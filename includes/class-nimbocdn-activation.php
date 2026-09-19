<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Activation {

	const FINISH_TIMEOUT = 30;

	public static function activate( $network_wide = false ) {
		if ( ! wp_next_scheduled( Settings_Store::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', Settings_Store::CRON_HOOK );
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $domain ) && '' !== $domain ) {
			Settings_Store::save_edge( array( 'domain' => $domain ) );
		}

		update_option( 'nimbocdn_activation_error', '', false );

		Probe::mark_pending();
		if ( ! wp_next_scheduled( Settings_Store::FIRST_RUN_HOOK ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, Settings_Store::FIRST_RUN_HOOK );
		}
		self::kick_first_run();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only read to tell a bulk activation apart; it changes nothing.
		if ( ! $network_wide && ! isset( $_REQUEST['activate-multi'] ) ) {
			set_transient( 'nimbocdn_activated', get_current_user_id(), MINUTE_IN_SECONDS );
		}
	}

	public static function register() {
		$creds = Settings_Store::credentials();
		$home  = wp_parse_url( home_url(), PHP_URL_HOST );
		$home  = is_string( $home ) ? $home : '';

		if ( '' !== $creds['tenant_id'] && '' !== $creds['license_key'] ) {
			$untouched = hash( 'sha256', $creds['domain'] ) === Settings_Store::edge()['domain_hash'];
			if ( $untouched && ( '' === $home || Signer::same_site( $home, $creds['domain'] ) ) ) {
				return true;
			}
			Settings_Store::save_edge(
				array(
					'tenant_id'   => '',
					'license_key' => '',
					'served'      => array(),
					'served_at'   => 0,
					'domain_hash' => '',
				)
			);
			update_option( 'nimbocdn_verified', false, false );
		}

		$domain = '' !== $home ? $home : $creds['domain'];
		if ( '' === $domain ) {
			return false;
		}
		Settings_Store::save_edge( array( 'domain' => $domain ) );

		$response = self::prove( $domain );
		if ( ! $response['ok'] ) {
			update_option( 'nimbocdn_activation_error', self::error_code( $response ), false );
			return false;
		}

		self::adopt( $response['data'] );
		return true;
	}

	public static function reregister() {
		$creds  = Settings_Store::credentials();
		$home   = wp_parse_url( home_url(), PHP_URL_HOST );
		$domain = is_string( $home ) && '' !== $home ? $home : $creds['domain'];
		if ( '' === $domain ) {
			return false;
		}

		$response = self::prove( $domain );
		if ( ! $response['ok'] ) {
			update_option( 'nimbocdn_activation_error', self::error_code( $response ), false );
			return false;
		}

		Settings_Store::save_edge(
			array(
				'domain'    => $domain,
				'served'    => array(),
				'served_at' => 0,
			)
		);
		self::adopt( $response['data'] );
		return true;
	}

	public static function verify() {
		if ( self::is_verified() ) {
			return true;
		}
		$creds = Settings_Store::credentials();
		if ( '' === $creds['tenant_id'] || '' === $creds['domain'] ) {
			return false;
		}
		$response = self::prove( $creds['domain'] );
		if ( ! $response['ok'] ) {
			update_option( 'nimbocdn_activation_error', self::error_code( $response ), false );
			return false;
		}
		self::adopt( $response['data'] );
		return self::is_verified();
	}

	public static function is_verified() {
		return (bool) get_option( 'nimbocdn_verified', false );
	}

	private static function prove( $domain ) {
		$start = Api::post( '/activate/start', array( 'domain' => $domain ), false );
		if ( ! $start['ok'] || empty( $start['data']['challenge'] ) || ! is_string( $start['data']['challenge'] ) ) {
			return array(
				'ok'     => false,
				'status' => (int) $start['status'],
				'data'   => array(),
			);
		}
		Challenge::remember( sanitize_text_field( $start['data']['challenge'] ) );

		$marketing = Settings_Store::marketing();

		$response = Api::post(
			'/activate/finish',
			array(
				'domain'            => $domain,
				'challenge'         => $start['data']['challenge'],
				'file_id'           => Challenge::file_id(),
				'file_path'         => Challenge::file_path(),
				'site_name'         => get_bloginfo( 'name' ),
				'wp_version'        => get_bloginfo( 'version' ),
				'plugin_version'    => VERSION,
				'email'             => self::contact_email(),
				'locale'            => get_locale(),
				'marketing_notice'  => $marketing['notice'],
				'marketing_opt_out' => $marketing['opt_out'],
			),
			false,
			self::FINISH_TIMEOUT
		);
		Challenge::forget();
		return $response;
	}

	private static function adopt( $data ) {
		$was_verified = self::is_verified();
		$old_key      = Settings_Store::credentials()['license_key'];
		$patch        = array();
		foreach ( array( 'tenant_id', 'license_key', 'domain' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$patch[ $key ] = sanitize_text_field( $data[ $key ] );
			}
		}
		if ( isset( $patch['domain'] ) ) {
			$patch['domain_hash'] = hash( 'sha256', $patch['domain'] );
		}
		Settings_Store::save_edge( $patch );
		update_option( 'nimbocdn_verified', ! empty( $data['verified'] ), false );
		$email = self::contact_email();
		if ( '' !== $email ) {
			update_option( 'nimbocdn_email', $email, false );
		}
		update_option( 'nimbocdn_activation_error', '', false );
		$new_key = isset( $patch['license_key'] ) ? $patch['license_key'] : $old_key;
		if ( ( ! $was_verified && ! empty( $data['verified'] ) ) || ( '' !== $old_key && $new_key !== $old_key ) ) {
			Page_Cache::purge();
		}
	}

	private static function error_code( $response ) {
		if ( 429 === (int) $response['status'] ) {
			return 'rate-limited';
		}
		if ( 403 === (int) $response['status'] ) {
			return 'not-verified';
		}
		return 'unreachable';
	}

	private static function kick_first_run() {
		$token = wp_generate_password( 32, false );
		set_transient( 'nimbocdn_first_run_token', $token, 5 * MINUTE_IN_SECONDS );
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, the one WP-Cron itself applies to loopbacks.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'nimbocdn_first_run',
					'token'  => $token,
				),
			)
		);
	}

	private static function contact_email() {
		$chosen = get_option( 'nimbocdn_email', '' );
		if ( is_string( $chosen ) && is_email( $chosen ) ) {
			return $chosen;
		}
		$admin = get_option( 'admin_email', '' );
		return is_string( $admin ) && is_email( $admin ) ? $admin : '';
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( Settings_Store::CRON_HOOK );
		wp_clear_scheduled_hook( Settings_Store::FIRST_RUN_HOOK );
		wp_clear_scheduled_hook( Home::HOOK );
		wp_clear_scheduled_hook( Challenge::SWEEP_HOOK );
		Challenge::sweep();
	}
}
