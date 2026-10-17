<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public REST endpoints:
 *   POST /wp-json/fortpesa/v1/pay             start an STK Push (called by the form)
 *   GET  /wp-json/fortpesa/v1/status/{token}  poll a payment (called by the form)
 *   POST /wp-json/fortpesa/v1/webhook         signed Fortpesa webhook
 */
class Fortpesa_REST {

	const NON_TERMINAL = array( 'initiating', 'pending', 'processing' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'fortpesa/v1', '/pay', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'pay' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'fortpesa/v1', '/status/(?P<token>[a-f0-9]{32})', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'status' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'fortpesa/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	private static function err( $code, $msg, $status ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/** Light abuse control: 5 attempts / IP and 3 attempts / phone per 10 minutes. */
	private static function rate_ok( $phone ) {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$sets = array(
			'fp_rl_ip_' . md5( $ip )    => 5,
			'fp_rl_ph_' . md5( $phone ) => 3,
		);
		foreach ( $sets as $key => $limit ) {
			$n = (int) get_transient( $key );
			if ( $n >= $limit ) {
				return false;
			}
		}
		foreach ( $sets as $key => $limit ) {
			set_transient( $key, (int) get_transient( $key ) + 1, 10 * MINUTE_IN_SECONDS );
		}
		return true;
	}

	public static function pay( WP_REST_Request $req ) {
		$p = $req->get_json_params();
		if ( ! is_array( $p ) ) {
			$p = $req->get_params();
		}

		// Honeypot.
		if ( ! empty( $p['website'] ) ) {
			return self::err( 'fortpesa_rejected', __( 'Request rejected.', 'fortpesa-payments' ), 400 );
		}
		if ( ! Fortpesa_API::is_configured() ) {
			return self::err( 'fortpesa_unconfigured', __( 'Payments are not available right now.', 'fortpesa-payments' ), 503 );
		}

		$cfg = Fortpesa_Shortcode::verify_cfg( isset( $p['cfg'] ) ? (string) $p['cfg'] : '' );
		if ( ! $cfg ) {
			return self::err( 'fortpesa_bad_form', __( 'This form has expired. Please refresh the page and try again.', 'fortpesa-payments' ), 400 );
		}

		$phone = Fortpesa_API::normalize_phone( isset( $p['phone'] ) ? $p['phone'] : '' );
		if ( ! $phone ) {
			return self::err( 'fortpesa_bad_phone', __( 'Enter a valid Safaricom number, e.g. 0712 345 678.', 'fortpesa-payments' ), 400 );
		}

		// Amount: fixed amounts come from the signed config, never from the browser.
		if ( (int) $cfg['a'] > 0 ) {
			$amount = (int) $cfg['a'];
		} else {
			$raw    = isset( $p['amount'] ) ? $p['amount'] : '';
			$amount = ( is_numeric( $raw ) && (float) $raw === floor( (float) $raw ) ) ? (int) $raw : 0;
			if ( $amount < (int) $cfg['mn'] || $amount > (int) $cfg['mx'] ) {
				/* translators: 1: min, 2: max */
				return self::err( 'fortpesa_bad_amount', sprintf( __( 'Enter a whole amount between KES %1$s and KES %2$s.', 'fortpesa-payments' ), number_format_i18n( $cfg['mn'] ), number_format_i18n( $cfg['mx'] ) ), 400 );
			}
		}

		$name  = isset( $p['name'] ) ? mb_substr( sanitize_text_field( $p['name'] ), 0, 120 ) : '';
		$email = isset( $p['email'] ) ? mb_substr( sanitize_email( $p['email'] ), 0, 120 ) : '';
		if ( ! empty( $cfg['cn'] ) && '' === $name ) {
			return self::err( 'fortpesa_no_name', __( 'Please enter your name.', 'fortpesa-payments' ), 400 );
		}
		if ( ! empty( $cfg['ce'] ) && ! is_email( $email ) ) {
			return self::err( 'fortpesa_no_email', __( 'Please enter a valid email address.', 'fortpesa-payments' ), 400 );
		}

		if ( ! self::rate_ok( $phone ) ) {
			return self::err( 'fortpesa_rate', __( 'Too many attempts. Please wait a few minutes and try again.', 'fortpesa-payments' ), 429 );
		}

		$id = Fortpesa_DB::insert( array(
			'token'       => bin2hex( random_bytes( 16 ) ),
			'amount'      => $amount,
			'phone'       => $phone,
			'name'        => $name,
			'email'       => $email,
			'description' => (string) $cfg['d'],
			'status'      => 'initiating',
			'page_id'     => (int) $cfg['pg'],
		) );
		if ( ! $id ) {
			return self::err( 'fortpesa_db', __( 'Could not start the payment. Please try again.', 'fortpesa-payments' ), 500 );
		}

		$reference = 'FP' . $id; // max 12 chars per Fortpesa.
		Fortpesa_DB::update( $id, array( 'reference' => $reference ) );

		// Same key on every attempt for this row => a retry can never double-prompt the customer.
		$idem = 'fpwp-' . substr( md5( home_url() ), 0, 12 ) . '-' . $id;

		$tx = Fortpesa_API::stk( $amount, $phone, $reference, $idem );
		if ( is_wp_error( $tx ) && 'fortpesa_network' === $tx->get_error_code() ) {
			$tx = Fortpesa_API::stk( $amount, $phone, $reference, $idem ); // safe retry.
		}

		$row = Fortpesa_DB::get( $id );

		if ( is_wp_error( $tx ) ) {
			Fortpesa_DB::update( $id, array( 'status' => 'failed', 'failure_code' => mb_substr( $tx->get_error_code(), 0, 64 ) ) );
			do_action( 'fortpesa_api_error', $tx, $row );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[fortpesa] STK failed for ' . $reference . ': ' . $tx->get_error_code() . ' ' . $tx->get_error_message() );
			}
			$msg = ( 'INVALID_PHONE' === $tx->get_error_code() )
				? __( 'Enter a valid Safaricom number, e.g. 0712 345 678.', 'fortpesa-payments' )
				: __( 'We could not start the payment. Please try again shortly.', 'fortpesa-payments' );
			return self::err( 'fortpesa_stk_failed', $msg, 502 );
		}

		$status = isset( $tx['status'] ) ? sanitize_key( $tx['status'] ) : 'pending';
		Fortpesa_DB::update( $id, array(
			'uuid'        => isset( $tx['uuid'] ) ? sanitize_text_field( $tx['uuid'] ) : '',
			'status'      => in_array( $status, array_merge( self::NON_TERMINAL, Fortpesa_API::TERMINAL ), true ) ? $status : 'pending',
			'provider_tx' => isset( $tx['provider_transaction_id'] ) ? sanitize_text_field( $tx['provider_transaction_id'] ) : '',
		) );

		return new WP_REST_Response( array(
			'token'   => $row->token,
			'status'  => $status,
			'message' => __( 'Check your phone and enter your M-Pesa PIN to complete the payment.', 'fortpesa-payments' ),
		), 201 );
	}

	public static function status( WP_REST_Request $req ) {
		$row = Fortpesa_DB::get_by_token( $req['token'] );
		if ( ! $row ) {
			return self::err( 'fortpesa_not_found', __( 'Payment not found.', 'fortpesa-payments' ), 404 );
		}

		// Fortpesa live-confirms with M-Pesa at most every 10s per transaction, so poll no faster than that.
		if ( in_array( $row->status, self::NON_TERMINAL, true ) && '' !== $row->uuid && ( time() - (int) $row->last_checked ) >= 8 ) {
			Fortpesa_DB::update( $row->id, array( 'last_checked' => time() ) );
			$tx = Fortpesa_API::get_transaction( $row->uuid );
			if ( ! is_wp_error( $tx ) ) {
				self::apply_remote( $row, $tx );
				$row = Fortpesa_DB::get( $row->id );
			}
		}

		return array(
			'status'   => $row->status,
			'terminal' => in_array( $row->status, Fortpesa_API::TERMINAL, true ),
			'message'  => self::message_for( $row->status ),
		);
	}

	public static function message_for( $status ) {
		switch ( $status ) {
			case 'success':
				return (string) Fortpesa_API::setting( 'success_message' );
			case 'cancelled':
				return __( 'The payment was cancelled. You have not been charged.', 'fortpesa-payments' );
			case 'expired':
				return __( 'The payment prompt timed out. You have not been charged. Please try again.', 'fortpesa-payments' );
			case 'reversed':
				return __( 'This payment was reversed.', 'fortpesa-payments' );
			case 'failed':
				return __( 'The payment was not completed (declined, wrong PIN or insufficient funds). Please try again.', 'fortpesa-payments' );
			default:
				return __( 'Waiting for your M-Pesa confirmation...', 'fortpesa-payments' );
		}
	}

	/**
	 * Applies a transaction read from the Fortpesa API to a local row.
	 * Fires hooks exactly once per transition, even if the webhook and a poll race.
	 */
	public static function apply_remote( $row, array $tx ) {
		$known = array_merge( self::NON_TERMINAL, Fortpesa_API::TERMINAL );
		$new   = isset( $tx['status'] ) ? sanitize_key( $tx['status'] ) : '';
		$old   = $row->status;

		if ( ! in_array( $new, $known, true ) || $new === $old ) {
			return false;
		}
		$old_terminal = in_array( $old, Fortpesa_API::TERMINAL, true );
		$new_terminal = in_array( $new, Fortpesa_API::TERMINAL, true );

		if ( 'reversed' === $old ) {
			return false;
		}
		if ( 'success' === $old && 'reversed' !== $new ) {
			return false;
		}
		if ( $old_terminal && ! $new_terminal ) {
			return false;
		}
		if ( ! $old_terminal && ! $new_terminal && array_search( $new, self::NON_TERMINAL, true ) < array_search( $old, self::NON_TERMINAL, true ) ) {
			return false; // never move backwards.
		}
		if ( 'success' === $new && isset( $tx['amount'] ) && (int) $tx['amount'] !== (int) $row->amount ) {
			do_action( 'fortpesa_amount_mismatch', $row, $tx );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[fortpesa] amount mismatch on ' . $row->reference );
			}
			return false;
		}

		$data = array(
			'status'       => $new,
			'failure_code' => isset( $tx['failure_code'] ) && null !== $tx['failure_code'] ? mb_substr( sanitize_text_field( (string) $tx['failure_code'] ), 0, 64 ) : '',
		);
		if ( ! empty( $tx['uuid'] ) && '' === $row->uuid ) {
			$data['uuid'] = sanitize_text_field( $tx['uuid'] );
		}
		if ( ! empty( $tx['provider_transaction_id'] ) ) {
			$data['provider_tx'] = mb_substr( sanitize_text_field( $tx['provider_transaction_id'] ), 0, 80 );
		}

		if ( ! Fortpesa_DB::transition( $row->id, $old, $data ) ) {
			return false; // someone else already applied it.
		}

		$fresh = Fortpesa_DB::get( $row->id );
		do_action( 'fortpesa_payment_status_changed', $fresh, $old );
		if ( 'success' === $new ) {
			do_action( 'fortpesa_payment_completed', $fresh );
		}
		return true;
	}

	public static function webhook( WP_REST_Request $req ) {
		$raw = $req->get_body(); // raw bytes, needed for the HMAC.
		$ok  = Fortpesa_API::verify_webhook( $raw, (string) $req->get_header( 'x_fortpesa_timestamp' ), (string) $req->get_header( 'x_fortpesa_signature' ) );

		if ( is_wp_error( $ok ) ) {
			return self::err( 'fortpesa_no_secret', 'Webhook secret not configured.', 503 );
		}
		if ( ! $ok ) {
			return self::err( 'fortpesa_bad_signature', 'Invalid signature.', 400 );
		}

		$evt  = json_decode( $raw, true );
		$data = ( is_array( $evt ) && isset( $evt['data'] ) && is_array( $evt['data'] ) ) ? $evt['data'] : array();
		$uuid = isset( $data['uuid'] ) ? sanitize_text_field( $data['uuid'] ) : '';
		if ( '' === $uuid ) {
			return array( 'received' => true, 'ignored' => true );
		}

		$row = Fortpesa_DB::get_by_uuid( $uuid );
		if ( ! $row && ! empty( $data['account_reference'] ) ) {
			// Covers the case where our STK call timed out but Fortpesa accepted it.
			$cand = Fortpesa_DB::get_by_reference( sanitize_text_field( $data['account_reference'] ) );
			if ( $cand && ( '' === $cand->uuid || $cand->uuid === $uuid ) ) {
				$row = $cand;
			}
		}
		if ( ! $row ) {
			// Not ours (e.g. another integration on the same Fortpesa account). Acknowledge so it isn't retried.
			return array( 'received' => true, 'ignored' => true );
		}

		// Trust but verify: the payload is a notification, not proof.
		$tx = Fortpesa_API::get_transaction( $uuid );
		if ( is_wp_error( $tx ) ) {
			return self::err( 'fortpesa_refetch_failed', 'Could not verify the transaction yet. Retry later.', 503 );
		}
		self::apply_remote( $row, $tx );

		return array( 'received' => true );
	}
}
