=== Fortpesa Payments ===
Contributors: fortunedevsolutions
Tags: mpesa, kenya, payments, stk push, fortpesa
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Accept M-Pesa STK Push payments on any WordPress site with the Fortpesa API. No WooCommerce needed.

== Description ==
Add [fortpesa_pay amount="500" description="Consultation"] to any page and visitors can pay by M-Pesa.
Includes a payments log, CSV export, signed webhook handling, email notifications and developer hooks.

== Installation ==
1. Upload the fortpesa-payments folder to /wp-content/plugins/ and activate.
2. Go to Fortpesa > Settings, add your API key and Channel ID, then press "Test connection".
3. (Recommended) In the Fortpesa dashboard add the webhook URL shown on the settings page and paste the signing secret.
4. Add the shortcode to a page.

== Hooks ==
do_action( 'fortpesa_payment_completed', $payment )
do_action( 'fortpesa_payment_status_changed', $payment, $old_status )
do_action( 'fortpesa_api_error', $wp_error, $payment )
do_action( 'fortpesa_amount_mismatch', $payment, $transaction )

== Changelog ==
= 1.0.0 =
* Initial release.
