<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Challenge {

	const TRANSIENT      = 'nimbocdn_challenge';
	const FILE_TRANSIENT = 'nimbocdn_challenge_file';
	const SWEEP_HOOK     = 'nimbocdn_challenge_sweep';
	const TTL            = 5 * MINUTE_IN_SECONDS;
	const FILE_PREFIX    = 'nimbocdn-challenge-';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'init', array( __CLASS__, 'answer_query' ), 1 );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
	}

	public static function remember( $challenge ) {
		set_transient( self::TRANSIENT, $challenge, self::TTL );
		self::write_file( $challenge );
	}

	public static function forget() {
		delete_transient( self::TRANSIENT );
		self::remove_file();
	}

	public static function file_id() {
		$id = get_transient( self::FILE_TRANSIENT );
		return is_string( $id ) && preg_match( '/^[a-f0-9]{12}$/', $id ) ? $id : '';
	}

	public static function file_path() {
		$id = self::file_id();
		if ( '' === $id ) {
			return '';
		}
		$dir  = wp_upload_dir();
		$base = isset( $dir['baseurl'] ) && is_string( $dir['baseurl'] ) ? $dir['baseurl'] : content_url( 'uploads' );
		$path = wp_parse_url( trailingslashit( $base ) . self::FILE_PREFIX . $id . '.txt', PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}

	public static function file_url_pattern() {
		$dir  = wp_upload_dir();
		$base = isset( $dir['baseurl'] ) && is_string( $dir['baseurl'] ) ? $dir['baseurl'] : content_url( 'uploads' );
		return $base . '/' . self::FILE_PREFIX . '*.txt';
	}

	private static function write_file( $challenge ) {
		self::remove_file();
		$dir = self::uploads_dir();
		if ( '' === $dir ) {
			return;
		}
		$fs = self::filesystem();
		if ( null === $fs ) {
			return;
		}
		$id   = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
		$path = trailingslashit( $dir ) . self::FILE_PREFIX . $id . '.txt';
		if ( ! $fs->put_contents( $path, $challenge . "\n", 0644 ) ) {
			return;
		}
		set_transient( self::FILE_TRANSIENT, $id, self::TTL );
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
		wp_schedule_single_event( time() + self::TTL, self::SWEEP_HOOK );
	}

	private static function remove_file() {
		$id = self::file_id();
		delete_transient( self::FILE_TRANSIENT );
		if ( '' === $id ) {
			return;
		}
		$dir = self::uploads_dir();
		$fs  = '' === $dir ? null : self::filesystem();
		if ( null !== $fs && $fs->delete( trailingslashit( $dir ) . self::FILE_PREFIX . $id . '.txt' ) ) {
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}
	}

	public static function sweep() {
		delete_transient( self::FILE_TRANSIENT );
		$dir = self::uploads_dir();
		$fs  = '' === $dir ? null : self::filesystem();
		if ( null === $fs ) {
			return;
		}
		$list = $fs->dirlist( $dir, false, false );
		if ( ! is_array( $list ) ) {
			return;
		}
		foreach ( array_keys( $list ) as $name ) {
			if ( preg_match( '/^' . preg_quote( self::FILE_PREFIX, '/' ) . '[a-f0-9]{12}\.txt$/', (string) $name ) ) {
				$fs->delete( trailingslashit( $dir ) . $name );
			}
		}
	}

	private static function uploads_dir() {
		$dir = wp_upload_dir();
		if ( ! empty( $dir['error'] ) || empty( $dir['basedir'] ) || ! is_string( $dir['basedir'] ) ) {
			return '';
		}
		return $dir['basedir'];
	}

	private static function filesystem() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method( array(), self::uploads_dir(), true ) ) {
			return null;
		}
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return null;
		}
		return $wp_filesystem;
	}

	public static function register_route() {
		register_rest_route(
			'nimbocdn/v1',
			'/challenge',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_answer' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function rest_answer() {
		$challenge = self::pending();
		$response  = new \WP_REST_Response(
			'' === $challenge ? array( 'error' => 'no challenge pending' ) : array( 'challenge' => $challenge ),
			'' === $challenge ? 404 : 200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function answer_query() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only, unauthenticated check by design: it reveals a random one-time value and nothing else.
		if ( ! isset( $_GET['nimbocdn-challenge'] ) ) {
			return;
		}
		$challenge = self::pending();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		status_header( '' === $challenge ? 404 : 200 );
		echo wp_json_encode( '' === $challenge ? array( 'error' => 'no challenge pending' ) : array( 'challenge' => $challenge ) );
		exit;
	}

	private static function pending() {
		$value = get_transient( self::TRANSIENT );
		return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}
}
