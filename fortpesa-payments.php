<?php
/**
 * Plugin Name:       Fortpesa Payments
 * Description:       Accept M-Pesa STK Push payments on any WordPress site using the Fortpesa API. No WooCommerce required: drop a shortcode on any page.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Fortune Dev Solutions
 * License:           GPL-2.0-or-later
 * Text Domain:       fortpesa-payments
 */

defined( 'ABSPATH' ) || exit;

define( 'FORTPESA_WP_VERSION', '1.0.0' );
define( 'FORTPESA_WP_DB_VERSION', '1' );
define( 'FORTPESA_WP_FILE', __FILE__ );
define( 'FORTPESA_WP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORTPESA_WP_URL', plugin_dir_url( __FILE__ ) );

require_once FORTPESA_WP_DIR . 'includes/class-fortpesa-api.php';
require_once FORTPESA_WP_DIR . 'includes/class-fortpesa-db.php';
require_once FORTPESA_WP_DIR . 'includes/class-fortpesa-rest.php';
require_once FORTPESA_WP_DIR . 'includes/class-fortpesa-shortcode.php';
require_once FORTPESA_WP_DIR . 'includes/class-fortpesa-admin.php';

register_activation_hook( __FILE__, array( 'Fortpesa_DB', 'install' ) );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'fortpesa_db_version' ) !== FORTPESA_WP_DB_VERSION ) {
		Fortpesa_DB::install();
	}
	Fortpesa_REST::init();
	Fortpesa_Shortcode::init();
	if ( is_admin() ) {
		Fortpesa_Admin::init();
	}
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=fortpesa-settings' ) ) . '">' . esc_html__( 'Settings', 'fortpesa-payments' ) . '</a>' );
	return $links;
} );
