<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-nimbocdn-settings-store.php';

use NimboCDN\Settings_Store;

function nimbocdn_uninstall_cleanup() {
	foreach ( Settings_Store::OPTIONS as $nimbocdn_option ) {
		delete_option( $nimbocdn_option );
	}

	foreach ( Settings_Store::TRANSIENTS as $nimbocdn_transient ) {
		delete_transient( $nimbocdn_transient );
	}

	wp_clear_scheduled_hook( Settings_Store::CRON_HOOK );
	wp_clear_scheduled_hook( Settings_Store::FIRST_RUN_HOOK );
	wp_clear_scheduled_hook( 'nimbocdn_home_sync' );
	wp_clear_scheduled_hook( 'nimbocdn_challenge_sweep' );
}

nimbocdn_uninstall_cleanup();
