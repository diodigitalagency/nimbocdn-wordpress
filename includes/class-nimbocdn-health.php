<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Health {

	const FAILURE_THRESHOLD = 3;

	public static function init() {
		add_action( Settings_Store::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( Settings_Store::FIRST_RUN_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'wp_ajax_nopriv_nimbocdn_first_run', array( __CLASS__, 'first_run' ) );
		add_action( 'wp_ajax_nimbocdn_first_run', array( __CLASS__, 'first_run' ) );
		if ( ! wp_next_scheduled( Settings_Store::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', Settings_Store::CRON_HOOK );
		}
	}

	public static function refresh_account() {
		$response = Api::account_response();
		if ( 401 === (int) $response['status'] ) {
			return 'unauthorized';
		}
		if ( ! $response['ok'] || empty( $response['data'] ) ) {
			return 'unavailable';
		}
		$account = $response['data'];
		update_option(
			'nimbocdn_account',
			array(
				'plan'              => isset( $account['plan'] ) ? sanitize_key( $account['plan'] ) : 'free',
				'past_due'          => isset( $account['past_due']['days_left'] ) ? max( 0, (int) $account['past_due']['days_left'] ) : 0,
				'in_dunning'        => isset( $account['past_due'] ) && null !== $account['past_due'],
				'marketing_opt_out' => ! empty( $account['marketing_opt_out'] ),
				'cancelling'        => isset( $account['cancelling']['ends_at'] ) ? sanitize_text_field( (string) $account['cancelling']['ends_at'] ) : '',
				'interval'          => isset( $account['interval'] ) && 'year' === $account['interval'] ? 'year' : 'month',
				'renews_at'         => isset( $account['renews_at'] ) ? sanitize_text_field( (string) $account['renews_at'] ) : '',
				'switching'         => self::sanitize_switching( isset( $account['switching'] ) ? $account['switching'] : null ),
				'prices'            => self::sanitize_prices( isset( $account['prices'] ) ? $account['prices'] : array() ),
				'grant'             => self::sanitize_grant( isset( $account['grant'] ) ? $account['grant'] : null ),
				'images'            => self::sanitize_images( isset( $account['images'] ) ? $account['images'] : array() ),
				'traffic'           => self::sanitize_traffic( isset( $account['traffic'] ) ? $account['traffic'] : array() ),
				'fetched_at'        => time(),
			),
			false
		);
		if ( ! empty( $account['email'] ) && is_email( $account['email'] ) ) {
			update_option( 'nimbocdn_email', sanitize_email( $account['email'] ), false );
		}
		if ( isset( $account['home'] ) ) {
			Home::apply( $account['home'] );
		}
		return 'ok';
	}

	public static function first_run() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- a one-time token, compared in constant time, replaces the nonce: there is no logged-in user on this request by design.
		$given = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$token = get_transient( 'nimbocdn_first_run_token' );
		if ( '' === $given || ! is_string( $token ) || ! hash_equals( $token, $given ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		delete_transient( 'nimbocdn_first_run_token' );
		wp_clear_scheduled_hook( Settings_Store::FIRST_RUN_HOOK );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		self::run();
		wp_die();
	}

	public static function is_service_up() {
		$failures = (int) get_option( 'nimbocdn_health_failures', 0 );
		return $failures < self::FAILURE_THRESHOLD;
	}

	public static function run( $wait = true, $probe_budget = null ) {
		Probe::note_stage( 'register' );
		if ( ! Activation::register() ) {
			Probe::run( $wait, $probe_budget );
			return;
		}
		Activation::verify();
		$creds = Settings_Store::credentials();

		$response = Api::get( '/health', array( 'tenant' => $creds['tenant_id'] ) );

		if ( ! $response['ok'] || empty( $response['data']['ok'] ) ) {
			self::record_failure();
			Probe::run( $wait, $probe_budget );
			return;
		}

		$body = $response['data'];

		if ( ! empty( $body['cdn_hostname'] ) && is_string( $body['cdn_hostname'] ) ) {
			$host = sanitize_text_field( $body['cdn_hostname'] );
			if ( preg_match( '/^[a-z0-9.-]+$/i', $host ) ) {
				Settings_Store::save_edge( array( 'cdn_host' => $host ) );
			}
		}

		update_option( 'nimbocdn_health_failures', 0, false );

		Probe::run( $wait, $probe_budget );

		$verdict = self::refresh_account();

		if ( 'unauthorized' === $verdict && Activation::reregister() ) {
			Probe::mark_pending();
		}

		self::sync_marketing();
	}

	private static function sync_marketing() {
		$marketing = Settings_Store::marketing();
		if ( ! $marketing['notice'] || $marketing['synced'] ) {
			return;
		}
		$res = Api::report_marketing_notice();
		if ( $res['ok'] ) {
			Settings_Store::save_marketing(
				array(
					'synced'  => true,
					'opt_out' => $res['opt_out'],
				)
			);
			Settings_Store::save_account( array( 'marketing_opt_out' => $res['opt_out'] ) );
		}
	}

	private static function record_failure() {
		$failures = (int) get_option( 'nimbocdn_health_failures', 0 );
		update_option( 'nimbocdn_health_failures', $failures + 1, false );
	}

	private static function sanitize_grant( $grant ) {
		if ( ! is_array( $grant ) ) {
			return array(
				'active'     => false,
				'by'         => '',
				'expires_at' => '',
				'days_left'  => 0,
			);
		}
		return array(
			'active'     => true,
			'by'         => isset( $grant['by'] ) ? substr( sanitize_text_field( (string) $grant['by'] ), 0, 60 ) : '',
			'expires_at' => isset( $grant['expires_at'] ) ? sanitize_text_field( (string) $grant['expires_at'] ) : '',
			'days_left'  => isset( $grant['days_left'] ) ? max( 0, (int) $grant['days_left'] ) : 0,
		);
	}

	private static function sanitize_switching( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['to'] ) ) {
			return array();
		}
		return array(
			'to' => 'month' === $raw['to'] ? 'month' : 'year',
			'at' => isset( $raw['at'] ) ? sanitize_text_field( (string) $raw['at'] ) : '',
		);
	}

	private static function sanitize_prices( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'usd', 'brl' ) as $currency ) {
			if ( ! isset( $raw[ $currency ] ) || ! is_array( $raw[ $currency ] ) ) {
				continue;
			}
			$row = array();
			foreach ( array( 'month', 'year', 'save', 'monthly_equivalent' ) as $key ) {
				if ( isset( $raw[ $currency ][ $key ] ) && is_numeric( $raw[ $currency ][ $key ] ) ) {
					$row[ $key ] = (int) $raw[ $currency ][ $key ];
				}
			}
			if ( ! empty( $row ) ) {
				$out[ $currency ] = $row;
			}
		}
		return $out;
	}

	private static function sanitize_images( $raw ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$fields = array( 'count', 'weighed', 'without_bytes', 'delivered_bytes', 'avif_pairs', 'avif_bytes', 'webp_bytes' );
		return self::ints( $raw, $fields );
	}

	private static function sanitize_traffic( $raw ) {
		$raw    = is_array( $raw ) ? $raw : array();
		$series = isset( $raw['series'] ) && is_array( $raw['series'] ) ? array_slice( array_values( $raw['series'] ), -30 ) : array();
		$last     = isset( $raw['last_30d'] ) && is_array( $raw['last_30d'] ) ? $raw['last_30d'] : array();
		$delivery = isset( $last['delivery_ms_p50'] ) && is_numeric( $last['delivery_ms_p50'] ) && $last['delivery_ms_p50'] >= 0
			? (float) $last['delivery_ms_p50']
			: -1;
		return array(
			'last_30d'   => array_merge(
				self::ints(
					$last,
					array( 'requests', 'cached', 'bytes_out', 'weighed', 'bytes_wp', 'bytes_out_weighed', 'avif', 'webp', 'jpeg', 'png', 'bytes_cached', 'sampled' )
				),
				array( 'delivery_ms_p50' => $delivery )
			),
			'lifetime'   => self::ints(
				isset( $raw['lifetime'] ) ? $raw['lifetime'] : array(),
				array( 'requests', 'weighed', 'bytes_wp', 'bytes_out_weighed' )
			),
			'series'     => array_map( 'intval', $series ),
			'updated_at' => isset( $raw['updated_at'] ) && is_string( $raw['updated_at'] ) ? sanitize_text_field( $raw['updated_at'] ) : '',
		);
	}

	private static function ints( $raw, array $fields ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $fields as $f ) {
			$out[ $f ] = isset( $raw[ $f ] ) ? max( 0, (int) $raw[ $f ] ) : 0;
		}
		return $out;
	}
}
