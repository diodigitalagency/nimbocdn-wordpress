<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Probe {

	const MAX_IMAGES = 500;

	const BATCH = 25;

	const TIMEOUT = 10;

	const JOB_OPTION = 'nimbocdn_probe_job';

	const LOCK_OPTION = 'nimbocdn_probe_lock';

	const LOCK_STALE = 90;

	const MIN_SLICE = 3;

	const SCOPE_RETRY_WAIT  = 3;
	const SCOPE_RETRIES     = 4;
	const FAILED_RETRY_WAIT = 2;

	const BUDGET_MARGIN    = 8;
	const BUDGET_UNLIMITED = 600;

	public static function run( $wait = true, $max_seconds = null ) {
		$deadline = self::deadline( $max_seconds );
		if ( ! self::lock() ) {
			return self::last();
		}
		$done = false;
		try {
			while ( true ) {
				$step = self::step( $deadline );
				if ( 'done' === $step ) {
					$done = true;
					break;
				}
				if ( 'budget' === $step ) {
					break;
				}
				if ( 'more' === $step ) {
					continue;
				}
				$until = (float) self::job()['wait_until'];
				if ( ! $wait || $until + self::MIN_SLICE >= $deadline ) {
					break;
				}
				$pause = $until - microtime( true );
				if ( $pause > 0 ) {
					usleep( (int) ( $pause * 1000000 ) );
				}
			}
		} finally {
			self::unlock();
		}
		if ( ! $done && ! wp_next_scheduled( Settings_Store::FIRST_RUN_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Settings_Store::FIRST_RUN_HOOK );
		}
		return self::last();
	}

	public static function in_flight() {
		if ( 'pending' === self::last()['state'] ) {
			return true;
		}
		$job = get_option( self::JOB_OPTION, null );
		return is_array( $job ) && isset( $job['stage'] );
	}

	private static function deadline( $max_seconds ) {
		$start  = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && 'cli' !== PHP_SAPI ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$limit  = (int) ini_get( 'max_execution_time' );
		$budget = $limit <= 0 ? self::BUDGET_UNLIMITED : max( self::MIN_SLICE + 1, $limit - self::BUDGET_MARGIN );
		if ( null !== $max_seconds ) {
			$budget = min( $budget, max( self::MIN_SLICE + 1, (int) $max_seconds ) );
		}
		return max( $start + $budget, microtime( true ) + self::MIN_SLICE + 1 );
	}

	private static function lock() {
		global $wpdb;
		$now = time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- an atomic lock needs the table's unique index; the Options API does not expose it.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
		if ( null !== $held && $now - (int) $held < self::LOCK_STALE ) {
			return false;
		}
		if ( null !== $held ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $held ) );
		}
		$suppress = $wpdb->suppress_errors();
		$got      = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK_OPTION, (string) $now ) );
		$wpdb->suppress_errors( $suppress );
		// phpcs:enable
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return (bool) $got;
	}

	private static function unlock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counterpart of lock().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	private static function job() {
		$job = get_option( self::JOB_OPTION, null );
		if ( is_array( $job ) && isset( $job['stage'] ) ) {
			return $job;
		}
		return array(
			'stage'       => 'page',
			'started'     => time(),
			'synced'      => false,
			'just_synced' => false,
			'urls'        => array(),
			'page'        => 0,
			'responses'   => array(),
			'queue'       => array(),
			'scope_tries' => 0,
			'wait_until'  => 0,
		);
	}

	private static function save_job( array $job ) {
		update_option( self::JOB_OPTION, $job, false );
	}

	private static function step( $deadline ) {
		$job  = self::job();
		$left = $deadline - microtime( true );
		switch ( $job['stage'] ) {
			case 'page':
				return self::step_page( $job, $left );
			case 'sync':
				return self::step_sync( $job, $left );
			case 'fetch':
			case 'retry_scope':
			case 'retry_failed':
			case 'recache':
				return self::step_fetch( $job, $deadline );
			default:
				return self::finish( $job );
		}
	}

	private static function step_page( array $job, $left ) {
		if ( $left < self::MIN_SLICE ) {
			return 'budget';
		}
		if ( Settings_Store::is_paused() ) {
			return self::finish_with( array( 'state' => 'paused' ) );
		}
		if ( ! Settings_Store::is_configured() ) {
			return self::finish_with(
				array(
					'state'  => 'error',
					'detail' => 'not-configured',
				)
			);
		}

		self::progress( 0, 0, 0, $job['synced'] ? 'page_again' : 'page' );
		$page = wp_remote_get(
			add_query_arg( 'nimbocdn-probe', (string) time(), home_url( '/' ) ),
			array(
				'timeout'     => min( self::TIMEOUT, max( 1, (int) floor( $left - 1 ) ) ),
				'redirection' => 2,
			)
		);
		if ( is_wp_error( $page ) ) {
			return self::finish_with(
				array(
					'state'  => 'error',
					'detail' => $page->get_error_code(),
				)
			);
		}
		$html = (string) wp_remote_retrieve_body( $page );

		$job['urls']      = self::delivery_urls( $html );
		$job['page']      = self::page_images( $html );
		$job['responses'] = array();

		if ( ! $job['synced'] && ! Home::serves_everything() ) {
			$job['origins'] = Home::discover( $html );
			$job['stage']   = 'sync';
			self::save_job( $job );
			self::progress( count( $job['urls'] ), 0, (int) $job['page'], 'sync' );
			return 'more';
		}
		return self::start_fetch( $job );
	}

	private static function step_sync( array $job, $left ) {
		if ( $left < self::MIN_SLICE ) {
			return 'budget';
		}
		$origins       = isset( $job['origins'] ) && is_array( $job['origins'] ) ? $job['origins'] : array();
		$job['synced'] = true;
		unset( $job['origins'] );
		if ( ! empty( $origins ) && Home::sync( $origins, true ) ) {
			$job['just_synced'] = true;
			$job['stage']       = 'page';
			self::save_job( $job );
			return 'more';
		}
		return self::start_fetch( $job );
	}

	private static function start_fetch( array $job ) {
		if ( empty( $job['urls'] ) ) {
			return self::finish_with(
				array(
					'state'    => 'no_images',
					'page'     => (int) $job['page'],
					'unserved' => (int) $job['page'],
				)
			);
		}
		$job['stage'] = 'fetch';
		$job['queue'] = array_keys( $job['urls'] );
		self::save_job( $job );
		self::progress( count( $job['urls'] ), 0, (int) $job['page'], 'fetch' );
		return 'more';
	}

	private static function step_fetch( array $job, $deadline ) {
		if ( empty( $job['queue'] ) ) {
			return self::advance( $job );
		}
		if ( microtime( true ) < (float) $job['wait_until'] ) {
			return 'wait';
		}
		$left = $deadline - microtime( true );
		if ( $left < self::MIN_SLICE ) {
			return 'budget';
		}

		$batch = array_splice( $job['queue'], 0, self::BATCH );
		$timeout   = (int) max( 1, min( self::TIMEOUT, floor( $left - 1 ) ) );
		$responses = self::fetch_all( $batch, $timeout );

		foreach ( $responses as $url => $res ) {
			switch ( $job['stage'] ) {
				case 'fetch':
				case 'retry_scope':
					$job['responses'][ $url ] = $res;
					break;
				case 'retry_failed':
					if ( null !== $res && 200 === $res['code'] ) {
						$job['responses'][ $url ] = $res;
					}
					break;
				case 'recache':
					if ( null !== $res && 200 === $res['code'] && isset( $job['responses'][ $url ] ) && null !== $job['responses'][ $url ] ) {
						$job['responses'][ $url ]['cache'] = $res['cache'];
						$job['responses'][ $url ]['ms']    = $res['ms'];
					}
					break;
			}
		}
		self::save_job( $job );
		self::progress( count( $job['urls'] ), self::weighed( $job ), (int) $job['page'], $job['stage'], (int) $job['scope_tries'] );
		return 'more';
	}

	private static function weighed( array $job ) {
		$n = 0;
		foreach ( $job['responses'] as $res ) {
			if ( null !== $res && 200 === $res['code'] ) {
				++$n;
			}
		}
		return $n;
	}

	private static function advance( array $job ) {
		$stage = $job['stage'];

		if ( 'fetch' === $stage ) {
			self::publish_provisional( $job );
		}

		if ( 'fetch' === $stage || 'retry_scope' === $stage ) {
			if ( $job['just_synced'] && $job['scope_tries'] < self::SCOPE_RETRIES ) {
				$pending = array();
				foreach ( $job['responses'] as $url => $res ) {
					if ( null !== $res && 307 === $res['code'] && 'not-in-free-scope' === $res['reason'] ) {
						$pending[] = $url;
					}
				}
				if ( ! empty( $pending ) ) {
					$job['stage']      = 'retry_scope';
					$job['queue']      = $pending;
					$job['wait_until'] = microtime( true ) + self::SCOPE_RETRY_WAIT;
					++$job['scope_tries'];
					self::save_job( $job );
					self::progress( count( $job['urls'] ), self::weighed( $job ), (int) $job['page'], 'retry_scope', (int) $job['scope_tries'] );
					return 'wait';
				}
			}
			$failed = array();
			foreach ( $job['urls'] as $url => $baseline ) {
				$res = isset( $job['responses'][ $url ] ) ? $job['responses'][ $url ] : null;
				if ( null === $res || 200 !== $res['code'] ) {
					$failed[] = $url;
				}
			}
			if ( ! empty( $failed ) && count( $failed ) < count( $job['urls'] ) ) {
				$job['stage']      = 'retry_failed';
				$job['queue']      = $failed;
				$job['wait_until'] = microtime( true ) + self::FAILED_RETRY_WAIT;
				self::save_job( $job );
				self::progress( count( $job['urls'] ), self::weighed( $job ), (int) $job['page'], 'retry_failed' );
				return 'wait';
			}
			$stage = 'retry_failed';
		}

		if ( 'retry_failed' === $stage ) {
			$ok     = array();
			$missed = false;
			foreach ( $job['responses'] as $url => $res ) {
				if ( null !== $res && 200 === $res['code'] ) {
					$ok[] = $url;
					if ( 'MISS' === $res['cache'] ) {
						$missed = true;
					}
				}
			}
			if ( $missed ) {
				$job['stage']      = 'recache';
				$job['queue']      = $ok;
				$job['wait_until'] = 0;
				self::save_job( $job );
				self::progress( count( $job['urls'] ), self::weighed( $job ), (int) $job['page'], 'recache' );
				return 'more';
			}
		}

		return self::finish( $job );
	}

	private static function finish_with( array $partial ) {
		self::store(
			array_merge(
				array(
					'detail'   => '',
					'images'   => 0,
					'before'   => 0,
					'after'    => 0,
					'format'   => '',
					'library'  => self::library_size(),
					'page'     => 0,
					'served'   => 0,
					'unserved' => 0,
				),
				$partial
			)
		);
		return 'done';
	}

	private static function finish( array $job ) {
		$result = self::compute( $job );
		if ( 'serving' !== $result['state'] ) {
			return self::finish_with( $result );
		}
		self::store( $result );
		return 'done';
	}

	private static function publish_provisional( array $job ) {
		$result = self::compute( $job );
		if ( 'serving' !== $result['state'] ) {
			return;
		}
		$result['provisional'] = true;
		$result['at']          = time();
		$result['progress_at'] = time();
		update_option( 'nimbocdn_probe', $result, false );
	}

	private static function compute( array $job ) {
		$urls            = $job['urls'];
		$after           = 0;
		$before          = 0;
		$formats         = array();
		$fallback_reason = '';
		$cached          = 0;
		$cached_bytes    = 0;
		$times           = array();

		foreach ( $job['responses'] as $url => $res ) {
			if ( null === $res || ! isset( $urls[ $url ] ) ) {
				continue;
			}
			if ( 307 === $res['code'] || 302 === $res['code'] ) {
				$fallback_reason = $res['reason'];
				continue;
			}
			if ( 200 !== $res['code'] ) {
				continue;
			}

			$after    += $res['bytes'];
			$before   += self::reference_bytes( $url, $urls[ $url ] );
			$formats[] = $res['type'];
			if ( in_array( $res['cache'], array( 'EDGE', 'HIT' ), true ) ) {
				++$cached;
				$cached_bytes += $res['bytes'];
				if ( $res['ms'] >= 0 ) {
					$times[] = $res['ms'];
				}
			}
		}

		$page     = (int) $job['page'];
		$served   = count( $urls );
		$unserved = max( 0, $page - $served );

		if ( 0 === $after ) {
			return array(
				'state'    => 'fallback',
				'detail'   => $fallback_reason,
				'images'   => $served,
				'page'     => $page,
				'served'   => $served,
				'unserved' => $unserved,
			);
		}

		$tally = array_count_values( array_filter( $formats ) );
		arsort( $tally );
		$by_format = array(
			'avif' => 0,
			'webp' => 0,
			'jpeg' => 0,
			'png'  => 0,
		);
		foreach ( $tally as $mime => $n ) {
			foreach ( array_keys( $by_format ) as $f ) {
				if ( false !== strpos( $mime, $f ) || ( 'jpeg' === $f && false !== strpos( $mime, 'jpg' ) ) ) {
					$by_format[ $f ] += (int) $n;
					break;
				}
			}
		}

		sort( $times );
		return array(
			'state'        => 'serving',
			'detail'       => $fallback_reason,
			'page'         => $page,
			'served'       => $served,
			'unserved'     => $unserved,
			'images'       => count( $formats ),
			'before'       => $before,
			'after'        => $after,
			'format'       => (string) key( $tally ),
			'library'      => self::library_size(),
			'cached'       => $cached,
			'cached_bytes' => $cached_bytes,
			'ms'           => empty( $times ) ? -1 : $times[ (int) floor( count( $times ) / 2 ) ],
			'formats'      => $by_format,
		);
	}

	private static function store( array $result ) {
		$result['at']          = time();
		$result['provisional'] = false;
		if ( 'error' === $result['state'] ) {
			$last = self::last();
			if ( 'serving' === $last['state'] && $last['before'] > 0 ) {
				$result = array_merge(
					$last,
					array(
						'state'    => 'serving',
						'stale'    => true,
						'error_at' => $result['at'],
						'error'    => $result['detail'],
						'library'  => $result['library'],
					)
				);
			}
		}
		delete_option( self::JOB_OPTION );
		update_option( 'nimbocdn_probe', $result, false );
	}

	const PENDING_MAX = 3 * MINUTE_IN_SECONDS;

	public static function mark_pending() {
		delete_option( self::JOB_OPTION );
		update_option(
			'nimbocdn_probe',
			array(
				'state'       => 'pending',
				'at'          => time(),
				'progress_at' => time(),
				'found'       => 0,
				'weighed'     => 0,
				'page'        => 0,
				'stage'       => '',
				'tries'       => 0,
			),
			false
		);
	}

	public static function note_stage( $stage ) {
		$saved = get_option( 'nimbocdn_probe', array() );
		if ( ! is_array( $saved ) || ! isset( $saved['state'] ) || 'pending' !== $saved['state'] ) {
			return;
		}
		self::progress( (int) $saved['found'], (int) $saved['weighed'], (int) $saved['page'], $stage );
	}

	private static function progress( $found, $weighed, $page = 0, $stage = '', $tries = 0 ) {
		$saved = get_option( 'nimbocdn_probe', array() );
		if ( ! is_array( $saved ) || ! isset( $saved['state'] ) || ( 'pending' !== $saved['state'] && empty( $saved['provisional'] ) ) ) {
			return;
		}
		$saved['found']       = (int) $found;
		$saved['weighed']     = (int) $weighed;
		$saved['page']        = (int) $page;
		$saved['stage']       = (string) $stage;
		$saved['tries']       = (int) $tries;
		$saved['progress_at'] = time();
		update_option( 'nimbocdn_probe', $saved, false );
	}

	public static function last() {
		$saved = get_option( 'nimbocdn_probe', array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'state'        => 'unknown',
				'at'           => 0,
				'detail'       => '',
				'images'       => 0,
				'before'       => 0,
				'after'        => 0,
				'format'       => '',
				'library'      => 0,
				'found'        => 0,
				'weighed'      => 0,
				'cached'       => 0,
				'cached_bytes' => 0,
				'ms'           => -1,
				'formats'      => array(),
				'page'         => 0,
				'served'       => 0,
				'unserved'     => 0,
				'progress_at'  => 0,
				'stage'        => '',
				'tries'        => 0,
				'provisional'  => false,
				'stale'        => false,
				'error_at'     => 0,
				'error'        => '',
			)
		);
	}


	private static function fetch_all( array $urls, $timeout = self::TIMEOUT ) {
		$pre = apply_filters( 'nimbocdn_pre_probe_fetch', null, $urls );
		if ( is_array( $pre ) ) {
			return $pre;
		}
		if ( count( $urls ) > self::BATCH ) {
			$out = array();
			foreach ( array_chunk( $urls, self::BATCH, true ) as $batch ) {
				$out += self::fetch_all( $batch, $timeout );
			}
			return $out;
		}

		$requests = array();
		foreach ( $urls as $i => $url ) {
			$requests[ $i ] = array(
				'url'     => $url,
				'type'    => 'GET',
				'headers' => array(
					'Accept'           => 'image/avif,image/webp,image/*',
					'X-NimboCDN-Probe' => '1',
				),
				'options' => array(
					'timeout'          => max( 1, (int) $timeout ),
					'follow_redirects' => false,
				),
			);
		}

		$class = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : '\Requests';
		try {
			$responses = call_user_func( array( $class, 'request_multiple' ), $requests );
		} catch ( \Throwable $e ) {
			$responses = array();
		}

		$out = array();
		foreach ( $urls as $i => $url ) {
			$r = isset( $responses[ $i ] ) ? $responses[ $i ] : null;
			if ( ! is_object( $r ) || ! isset( $r->status_code ) ) {
				$out[ $url ] = null;
				continue;
			}
			$timing      = (string) ( isset( $r->headers['server-timing'] ) ? $r->headers['server-timing'] : '' );
			$out[ $url ] = array(
				'code'   => (int) $r->status_code,
				'bytes'  => strlen( (string) $r->body ),
				'type'   => strtolower( (string) ( isset( $r->headers['content-type'] ) ? $r->headers['content-type'] : '' ) ),
				'reason' => (string) ( isset( $r->headers['x-nimbocdn-fallback-reason'] ) ? $r->headers['x-nimbocdn-fallback-reason'] : '' ),
				'cache'  => strtoupper( (string) ( isset( $r->headers['x-nimbocdn-cache'] ) ? $r->headers['x-nimbocdn-cache'] : '' ) ),
				'ms'     => preg_match( '/worker;dur=(\d+)/', $timing, $m ) ? (int) $m[1] : -1,
			);
		}
		return $out;
	}

	private static function reference_bytes( $url, $baseline ) {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( is_string( $query ) ) {
			parse_str( $query, $q );
			if ( isset( $q['w'] ) && (int) $q['w'] > 0 ) {
				return (int) $q['w'];
			}
		}
		return self::wordpress_bytes( $baseline );
	}

	private static function library_size() {
		$counts = (array) wp_count_attachments( 'image' );
		$total  = 0;
		foreach ( $counts as $n ) {
			$total += (int) $n;
		}
		return $total;
	}

	private static function delivery_urls( $html ) {
		$host = Settings_Store::cdn_host();
		if ( '' === $host ) {
			return array();
		}
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			return array();
		}

		$found = array();
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $s ) ) {
				continue;
			}
			$url = html_entity_decode( $s[1], ENT_QUOTES );
			if ( false === strpos( $url, $host ) || isset( $found[ $url ] ) ) {
				continue;
			}

			$baseline = self::baseline_url( $tag, $url );
			if ( null === $baseline ) {
				continue;
			}

			$found[ $url ] = $baseline;
			if ( count( $found ) >= self::MAX_IMAGES ) {
				break;
			}
		}
		return $found;
	}

	private static function page_images( $html ) {
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			return 0;
		}
		$host    = Settings_Store::cdn_host();
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$base    = preg_replace( '#^https?://(www\.)?#i', '', $base );
		$seen    = array();
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $s ) ) {
				continue;
			}
			$url     = html_entity_decode( $s[1], ENT_QUOTES );
			$is_ours = ( '' !== $host && false !== strpos( $url, $host ) )
				|| ( '' !== $base && false !== strpos( preg_replace( '#^https?://(www\.)?#i', '', $url ), $base ) );
			if ( ! $is_ours ) {
				continue;
			}
			$seen[ $url ] = true;
			if ( count( $seen ) >= self::MAX_IMAGES ) {
				break;
			}
		}
		return count( $seen );
	}

	private static function baseline_url( $tag, $url ) {
		if ( preg_match( '/\bonerror=(["\'])(.*?)\1/is', $tag, $o ) ) {
			$js = html_entity_decode( $o[2], ENT_QUOTES );
			if ( preg_match( '/\bsrc\s*=\s*([\'"])(.*?)\1/', $js, $m ) && 0 === strpos( $m[2], 'http' ) ) {
				return $m[2];
			}
		}

		$parts  = explode( '/', wp_parse_url( $url, PHP_URL_PATH ) );
		$origin = rawurldecode( (string) end( $parts ) );
		return 0 === strpos( $origin, 'http' ) ? $origin : null;
	}

	private static function wordpress_bytes( $origin_url ) {
		$path = wp_parse_url( $origin_url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return 0;
		}

		$uploads   = wp_upload_dir();
		$base_path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( ! is_string( $base_path ) || 0 !== strpos( $path, $base_path ) ) {
			return 0;
		}

		$file = $uploads['basedir'] . substr( $path, strlen( $base_path ) );
		if ( ! file_exists( $file ) ) {
			return 0;
		}

		$size = filesize( $file );
		return is_int( $size ) ? $size : 0;
	}
}
