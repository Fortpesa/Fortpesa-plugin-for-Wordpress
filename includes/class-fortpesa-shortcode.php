<?php
defined( 'ABSPATH' ) || exit;

/**
 * [fortpesa_pay] shortcode.
 *
 *  Fixed price:   [fortpesa_pay amount="500" description="Consultation"]
 *  Open amount:   [fortpesa_pay description="Donation" min="10" max="70000" presets="100,500,1000"]
 *
 * Attributes: amount, min, max, presets, description, title, button,
 *             collect_name (yes/no), collect_email (yes/no), success_url
 */
class Fortpesa_Shortcode {

	public static function init() {
		add_shortcode( 'fortpesa_pay', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'fortpesa-pay', FORTPESA_WP_URL . 'assets/fortpesa.css', array(), FORTPESA_WP_VERSION );
		wp_register_script( 'fortpesa-pay', FORTPESA_WP_URL . 'assets/fortpesa.js', array(), FORTPESA_WP_VERSION, true );
	}

	/* ---- signed form config: stops visitors tampering with price/description ---- */

	private static function sign( $b64 ) {
		return hash_hmac( 'sha256', $b64, wp_salt( 'auth' ) );
	}

	public static function make_cfg( array $c ) {
		$b64 = rtrim( strtr( base64_encode( wp_json_encode( $c ) ), '+/', '-_' ), '=' );
		return $b64 . '.' . self::sign( $b64 );
	}

	public static function verify_cfg( $s ) {
		$parts = explode( '.', (string) $s );
		if ( 2 !== count( $parts ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			return false;
		}
		$c = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
		if ( ! is_array( $c ) ) {
			return false;
		}
		return wp_parse_args( $c, array( 'a' => 0, 'mn' => 1, 'mx' => 250000, 'd' => '', 'cn' => 0, 'ce' => 0, 'pg' => 0 ) );
	}

	public static function render( $atts ) {
		$a = shortcode_atts( array(
			'amount'        => '',
			'min'           => 1,
			'max'           => 250000,
			'presets'       => '',
			'description'   => 'Payment',
			'title'         => '',
			'button'        => 'Pay with M-Pesa',
			'collect_name'  => 'yes',
			'collect_email' => 'no',
			'success_url'   => '',
		), $atts, 'fortpesa_pay' );

		if ( ! Fortpesa_API::is_configured() ) {
			return current_user_can( 'manage_options' )
				? '<p class="fortpesa-notice">' . esc_html__( 'Fortpesa Payments: add your API key and Channel ID under Fortpesa > Settings.', 'fortpesa-payments' ) . '</p>'
				: '';
		}

		$yes   = function ( $v ) {
			return in_array( strtolower( (string) $v ), array( 'yes', 'true', '1', 'on' ), true );
		};
		$fixed = min( 250000, max( 0, (int) $a['amount'] ) );
		$min   = max( 1, (int) $a['min'] );
		$max   = min( 250000, max( $min, (int) $a['max'] ) );
		$cn    = $yes( $a['collect_name'] );
		$ce    = $yes( $a['collect_email'] );
		$desc  = mb_substr( sanitize_text_field( $a['description'] ), 0, 255 );

		$cfg = self::make_cfg( array(
			'a'  => $fixed,
			'mn' => $min,
			'mx' => $max,
			'd'  => $desc,
			'cn' => $cn ? 1 : 0,
			'ce' => $ce ? 1 : 0,
			'pg' => (int) get_the_ID(),
		) );

		$presets = array();
		if ( ! $fixed && '' !== $a['presets'] ) {
			foreach ( explode( ',', $a['presets'] ) as $v ) {
				$v = absint( $v );
				if ( $v >= $min && $v <= $max ) {
					$presets[] = $v;
				}
			}
		}

		static $n = 0;
		$n++;
		$uid = 'fortpesa-' . $n;

		wp_enqueue_style( 'fortpesa-pay' );
		wp_enqueue_script( 'fortpesa-pay' );

		ob_start();
		?>
		<div class="fortpesa-wrap">
			<?php if ( '' !== $a['title'] ) : ?>
				<h3 class="fortpesa-title"><?php echo esc_html( $a['title'] ); ?></h3>
			<?php endif; ?>
			<form class="fortpesa-form" novalidate
				data-pay="<?php echo esc_url( rest_url( 'fortpesa/v1/pay' ) ); ?>"
				data-status="<?php echo esc_url( rest_url( 'fortpesa/v1/status/' ) ); ?>"
				data-redirect="<?php echo esc_url( $a['success_url'] ); ?>">

				<?php if ( $fixed ) : ?>
					<p class="fortpesa-amount-line"><?php echo esc_html( $desc ); ?>: <strong>KES <?php echo esc_html( number_format_i18n( $fixed ) ); ?></strong></p>
				<?php endif; ?>

				<?php if ( $cn ) : ?>
					<p><label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Full name', 'fortpesa-payments' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="name" autocomplete="name" maxlength="120" required></p>
				<?php endif; ?>

				<?php if ( $ce ) : ?>
					<p><label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Email', 'fortpesa-payments' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" autocomplete="email" maxlength="120" required></p>
				<?php endif; ?>

				<p><label for="<?php echo esc_attr( $uid ); ?>-phone"><?php esc_html_e( 'M-Pesa phone number', 'fortpesa-payments' ); ?></label>
				<input type="tel" id="<?php echo esc_attr( $uid ); ?>-phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="0712 345 678" required></p>

				<?php if ( ! $fixed ) : ?>
					<p><label for="<?php echo esc_attr( $uid ); ?>-amount"><?php esc_html_e( 'Amount (KES)', 'fortpesa-payments' ); ?></label>
					<input type="number" id="<?php echo esc_attr( $uid ); ?>-amount" name="amount" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="1" inputmode="numeric" required></p>
					<?php if ( $presets ) : ?>
						<p class="fortpesa-presets">
							<?php foreach ( $presets as $v ) : ?>
								<button type="button" class="fortpesa-preset" data-amount="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( number_format_i18n( $v ) ); ?></button>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>

				<div class="fortpesa-hp" aria-hidden="true">
					<label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
				</div>
				<input type="hidden" name="cfg" value="<?php echo esc_attr( $cfg ); ?>">

				<p><button type="submit" class="fortpesa-submit"><?php echo esc_html( $a['button'] ); ?></button></p>
				<div class="fortpesa-status" role="status" aria-live="polite" hidden></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
