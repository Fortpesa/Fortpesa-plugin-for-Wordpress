<?php
defined( 'ABSPATH' ) || exit;

class Fortpesa_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_fortpesa_recheck', array( __CLASS__, 'recheck' ) );
		add_action( 'admin_post_fortpesa_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_fortpesa_test', array( __CLASS__, 'test_connection' ) );
		add_action( 'fortpesa_payment_completed', array( __CLASS__, 'notify_admin' ) );
	}

	public static function menu() {
		add_menu_page( 'Fortpesa', 'Fortpesa', 'manage_options', 'fortpesa-payments', array( __CLASS__, 'page_payments' ), 'dashicons-money-alt', 58 );
		add_submenu_page( 'fortpesa-payments', 'Payments', 'Payments', 'manage_options', 'fortpesa-payments', array( __CLASS__, 'page_payments' ) );
		add_submenu_page( 'fortpesa-payments', 'Settings', 'Settings', 'manage_options', 'fortpesa-settings', array( __CLASS__, 'page_settings' ) );
	}

	/* ------------------------------------------------------------ settings */

	public static function register_settings() {
		register_setting( 'fortpesa_settings_group', 'fortpesa_settings', array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => array(),
		) );
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$old = get_option( 'fortpesa_settings', array() );
		$out = array();

		foreach ( array( 'api_key', 'webhook_secret' ) as $secret ) {
			$v           = isset( $in[ $secret ] ) ? trim( sanitize_text_field( $in[ $secret ] ) ) : '';
			$out[ $secret ] = '' !== $v ? $v : ( isset( $old[ $secret ] ) ? $old[ $secret ] : '' ); // blank = keep.
		}
		$out['channel_id']      = isset( $in['channel_id'] ) ? absint( $in['channel_id'] ) : 0;
		$out['notify_enabled']  = empty( $in['notify_enabled'] ) ? 0 : 1;
		$out['notify_email']    = isset( $in['notify_email'] ) ? sanitize_email( $in['notify_email'] ) : '';
		$out['success_message'] = isset( $in['success_message'] ) ? sanitize_text_field( $in['success_message'] ) : '';
		return $out;
	}

	private static function secret_hint( $key ) {
		$v = Fortpesa_API::setting( $key );
		return '' === $v ? '' : '•••• saved (ends ' . substr( $v, -4 ) . ')';
	}

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = wp_parse_args( get_option( 'fortpesa_settings', array() ), Fortpesa_API::defaults() );
		?>
		<div class="wrap">
			<h1>Fortpesa Settings</h1>

			<?php
			// phpcs:disable WordPress.Security.NonceVerification
			if ( isset( $_GET['fp_test'] ) ) {
				if ( 'ok' === $_GET['fp_test'] ) {
					echo '<div class="notice notice-success"><p>' . esc_html( sprintf( 'Connected. Service wallet balance: KES %s.', number_format_i18n( (int) $_GET['bal'] ) ) ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html( 'Connection failed: ' . sanitize_text_field( wp_unslash( isset( $_GET['msg'] ) ? $_GET['msg'] : 'unknown error' ) ) ) . '</p></div>';
				}
			}
			// phpcs:enable
			?>

			<form method="post" action="options.php">
				<?php settings_fields( 'fortpesa_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="fp_api_key">API key</label></th>
						<td>
							<input type="password" id="fp_api_key" name="fortpesa_settings[api_key]" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( self::secret_hint( 'api_key' ) ?: 'pb_live_...' ); ?>">
							<p class="description">Fortpesa dashboard &rarr; Settings &rarr; API keys. Leave blank to keep the saved key. Or define <code>FORTPESA_API_KEY</code> in wp-config.php.</p>
						</td>
					</tr>
					<tr>
						<th><label for="fp_channel">Channel ID</label></th>
						<td>
							<input type="number" id="fp_channel" name="fortpesa_settings[channel_id]" class="small-text" min="1" value="<?php echo esc_attr( $o['channel_id'] ); ?>">
							<p class="description">The <em>Channel ID</em> column on the Channels page of your Fortpesa dashboard (the channel must be approved).</p>
						</td>
					</tr>
					<tr>
						<th><label for="fp_secret">Webhook signing secret</label></th>
						<td>
							<input type="password" id="fp_secret" name="fortpesa_settings[webhook_secret]" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( self::secret_hint( 'webhook_secret' ) ?: 'whsec_...' ); ?>">
							<p class="description">Shown once when you create the webhook in Fortpesa &rarr; Settings &rarr; Webhooks. Or define <code>FORTPESA_WEBHOOK_SECRET</code>.</p>
						</td>
					</tr>
					<tr>
						<th>Webhook URL</th>
						<td>
							<code><?php echo esc_html( rest_url( 'fortpesa/v1/webhook' ) ); ?></code>
							<p class="description">Paste this in Fortpesa &rarr; Settings &rarr; Webhooks (must be HTTPS and publicly reachable). Payments are also confirmed by polling, so the form works without it, but the webhook catches customers who close the page early.</p>
						</td>
					</tr>
					<tr>
						<th><label for="fp_msg">Success message</label></th>
						<td><input type="text" id="fp_msg" name="fortpesa_settings[success_message]" class="large-text" value="<?php echo esc_attr( $o['success_message'] ); ?>"></td>
					</tr>
					<tr>
						<th>Email me on payment</th>
						<td>
							<label><input type="checkbox" name="fortpesa_settings[notify_enabled]" value="1" <?php checked( $o['notify_enabled'] ); ?>> Send an email for every successful payment</label><br>
							<input type="email" name="fortpesa_settings[notify_email]" class="regular-text" value="<?php echo esc_attr( $o['notify_email'] ); ?>">
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fortpesa_test' ), 'fortpesa_test' ) ); ?>">Test connection</a>
				<span class="description">Reads your service wallet balance using the saved API key.</span>
			</p>

			<hr>
			<h2>Usage</h2>
			<p>Fixed price: <code>[fortpesa_pay amount="500" description="Consultation"]</code></p>
			<p>Open amount / donations: <code>[fortpesa_pay description="Donation" min="10" presets="100,500,1000" collect_email="yes"]</code></p>
			<p>Other attributes: <code>title</code>, <code>button</code>, <code>max</code>, <code>collect_name</code>, <code>collect_email</code>, <code>success_url</code>.</p>
			<p>Developers: hook <code>fortpesa_payment_completed</code> (receives the payment row object) to grant access, send receipts, etc.</p>
		</div>
		<?php
	}

	public static function test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'fortpesa_test' );
		$bal  = Fortpesa_API::balance();
		$args = is_wp_error( $bal )
			? array( 'fp_test' => 'err', 'msg' => rawurlencode( $bal->get_error_code() . ': ' . $bal->get_error_message() ) )
			: array( 'fp_test' => 'ok', 'bal' => (int) $bal );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=fortpesa-settings' ) ) );
		exit;
	}

	/* ------------------------------------------------------------ payments list */

	public static function page_payments() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		if ( '' !== $status && ! in_array( $status, array_merge( Fortpesa_REST::NON_TERMINAL, Fortpesa_API::TERMINAL ), true ) ) {
			$status = '';
		}
		$per   = 20;
		$rows  = Fortpesa_DB::query( $status, $per, ( $paged - 1 ) * $per );
		$total = Fortpesa_DB::count( $status );
		$base  = admin_url( 'admin.php?page=fortpesa-payments' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Fortpesa Payments</h1>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fortpesa_export' ), 'fortpesa_export' ) ); ?>">Export CSV</a>
			<hr class="wp-header-end">

			<ul class="subsubsub">
				<?php
				$links = array( '' => 'All' );
				foreach ( array( 'success', 'pending', 'failed', 'cancelled', 'expired' ) as $s ) {
					$links[ $s ] = ucfirst( $s );
				}
				$i = 0;
				foreach ( $links as $key => $label ) {
					printf(
						'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a>%s</li>',
						esc_url( '' === $key ? $base : add_query_arg( 'status', $key, $base ) ),
						$key === $status ? 'current' : '',
						esc_html( $label ),
						(int) Fortpesa_DB::count( $key ),
						++$i < count( $links ) ? ' |' : ''
					);
				}
				?>
			</ul>

			<table class="wp-list-table widefat fixed striped" style="margin-top:12px">
				<thead><tr>
					<th style="width:50px">ID</th><th>Date (UTC)</th><th>Customer</th><th>Amount</th>
					<th>Description</th><th>Reference</th><th>M-Pesa ref</th><th>Status</th><th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="9">No payments yet.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->id ); ?></td>
						<td><?php echo esc_html( $r->created_at ); ?></td>
						<td><?php echo esc_html( trim( $r->name . ' ' . $r->phone ) ); ?><?php echo $r->email ? '<br><small>' . esc_html( $r->email ) . '</small>' : ''; ?></td>
						<td>KES <?php echo esc_html( number_format_i18n( $r->amount ) ); ?></td>
						<td><?php echo esc_html( $r->description ); ?></td>
						<td><code><?php echo esc_html( $r->reference ); ?></code></td>
						<td><?php echo esc_html( $r->provider_tx ); ?></td>
						<td><strong><?php echo esc_html( $r->status ); ?></strong><?php echo $r->failure_code ? '<br><small>' . esc_html( $r->failure_code ) . '</small>' : ''; ?></td>
						<td>
							<?php if ( '' !== $r->uuid ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fortpesa_recheck&id=' . (int) $r->id ), 'fortpesa_recheck_' . (int) $r->id ) ); ?>">Re-check</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%', $base . ( $status ? '&status=' . $status : '' ) ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				) ) ) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	public static function recheck() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'fortpesa_recheck_' . $id );
		$row = Fortpesa_DB::get( $id );
		if ( $row && '' !== $row->uuid ) {
			$tx = Fortpesa_API::get_transaction( $row->uuid );
			if ( ! is_wp_error( $tx ) ) {
				Fortpesa_REST::apply_remote( $row, $tx );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=fortpesa-payments' ) );
		exit;
	}

	private static function csv_cell( $v ) {
		$v = (string) $v;
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v; // block spreadsheet formula injection.
	}

	public static function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'fortpesa_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=fortpesa-payments-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'created_utc', 'name', 'phone', 'email', 'amount_kes', 'description', 'reference', 'mpesa_ref', 'status', 'failure_code', 'uuid' ) );
		foreach ( Fortpesa_DB::query( '', 5000, 0 ) as $r ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), array(
				$r->id, $r->created_at, $r->name, $r->phone, $r->email, $r->amount, $r->description,
				$r->reference, $r->provider_tx, $r->status, $r->failure_code, $r->uuid,
			) ) );
		}
		fclose( $out );
		exit;
	}

	/* ------------------------------------------------------------ notifications */

	public static function notify_admin( $row ) {
		if ( ! Fortpesa_API::setting( 'notify_enabled' ) ) {
			return;
		}
		$to = Fortpesa_API::setting( 'notify_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$subject = sprintf( '[%s] M-Pesa payment received: KES %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), number_format_i18n( $row->amount ) );
		$body    = sprintf(
			"Amount: KES %s\nFor: %s\nCustomer: %s\nPhone: %s\nEmail: %s\nM-Pesa ref: %s\nReference: %s\n",
			number_format_i18n( $row->amount ), $row->description, $row->name, $row->phone, $row->email, $row->provider_tx, $row->reference
		);
		wp_mail( $to, $subject, $body );
	}
}
