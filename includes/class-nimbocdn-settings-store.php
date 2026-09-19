<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Settings_Store {

	private static $edge_cache = null;

	const OPTIONS = array(
		'nimbocdn_edge',
		'nimbocdn_email',
		'nimbocdn_health_failures',
		'nimbocdn_activation_error',
		'nimbocdn_paused',
		'nimbocdn_probe',
		'nimbocdn_account',
		'nimbocdn_verified',
		'nimbocdn_probe_job',
		'nimbocdn_probe_lock',
		'nimbocdn_marketing',
		'nimbocdn_page_cache_purge',
	);

	const TRANSIENTS = array( 'nimbocdn_health', 'nimbocdn_activated', 'nimbocdn_first_run_token', 'nimbocdn_home_dirty', 'nimbocdn_challenge', 'nimbocdn_challenge_file', 'nimbocdn_check_cooldown' );

	const CRON_HOOK = 'nimbocdn_health_check';

	const FIRST_RUN_HOOK = 'nimbocdn_first_run';

	public static function cdn_host() {
		return self::edge()['cdn_host'];
	}

	public static function edge() {
		if ( null !== self::$edge_cache ) {
			return self::$edge_cache;
		}
		$defaults = array(
			'tenant_id'   => '',
			'license_key' => '',
			'domain'      => '',
			'domain_hash' => '',
			'cdn_host'    => '',
			'served'      => array(),
			'served_at'   => 0,
		);
		$saved    = get_option( 'nimbocdn_edge', null );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$edge = wp_parse_args( $saved, $defaults );
		foreach ( array( 'tenant_id', 'license_key', 'domain', 'domain_hash', 'cdn_host' ) as $k ) {
			$edge[ $k ] = is_string( $edge[ $k ] ) ? $edge[ $k ] : '';
		}
		if ( '*' !== $edge['served'] && ! is_array( $edge['served'] ) ) {
			$edge['served'] = array();
		}
		$edge['served_at'] = (int) $edge['served_at'];
		self::$edge_cache  = $edge;
		return $edge;
	}

	public static function save_edge( array $patch ) {
		$edge = array_merge( self::edge(), $patch );
		update_option( 'nimbocdn_edge', $edge, true );
		self::$edge_cache = $edge;
	}

	public static function save_account( array $patch ) {
		$saved = get_option( 'nimbocdn_account', array() );
		update_option( 'nimbocdn_account', array_merge( is_array( $saved ) ? $saved : array(), $patch ), false );
	}

	public static function is_served( $origin_url ) {
		$served = self::edge()['served'];
		if ( '*' === $served ) {
			return true;
		}
		if ( empty( $served ) ) {
			return false;
		}
		static $index = null;
		if ( null === $index ) {
			$index = array_flip( $served );
		}
		return isset( $index[ substr( hash( 'sha256', $origin_url ), 0, 16 ) ] );
	}

	public static function credentials() {
		$edge = self::edge();
		return array(
			'tenant_id'   => $edge['tenant_id'],
			'license_key' => $edge['license_key'],
			'domain'      => $edge['domain'],
		);
	}

	public static function is_paused() {
		return (bool) get_option( 'nimbocdn_paused', false );
	}

	public static function marketing() {
		$saved = get_option( 'nimbocdn_marketing', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array(
			'opt_out' => ! empty( $saved['opt_out'] ),
			'notice'  => ! empty( $saved['notice'] ),
			'synced'  => ! empty( $saved['synced'] ),
		);
	}

	public static function save_marketing( $patch ) {
		$next = self::marketing();
		foreach ( array( 'opt_out', 'notice', 'synced' ) as $key ) {
			if ( array_key_exists( $key, $patch ) ) {
				$next[ $key ] = (bool) $patch[ $key ];
			}
		}
		update_option( 'nimbocdn_marketing', $next, false );
	}

	public static function account() {
		$saved   = get_option( 'nimbocdn_account', array() );
		$account = wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'plan'              => 'free',
				'past_due'          => 0,
				'in_dunning'        => false,
				'marketing_opt_out' => null,
				'cancelling'        => '',
				'interval'          => 'month',
				'renews_at'         => '',
				'switching'         => array(),
				'prices'            => array(),
				'grant'             => array(
					'active'     => false,
					'by'         => '',
					'expires_at' => '',
					'days_left'  => 0,
				),
				'images'            => array(),
				'traffic'           => array(),
				'home'              => array(),
				'fetched_at'        => 0,
			)
		);

		$account['images'] = wp_parse_args(
			is_array( $account['images'] ) ? $account['images'] : array(),
			array(
				'count'           => 0,
				'weighed'         => 0,
				'without_bytes'   => 0,
				'delivered_bytes' => 0,
				'avif_pairs'      => 0,
				'avif_bytes'      => 0,
				'webp_bytes'      => 0,
			)
		);

		$traffic               = is_array( $account['traffic'] ) ? $account['traffic'] : array();
		$traffic               = wp_parse_args(
			$traffic,
			array(
				'last_30d'   => array(),
				'lifetime'   => array(),
				'series'     => array(),
				'updated_at' => '',
			)
		);
		$traffic['last_30d']   = wp_parse_args(
			is_array( $traffic['last_30d'] ) ? $traffic['last_30d'] : array(),
			array(
				'requests'          => 0,
				'cached'            => 0,
				'bytes_out'         => 0,
				'weighed'           => 0,
				'bytes_wp'          => 0,
				'bytes_out_weighed' => 0,
				'avif'              => 0,
				'webp'              => 0,
				'jpeg'              => 0,
				'png'               => 0,
				'bytes_cached'      => 0,
				'sampled'           => 0,
				'delivery_ms_p50'   => -1,
			)
		);
		$traffic['lifetime']   = wp_parse_args(
			is_array( $traffic['lifetime'] ) ? $traffic['lifetime'] : array(),
			array(
				'requests'          => 0,
				'weighed'           => 0,
				'bytes_wp'          => 0,
				'bytes_out_weighed' => 0,
			)
		);
		$traffic['series']     = is_array( $traffic['series'] ) ? array_values( $traffic['series'] ) : array();
		$traffic['updated_at'] = is_string( $traffic['updated_at'] ) ? $traffic['updated_at'] : '';
		$account['traffic']    = $traffic;

		$account['home'] = wp_parse_args(
			is_array( $account['home'] ) ? $account['home'] : array(),
			array(
				'count'     => 0,
				'limit'     => 0,
				'used'      => 0,
				'month'     => '',
				'renews_at' => '',
				'synced_at' => '',
			)
		);

		return $account;
	}

	public static function is_configured() {
		$c = self::credentials();
		return '' !== $c['tenant_id'] && '' !== $c['license_key'] && '' !== $c['domain']
			&& '' !== self::cdn_host();
	}
}
