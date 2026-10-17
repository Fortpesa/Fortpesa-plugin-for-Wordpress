<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings access + thin client for the Fortpesa REST API.
 * Docs: https://fortpesa.co.ke/index
 */
class Fortpesa_API {

	const BASE     = 'https://fortpesa.co.ke/api/v1';
	const TERMINAL = array( 'success', 'failed', 'cancelled', 'expired', 'reversed' );

	public static function defaults() {
		return array(
			'api_key'         => '',
			'channel_id'      => '',
			'webhook_secret'  => '',
			'notify_enabled'  => 1,
			'notify_email'    => get_option( 'admin_email' ),
			'success_message' => 'Payment received. Thank you!',
		);
	}

	/** Constants in wp-config.php win over saved options (keeps secrets out of the DB). */
	public static function setting( $key ) {
		$consts = array(
			'api_key'        => 'FORTPESA_API_KEY',
			'channel_id'     => 'FORTPESA_CHANNEL_ID',
			'webhook_secret' => 'FORTPESA_WEBHOOK_SECRET',
		);
		if ( isset( $consts[ $key ] ) && defined( $consts[ $key ] ) && '' !== constant( $consts[ $key ] ) ) {
			return constant( $consts[ $key ] );
		}
		$opts = wp_parse_args( get_option( 'fortpesa_settings', array() ), self::defaults() );
		return isset( $opts[ $key ] ) ? $opts[ $key ] : '';
	}

	public static function is_configured() {
		return '' !== self::setting( 'api_key' ) && (int) self::setting( 'channel_id' ) > 0;
	}

	/** Returns 2547XXXXXXXX / 2541XXXXXXXX or false. */
	public static function normalize_phone( $raw ) {
		$p = preg_replace( '/[\s\-\(\)]/', '', (string) $raw );
		$p = ltrim( $p, '+' );
		if ( preg_match( '/^0([17]\d{8})$/', $p, $m ) ) {
			return '254' . $m[1];
		}
		if ( preg_match( '/^254([17]\d{8})$/', $p, $m ) ) {
			return '254' . $m[1];
		}
		return false;
	}

	private static function request( $method, $path, $body = null, $headers = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . self::setting( 'api_key' ),
					'Accept'        => 'application/json',
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$res = wp_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'fortpesa_network', $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code >= 200 && $code < 300 && is_array( $json ) ) {
			return $json;
		}

		$err_code = isset( $json['error']['code'] ) ? (string) $json['error']['code'] : 'HTTP_' . $code;
		$msg      = isset( $json['error']['message'] ) ? (string) $json['error']['message'] : 'Unexpected response from Fortpesa (HTTP ' . $code . ').';
		if ( 422 === $code && isset( $json['errors'] ) && is_array( $json['errors'] ) ) {
			$flat = array();
			array_walk_recursive( $json['errors'], function ( $v ) use ( &$flat ) {
				$flat[] = (string) $v;
			} );
			$msg = implode( ' ', $flat );
			if ( empty( $json['error']['code'] ) ) {
				$err_code = 'VALIDATION';
			}
		}
		return new WP_Error( $err_code, $msg, array( 'status' => $code ) );
	}

	/** POST /payments/stk. Always sends an Idempotency-Key so retries never double-prompt. */
	public static function stk( $amount, $phone, $reference, $idempotency_key ) {
		$res = self::request(
			'POST',
			'/payments/stk',
			array(
				'amount'            => (int) $amount,
				'phone'             => $phone,
				'account_reference' => $reference,
				'channel_id'        => (int) self::setting( 'channel_id' ),
			),
			array( 'Idempotency-Key' => $idempotency_key )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['data'] ) ? $res['data'] : new WP_Error( 'fortpesa_bad_response', 'Malformed response from Fortpesa.' );
	}

	/** GET /transactions/{uuid}: returns the transaction array (the `data` object). */
	public static function get_transaction( $uuid ) {
		$res = self::request( 'GET', '/transactions/' . rawurlencode( $uuid ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['data'] ) ? $res['data'] : new WP_Error( 'fortpesa_bad_response', 'Malformed response from Fortpesa.' );
	}

	/** GET /wallet/balance: whole KES. */
	public static function balance() {
		$res = self::request( 'GET', '/wallet/balance' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['data']['balance'] ) ? (int) $res['data']['balance'] : new WP_Error( 'fortpesa_bad_response', 'Malformed response from Fortpesa.' );
	}

	/** Verifies X-Fortpesa-Signature = sha256=HMAC(timestamp . "." . raw_body). */
	public static function verify_webhook( $raw_body, $timestamp, $signature ) {
		$secret = self::setting( 'webhook_secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'fortpesa_no_secret', 'Webhook secret is not configured.' );
		}
		if ( ! ctype_digit( (string) $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		return hash_equals( $expected, (string) $signature );
	}
}
