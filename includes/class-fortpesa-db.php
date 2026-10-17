<?php
defined( 'ABSPATH' ) || exit;

class Fortpesa_DB {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'fortpesa_payments';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = self::table();

		dbDelta( "CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token char(32) NOT NULL,
  uuid varchar(64) NOT NULL DEFAULT '',
  reference varchar(12) NOT NULL DEFAULT '',
  amount int(11) unsigned NOT NULL DEFAULT 0,
  phone varchar(20) NOT NULL DEFAULT '',
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(120) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'initiating',
  failure_code varchar(64) NOT NULL DEFAULT '',
  provider_tx varchar(80) NOT NULL DEFAULT '',
  page_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_checked int(11) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY uuid (uuid),
  KEY reference (reference),
  KEY status (status)
) $charset;" );

		update_option( 'fortpesa_db_version', FORTPESA_WP_DB_VERSION );
	}

	public static function insert( array $data ) {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array_merge( $data, array( 'created_at' => $now, 'updated_at' => $now ) );
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		return $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/** Compare-and-set on status. True only for the single caller that wins the race. */
	public static function transition( $id, $from_status, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$n = $wpdb->update( self::table(), $data, array( 'id' => (int) $id, 'status' => $from_status ) );
		return 1 === $n;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ) );
	}

	public static function get_by_uuid( $uuid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE uuid = %s', $uuid ) );
	}

	public static function get_by_reference( $ref ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE reference = %s ORDER BY id DESC LIMIT 1', $ref ) );
	}

	public static function query( $status = '', $limit = 20, $offset = 0 ) {
		global $wpdb;
		$t = self::table();
		if ( '' !== $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, $offset ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
	}

	public static function count( $status = '' ) {
		global $wpdb;
		$t = self::table();
		if ( '' !== $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = %s", $status ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
	}
}
