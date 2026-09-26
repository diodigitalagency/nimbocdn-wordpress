<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Home {

	const HOOK = 'nimbocdn_home_sync';

	const DEBOUNCE = 5 * MINUTE_IN_SECONDS;

	const MAX_ORIGINS = 500;

	const MAX_PAGE_BYTES = 1000000;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'save_post', array( __CLASS__, 'on_post' ), 10, 3 );
		add_action( 'delete_attachment', array( __CLASS__, 'schedule' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'schedule' ) );
		add_action( 'switch_theme', array( __CLASS__, 'schedule' ) );
		add_action( 'updated_option', array( __CLASS__, 'on_option' ), 10, 1 );
	}

	public static function on_post( $post_id, $post, $update ) {
		unset( $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! is_object( $post ) || 'publish' !== $post->post_status ) {
			if ( ! is_object( $post ) || 'trash' !== $post->post_status ) {
				return;
			}
		}
		self::schedule();
	}

	public static function on_option( $option ) {
		if ( in_array( $option, array( 'page_on_front', 'show_on_front', 'sidebars_widgets', 'stylesheet' ), true ) ) {
			self::schedule();
		}
	}

	public static function serves_everything() {
		return '*' === Settings_Store::edge()['served'];
	}

	public static function schedule() {
		if ( ! Settings_Store::is_configured() || self::serves_everything() || get_transient( 'nimbocdn_home_dirty' ) ) {
			return;
		}
		set_transient( 'nimbocdn_home_dirty', 1, self::DEBOUNCE );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	public static function run() {
		delete_transient( 'nimbocdn_home_dirty' );
		if ( self::serves_everything() ) {
			return;
		}
		$page = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => Probe::TIMEOUT,
				'redirection' => 2,
			)
		);
		if ( is_wp_error( $page ) ) {
			return;
		}
		$html = (string) wp_remote_retrieve_body( $page );
		self::sync( self::discover( $html ), false, $html );
	}

	public static function discover( $html ) {
		$backgrounds = array_merge( Rewriter::background_urls( $html ), Rewriter::gallery_thumbnails( $html ) );
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) && empty( $backgrounds ) ) {
			return array();
		}
		$cdn      = Settings_Store::cdn_host();
		$uploads  = wp_upload_dir();
		$base_url = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$files    = array();

		$candidates = $backgrounds;
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $m ) ) {
				$candidates[] = html_entity_decode( $m[1], ENT_QUOTES );
			}
			if ( preg_match( '/\bdata-src=["\']([^"\']+)["\']/i', $tag, $m ) ) {
				$candidates[] = html_entity_decode( $m[1], ENT_QUOTES );
			}
			if ( preg_match( '/\bsrcset=["\']([^"\']+)["\']/i', $tag, $m ) ) {
				foreach ( explode( ',', $m[1] ) as $piece ) {
					$candidates[] = html_entity_decode( trim( explode( ' ', trim( $piece ) )[0] ), ENT_QUOTES );
				}
			}
		}
		foreach ( $candidates as $url ) {
			if ( '' !== $cdn && false !== strpos( $url, $cdn ) ) {
				$parts = explode( '/', (string) wp_parse_url( $url, PHP_URL_PATH ) );
				$url   = rawurldecode( (string) end( $parts ) );
			}
			if ( '' === $base_url || 0 !== strpos( $url, $base_url ) ) {
				$url = Rewriter::original_of_derivative( $url );
				if ( null === $url ) {
					continue;
				}
			}
			$files[ strtok( $url, '?' ) ] = true;
		}

		$origins = array();
		$seen    = array();
		foreach ( self::resolve_many( array_keys( $files ) ) as $origin ) {
			if ( null === $origin || isset( $seen[ $origin ] ) ) {
				continue;
			}
			$seen[ $origin ] = true;
			$origins[]       = $origin;
			if ( count( $origins ) >= self::MAX_ORIGINS ) {
				break;
			}
		}
		return $origins;
	}

	private static function resolve_many( array $urls ) {
		global $wpdb;
		$uploads   = wp_upload_dir();
		$base_path = (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH );
		$tries     = array();
		$wanted    = array();

		foreach ( $urls as $url ) {
			$tries[ $url ] = array();
			if ( ! preg_match( '#/uploads/\d{4}/\d{2}/#', $url ) ) {
				continue;
			}
			$bare   = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]{2,5}$)/i', '', $url );
			$scaled = preg_replace( '/(?=\.[a-z0-9]{2,5}$)/i', '-scaled', $bare, 1 );
			foreach ( array_unique( array( $url, $bare, $scaled ) ) as $try ) {
				$path = (string) wp_parse_url( $try, PHP_URL_PATH );
				if ( '' === $base_path || 0 !== strpos( $path, $base_path . '/' ) ) {
					continue;
				}
				$rel             = substr( $path, strlen( $base_path ) + 1 );
				$tries[ $url ][] = $rel;
				$wanted[ $rel ]  = true;
			}
		}

		$ids = array();
		foreach ( array_chunk( array_keys( $wanted ), 200 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the same query attachment_url_to_postid() runs, in batch; the placeholders are generated above and $chunk travels as prepare() arguments.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($in)", $chunk ) );
			foreach ( (array) $rows as $row ) {
				if ( ! isset( $ids[ $row->meta_value ] ) ) {
					$ids[ $row->meta_value ] = (int) $row->post_id;
				}
			}
		}

		$out = array();
		foreach ( $tries as $url => $rels ) {
			$out[ $url ] = null;
			foreach ( $rels as $rel ) {
				if ( isset( $ids[ $rel ] ) ) {
					$out[ $url ] = Rewriter::origin_for( $ids[ $rel ] );
					break;
				}
			}
			if ( null === $out[ $url ] ) {
				$out[ $url ] = has_filter( 'attachment_url_to_postid' ) && ! empty( $rels )
					? self::origin_of( $url )
					: Rewriter::origin_for_src( $url );
			}
		}
		return $out;
	}

	private static function origin_of( $url ) {
		static $memo = array();

		$url = strtok( $url, '?' );
		if ( isset( $memo[ $url ] ) ) {
			return $memo[ $url ];
		}

		$memo[ $url ] = self::resolve_origin( $url );
		return $memo[ $url ];
	}

	private static function resolve_origin( $url ) {
		$bare = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]{2,5}$)/i', '', $url );

		if ( preg_match( '#/uploads/\d{4}/\d{2}/#', $url ) ) {
			foreach ( array_unique( array( $url, $bare, preg_replace( '/(?=\.[a-z0-9]{2,5}$)/i', '-scaled', $bare, 1 ) ) ) as $try ) {
				$id = attachment_url_to_postid( $try );
				if ( $id > 0 ) {
					return Rewriter::origin_for( $id );
				}
			}
		}

		return Rewriter::origin_for_src( $url );
	}

	public static function sync( array $origins, $force = false, $html = '' ) {
		$before = Settings_Store::edge()['served'];
		$body   = array(
			'origins' => array_values( $origins ),
			'force'   => (bool) $force,
		);
		if ( is_string( $html ) && '' !== $html ) {
			$body['page'] = substr( $html, 0, self::MAX_PAGE_BYTES );
		}
		$response = Api::post( '/site/home', $body );
		if ( ! $response['ok'] ) {
			return false;
		}
		self::apply( $response['data'] );
		return Settings_Store::edge()['served'] !== $before;
	}

	public static function apply( $state ) {
		if ( ! is_array( $state ) || ! isset( $state['served'] ) ) {
			return;
		}
		$served = '*' === $state['served'] ? '*' : array();
		if ( is_array( $state['served'] ) ) {
			foreach ( $state['served'] as $prefix ) {
				if ( is_string( $prefix ) && preg_match( '/^[a-f0-9]{8,64}$/', $prefix ) ) {
					$served[] = $prefix;
				}
			}
		}
		Settings_Store::save_edge(
			array(
				'served'    => $served,
				'served_at' => time(),
			)
		);

		$cap             = isset( $state['cap'] ) && is_array( $state['cap'] ) ? $state['cap'] : array();
		$account         = get_option( 'nimbocdn_account', array() );
		$account         = is_array( $account ) ? $account : array();
		$account['home'] = array(
			'count'     => isset( $state['home_count'] ) ? max( 0, (int) $state['home_count'] ) : 0,
			'limit'     => isset( $cap['limit'] ) ? max( 0, (int) $cap['limit'] ) : 0,
			'used'      => isset( $cap['used'] ) ? max( 0, (int) $cap['used'] ) : 0,
			'month'     => isset( $cap['month'] ) && is_string( $cap['month'] ) ? sanitize_text_field( $cap['month'] ) : '',
			'renews_at' => isset( $cap['renews_at'] ) && is_string( $cap['renews_at'] ) ? sanitize_text_field( $cap['renews_at'] ) : '',
			'synced_at' => isset( $state['synced_at'] ) && is_string( $state['synced_at'] ) ? sanitize_text_field( $state['synced_at'] ) : '',
			'source'    => isset( $state['source'] ) && is_string( $state['source'] ) ? sanitize_key( $state['source'] ) : '',
			'blocked'   => isset( $state['blocked'] ) && is_string( $state['blocked'] ) ? sanitize_key( $state['blocked'] ) : '',
		);
		update_option( 'nimbocdn_account', $account, false );
	}
}
