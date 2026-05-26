<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'rb_message' );
delete_option( 'rb_redirect_url' );
delete_option( 'rb_blocked_log' );
