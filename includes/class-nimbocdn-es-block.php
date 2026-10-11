<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Es_Block {

	const OPTION = 'nimbocdn_es_block';

	const HOOK = 'nimbocdn_es_block_check';

	const SCHEDULE = 'nimbocdn_five_minutes';

	const INTERVAL = 300;

	const MAX_AGE = 3 * HOUR_IN_SECONDS;

	const TIMEOUT = 4;

	const TIMEZONES = array( 'Europe/Madrid', 'Atlantic/Canary', 'Africa/Ceuta' );
	const LOCALES   = array( 'es_ES', 'ca', 'eu', 'gl_ES' );

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval -- five minutes, and only on sites with a Spanish audience: the block starts at kick-off and every minute costs a hung store.
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		$wanted    = Settings_Store::is_configured() && self::applies();
		$scheduled = wp_next_scheduled( self::HOOK );
		if ( $wanted && ! $scheduled ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		} elseif ( ! $wanted && $scheduled ) {
			self::stop();
		}
	}

	public static function schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every five minutes (NimboCDN)', 'nimbocdn' ),
		);
		return $schedules;
	}

	public static function applies() {
		return (bool) apply_filters( 'nimbocdn_es_block_mode', self::detect() );
	}

	public static function detect() {
		$country = strtoupper( (string) get_option( 'woocommerce_default_country', '' ) );
		if ( 'ES' === $country || 0 === strpos( $country, 'ES:' ) ) {
			return true;
		}
		if ( in_array( (string) get_option( 'timezone_string', '' ), self::TIMEZONES, true ) ) {
			return true;
		}
		return in_array( (string) get_locale(), self::LOCALES, true );
	}

	public static function is_active() {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || empty( $state['blocked'] ) ) {
			return false;
		}
		$age = time() - ( isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0 );
		return $age >= 0 && $age <= self::MAX_AGE && self::applies();
	}

	public static function run() {
		if ( ! self::applies() ) {
			return;
		}
		$response = Api::get( '/v1/es-block', array(), self::TIMEOUT );
		if ( ! $response['ok'] || ! isset( $response['data']['blocked'] ) || ! is_bool( $response['data']['blocked'] ) ) {
			return;
		}
		$since = isset( $response['data']['since'] ) && is_string( $response['data']['since'] ) ? $response['data']['since'] : '';
		self::record( $response['data']['blocked'], $since );
	}

	public static function record( $blocked, $since = '' ) {
		$before = self::is_active();
		update_option(
			self::OPTION,
			array(
				'blocked'    => (bool) $blocked,
				'since'      => $blocked ? substr( sanitize_text_field( (string) $since ), 0, 40 ) : '',
				'checked_at' => time(),
			),
			true
		);
		$after = self::is_active();
		if ( $before !== $after ) {
			Page_Cache::purge();
			return true;
		}
		return false;
	}

	public static function stop() {
		$was = get_option( self::OPTION, null );
		wp_clear_scheduled_hook( self::HOOK );
		if ( null !== $was ) {
			delete_option( self::OPTION );
			if ( is_array( $was ) && ! empty( $was['blocked'] ) ) {
				Page_Cache::purge();
			}
		}
	}
}
