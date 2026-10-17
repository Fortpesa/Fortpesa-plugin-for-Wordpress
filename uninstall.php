<?php
// Settings are removed on uninstall. Payment records (financial data) are intentionally kept.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
delete_option( 'fortpesa_settings' );
