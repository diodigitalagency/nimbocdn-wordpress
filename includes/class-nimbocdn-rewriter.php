<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Rewriter {

	const MARKER = '/i/';

	public static function init() {
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_attributes' ), 20, 3 );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'filter_srcset' ), 20, 5 );

		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_content_img' ), 20, 3 );

		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );

		add_filter( 'wp_resource_hints', array( __CLASS__, 'preconnect' ), 10, 2 );
	}

	public static function preconnect( $urls, $relation_type ) {
		if ( 'preconnect' !== $relation_type || ! self::should_rewrite() ) {
			return $urls;
		}
		$host = Settings_Store::cdn_host();
		if ( '' !== $host ) {
			$urls[] = array(
				'href'        => 'https://' . $host,
				'crossorigin' => 'anonymous',
			);
		}
		return $urls;
	}

	private static function should_rewrite() {
		if ( is_admin() || is_feed() || wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( ! Settings_Store::is_configured() ) {
			return false;
		}
		if ( Settings_Store::is_paused() ) {
			return false;
		}
		return Health::is_service_up();
	}

	public static function filter_content_img( $filtered_image, $context, $attachment_id ) {
		unset( $context );

		if ( ! self::should_rewrite() || ! $attachment_id ) {
			return $filtered_image;
		}
		if ( ! preg_match( '/\ssrc=["\']([^"\']+)["\']/i', $filtered_image, $src ) ) {
			return $filtered_image;
		}

		if ( false !== strpos( $src[1], self::MARKER ) ) {
			return $filtered_image;
		}

		$width = 0;
		if ( preg_match( '/\swidth=["\']?(\d+)/i', $filtered_image, $w ) ) {
			$width = (int) $w[1];
		}
		if ( $width <= 0 ) {
			$meta  = wp_get_attachment_metadata( $attachment_id );
			$width = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		}

		$url = self::delivery_url( $attachment_id, $width > 0 ? $width : Signer::MAX_OFFERED_WIDTH );
		if ( null === $url ) {
			return $filtered_image;
		}

		$onerror = "this.onerror=null;this.srcset='';this.src='" . esc_js( $src[1] ) . "';";

		$rewritten = str_replace( $src[0], ' src="' . esc_url( $url ) . '"', $filtered_image );

		if ( ! preg_match( '/\ssrcset=/i', $rewritten )
			&& ! preg_match( '/\sdata-(lazy-)?srcset=/i', $rewritten ) ) {
			$file = self::uploads_file( $src[1] );
			if ( null !== $file ) {
				$own = self::own_srcset( $src[1], self::origin_width( $file ), $width );
				if ( null !== $own ) {
					$rewritten = str_replace(
						'<img ',
						'<img srcset="' . esc_attr( $own['srcset'] ) . '" sizes="' . esc_attr( $own['sizes'] ) . '" ',
						$rewritten
					);
				}
			}
		}

		return str_replace( '<img ', '<img data-nimbocdn="1" onerror="' . esc_attr( $onerror ) . '" ', $rewritten );
	}

	public static function start_buffer() {
		if ( ! self::should_rewrite() ) {
			return;
		}
		ob_start( array( __CLASS__, 'filter_output' ) );
	}

	public static function filter_output( $html ) {
		if ( ! defined( 'NIMBOCDN_PROFILE' ) || ! NIMBOCDN_PROFILE ) {
			return self::rewrite_html( $html );
		}
		$t0  = microtime( true );
		$out = self::rewrite_html( $html );
		if ( false !== stripos( $out, '</html>' ) ) {
			$out .= sprintf(
				"\n<!-- nimbocdn pass=%.1fms in=%d kB -->",
				( microtime( true ) - $t0 ) * 1000,
				(int) round( strlen( $html ) / 1024 )
			);
		}
		return $out;
	}

	private static function rewrite_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return is_string( $html ) ? $html : '';
		}
		if ( false === stripos( $html, '<img' ) && false === stripos( $html, 'url(' ) && false === stripos( $html, '<a ' ) && false === stripos( $html, '<picture' ) ) {
			return $html;
		}
		if ( false === stripos( $html, '<picture' ) ) {
			$filtered = preg_replace_callback( '/<img\b[^>]*>/i', array( __CLASS__, 'rewrite_tag' ), $html );

			return is_string( $filtered ) ? self::rewrite_anchors_and_backgrounds( $filtered ) : $html;
		}

		$parts = preg_split( '/(<picture\b[^>]*>.*?<\/picture>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}
		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				$parts[ $i ] = self::rewrite_picture_block( $part );
				continue;
			}
			$r           = preg_replace_callback( '/<img\b[^>]*>/i', array( __CLASS__, 'rewrite_tag' ), $part );
			$parts[ $i ] = is_string( $r ) ? $r : $part;
		}
		$filtered = implode( '', $parts );
		return self::rewrite_anchors_and_backgrounds( $filtered );
	}

	private static function rewrite_anchors_and_backgrounds( $filtered ) {

		$anchors = preg_replace_callback(
			'/<a\b([^>]*?)\shref=(["\'])(https?:\/\/[^"\']+?\.(?:jpe?g|png|gif|webp)(?:\?[^"\']*)?)\2([^>]*)>(\s*<img\b)/i',
			array( __CLASS__, 'rewrite_anchor' ),
			$filtered
		);
		if ( ! is_string( $anchors ) ) {
			return $filtered;
		}

		$backgrounds = preg_replace_callback(
			'/url\((["\']?)(https?:\/\/[^"\')\s]+?\/wp-content\/uploads\/[^"\')\s]+?\.(?:jpe?g|png|gif|webp)(?:\?[^"\')\s]*)?)\1\)/i',
			array( __CLASS__, 'rewrite_css_url' ),
			$anchors
		);
		return is_string( $backgrounds ) ? $backgrounds : $anchors;
	}

	private static function rewrite_picture_block( $block ) {
		$with_sources = preg_replace_callback( '/<source\b[^>]*>/i', array( __CLASS__, 'rewrite_source' ), $block );
		if ( ! is_string( $with_sources ) ) {
			return $block;
		}

		if ( self::picture_won_by_other( $with_sources ) ) {
			return $block;
		}

		$with_img = preg_replace_callback( '/<img\b[^>]*>/i', array( __CLASS__, 'rewrite_tag' ), $with_sources );
		return is_string( $with_img ) ? $with_img : $block;
	}

	private static function picture_won_by_other( $block ) {
		if ( ! preg_match_all( '/<source\b[^>]*>/i', $block, $sources ) ) {
			return false;
		}
		foreach ( $sources[0] as $tag ) {
			if ( preg_match( '/\smedia=(["\'])\s*\S.*?\1/is', $tag ) ) {
				continue;
			}
			if ( ! preg_match( '/\s(?:data-)?srcset=(["\'])(.*?)\1/is', $tag, $set ) ) {
				continue;
			}
			if ( false === strpos( html_entity_decode( $set[2], ENT_QUOTES ), self::MARKER ) ) {
				return true;
			}
		}
		return false;
	}

	private static function rewrite_source( $matches ) {
		$tag = $matches[0];
		if ( ! preg_match( '/\ssrcset=(["\'])(.*?)\1/is', $tag, $set ) ) {
			return $tag;
		}
		$translated = false;
		$updated    = self::rewrite_srcset( $set[2], true, $translated );
		if ( null === $updated ) {
			return $tag;
		}
		$tag = str_replace( $set[0], ' srcset="' . esc_attr( $updated ) . '"', $tag );
		if ( ! $translated ) {
			return $tag;
		}

		$clean = preg_replace( '/\stype=(["\'])[^"\']*\1/i', '', $tag );
		return is_string( $clean ) ? $clean : $tag;
	}

	private static function rewrite_css_url( $matches ) {
		$original = html_entity_decode( $matches[2], ENT_QUOTES );
		if ( false !== strpos( $original, self::MARKER ) ) {
			return $matches[0];
		}
		$url = self::hero_url( $original );
		if ( null === $url ) {
			return $matches[0];
		}
		return 'url(' . $matches[1] . esc_url( $url ) . $matches[1] . ')';
	}

	private static function rewrite_anchor( $matches ) {
		$original = html_entity_decode( $matches[3], ENT_QUOTES );
		$url      = self::hero_url( $original );
		if ( null === $url ) {
			return $matches[0];
		}
		return '<a' . $matches[1] . ' href="' . esc_url( $url ) . '"' . $matches[4] . '>' . $matches[5];
	}

	private static function hero_url( $src ) {
		$file = self::uploads_file( $src );
		if ( null === $file ) {
			return null;
		}
		$width = self::origin_width( self::source_file( $file ) );
		return self::delivery_url_for_src( $src, max( $width, Signer::MAX_OFFERED_WIDTH ), true );
	}

	private static function rewrite_tag( $matches ) {
		$tag = $matches[0];

		if ( ! preg_match( '/\ssrc=(["\'])(.*?)\1/i', $tag, $src ) ) {
			return $tag;
		}
		$original = html_entity_decode( $src[2], ENT_QUOTES );
		if ( '' === $original ) {
			return $tag;
		}

		$already_ours = false !== strpos( $original, self::MARKER );
		$rewritten    = $tag;
		$onerror      = '';

		$width = 0;
		if ( preg_match( '/\swidth=["\']?(\d+)/i', $tag, $w ) ) {
			$width = (int) $w[1];
		}

		if ( ! $already_ours ) {

			$url = self::delivery_url_for_src( $original, $width > 0 ? $width : Signer::MAX_OFFERED_WIDTH );
			if ( null !== $url ) {
				$onerror   = "this.onerror=null;this.srcset='';this.src='" . esc_js( $original ) . "';";
				$rewritten = str_replace( $src[0], ' src="' . esc_url( $url ) . '"', $tag );
			}
		}

		if ( ! preg_match( '/\ssrcset=/i', $rewritten )
			&& ! preg_match( '/\sdata-(lazy-)?srcset=/i', $rewritten ) ) {
			$file = self::uploads_file( $original );
			if ( null !== $file ) {
				$own = self::own_srcset( $original, self::origin_width( $file ), $width );
				if ( null !== $own ) {
					$rewritten = str_replace(
						'<img ',
						'<img srcset="' . esc_attr( $own['srcset'] ) . '" sizes="' . esc_attr( $own['sizes'] ) . '" ',
						$rewritten
					);
				}
			}
		}

		if ( preg_match( '/\ssrcset=(["\'])(.*?)\1/is', $rewritten, $set ) ) {
			$updated = self::rewrite_srcset( $set[2] );
			if ( null !== $updated ) {
				$rewritten = str_replace( $set[0], ' srcset="' . esc_attr( $updated ) . '"', $rewritten );
			}
		}

		foreach ( array( 'data-src', 'data-large_image', 'data-lazy-src', 'data-full-url' ) as $attr ) {
			if ( preg_match( '/\s' . preg_quote( $attr, '/' ) . '=(["\'])(.*?)\1/i', $rewritten, $m ) ) {
				$value = html_entity_decode( $m[2], ENT_QUOTES );
				if ( '' === $value || false !== strpos( $value, self::MARKER ) ) {
					continue;
				}
				if ( 'data-large_image' === $attr || 'data-full-url' === $attr ) {
					$url = self::hero_url( $value );
				} else {
					$url = self::delivery_url_for_src( $value, $width > 0 ? $width : Signer::MAX_OFFERED_WIDTH );
				}
				if ( null !== $url ) {
					$rewritten = str_replace( $m[0], ' ' . $attr . '="' . esc_url( $url ) . '"', $rewritten );
				}
			}
		}
		foreach ( array( 'data-srcset', 'data-lazy-srcset' ) as $attr ) {
			if ( preg_match( '/\s' . preg_quote( $attr, '/' ) . '=(["\'])(.*?)\1/is', $rewritten, $m ) ) {
				$updated = self::rewrite_srcset( $m[2] );
				if ( null !== $updated ) {
					$rewritten = str_replace( $m[0], ' ' . $attr . '="' . esc_attr( $updated ) . '"', $rewritten );
				}
			}
		}

		$rewritten = self::with_sizes_auto( $rewritten );

		if ( $rewritten === $tag ) {
			return $tag;
		}
		if ( false !== strpos( $rewritten, 'data-nimbocdn' ) ) {
			return $rewritten;
		}

		return preg_replace(
			'/^<img\b/i',
			'<img data-nimbocdn="1"' . ( '' !== $onerror ? ' onerror="' . esc_attr( $onerror ) . '"' : '' ),
			$rewritten,
			1
		);
	}

	private static function with_sizes_auto( $tag ) {
		if ( false === strpos( $tag, self::MARKER ) || ! preg_match( '/\ssrcset=/i', $tag ) ) {
			return $tag;
		}
		if ( ! preg_match( '/\sloading=["\']?lazy["\']?(?=[\s\/>])/i', $tag ) ) {
			return $tag;
		}
		if ( ! preg_match( '/\swidth=["\']?[1-9]\d*/i', $tag ) || ! preg_match( '/\sheight=["\']?[1-9]\d*/i', $tag ) ) {
			return $tag;
		}
		if ( ! preg_match( '/\ssizes=(["\'])(.*?)\1/is', $tag, $m ) ) {
			return $tag;
		}
		if ( preg_match( '/^\s*auto(\s*,|\s*$)/i', $m[2] ) ) {
			return $tag;
		}
		return str_replace( $m[0], ' sizes=' . $m[1] . 'auto, ' . $m[2] . $m[1], $tag );
	}

	private static function rewrite_srcset( $srcset, $translate = false, &$translated = false ) {
		$out        = array();
		$any        = false;
		$translated = false;

		foreach ( explode( ',', $srcset ) as $piece ) {
			$piece = trim( $piece );
			if ( '' === $piece ) {
				continue;
			}
			$parts      = preg_split( '/\s+/', $piece, 2 );
			$candidate  = html_entity_decode( $parts[0], ENT_QUOTES );
			$descriptor = isset( $parts[1] ) ? trim( $parts[1] ) : '';

			$width = 0;
			if ( preg_match( '/^(\d+)w$/', $descriptor, $d ) ) {
				$width = (int) $d[1];
			}

			$mirable = $width > 0 && false === strpos( $candidate, self::MARKER );
			$url     = $mirable ? self::delivery_url_for_src( $candidate, $width ) : null;

			if ( null === $url && $translate && $mirable ) {
				$original = self::original_behind_foreign_derivative( $candidate );
				if ( null !== $original ) {
					$url = self::delivery_url_for_src( $original, $width );
					if ( null !== $url ) {
						$translated = true;
					}
				}
			}

			if ( null === $url ) {
				$out[] = $piece;
				continue;
			}
			$any = true;
			$previous = isset( $out[ $url ] ) ? (int) $out[ $url ] : 0;
			if ( $width > $previous ) {
				$out[ $url ] = $width;
			}
		}

		if ( ! $any ) {
			return null;
		}

		$pieces = array();
		foreach ( $out as $key => $value ) {
			$pieces[] = is_int( $key ) ? $value : $key . ' ' . $value . 'w';
		}
		return implode( ', ', $pieces );
	}

	private static function original_behind_foreign_derivative( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}
		$path = rawurldecode( $path );
		if ( false !== strpos( $path, '..' ) ) {
			return null;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( '' !== (string) $host && wp_parse_url( home_url(), PHP_URL_HOST ) !== $host ) {
			return null;
		}

		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return null;
		}
		$base = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( is_string( $base ) && '' !== $base && 0 === strpos( $path, $base ) ) {
			return null;
		}

		if ( preg_match( '/\.(?:webp|avif)$/i', $path ) ) {
			$stripped = substr( $path, 0, (int) strrpos( $path, '.' ) );
			if ( preg_match( '/\.(?:jpe?g|png|gif)$/i', $stripped ) ) {
				$path = $stripped;
			}
		}
		if ( ! preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', $path ) ) {
			return null;
		}

		$segments = explode( '/', ltrim( $path, '/' ) );
		$n        = count( $segments );
		$from_index = max( 0, $n - 6 );
		for ( $i = $from_index; $i < $n; $i++ ) {
			$relative = implode( '/', array_slice( $segments, $i ) );
			if ( is_file( $uploads['basedir'] . '/' . $relative ) ) {
				return $uploads['baseurl'] . '/' . $relative;
			}
		}
		return null;
	}

	private static function origin_width( $file ) {
		static $cache = array();
		if ( isset( $cache[ $file ] ) ) {
			return $cache[ $file ];
		}
		$tam            = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$cache[ $file ] = ( is_array( $tam ) && isset( $tam[0] ) ) ? (int) $tam[0] : 0;
		return $cache[ $file ];
	}

	private static function own_srcset( $src, $width, $slot = 0 ) {
		if ( $width <= 0 ) {
			return null;
		}

		$candidates = array();
		foreach ( Signer::OFFERED_BUCKETS as $bucket ) {
			$delivered = min( $bucket, $width );
			if ( isset( $candidates[ $delivered ] ) ) {
				continue;
			}
			$url = self::delivery_url_for_src( $src, $bucket );
			if ( null === $url ) {
				return null;
			}
			$candidates[ $delivered ] = esc_url( $url ) . ' ' . $delivered . 'w';
		}

		if ( count( $candidates ) < 2 ) {
			return null;
		}

		$slot = $slot > 0 ? min( $slot, $width ) : min( $width, Signer::MAX_OFFERED_WIDTH );

		return array(
			'srcset' => implode( ', ', $candidates ),
			'sizes'  => sprintf( '(max-width: %1$dpx) 100vw, %1$dpx', $slot ),
		);
	}

	public static function delivery_url_for_src( $src, $width, $hero = false ) {
		$file = self::uploads_file( $src );
		if ( null === $file ) {
			return null;
		}

		$creds  = Settings_Store::credentials();
		$origin = self::origin_for_src( $src );
		if ( null === $origin || ! Signer::same_site( wp_parse_url( $origin, PHP_URL_HOST ), $creds['domain'] ) ) {
			return null;
		}
		if ( ! Settings_Store::is_served( $origin ) ) {
			return null;
		}

		$bytes = filesize( $file );

		return Signer::build_url(
			Settings_Store::cdn_host(),
			$creds['tenant_id'],
			$creds['license_key'],
			$creds['domain'],
			$origin,
			$width,
			is_int( $bytes ) ? $bytes : 0,
			$hero
		);
	}

	public static function origin_for_src( $src ) {
		static $memo = array();

		$shown = self::uploads_file( $src );
		if ( null === $shown ) {
			return null;
		}
		$file = self::source_file( $shown );
		if ( isset( $memo[ $file ] ) ) {
			return $memo[ $file ];
		}

		$uploads = wp_upload_dir();
		$url     = $uploads['baseurl'] . substr( $file, strlen( $uploads['basedir'] ) );

		$stamp         = filemtime( $file );
		$memo[ $file ] = $stamp ? add_query_arg( 'v', base_convert( (string) $stamp, 10, 36 ), $url ) : $url;
		return $memo[ $file ];
	}

	private static function source_file( $file ) {
		if ( preg_match( '/\.(?:webp|avif)$/i', $file ) ) {
			$stripped = substr( $file, 0, (int) strrpos( $file, '.' ) );
			if ( preg_match( '/\.(?:jpe?g|png|gif)$/i', $stripped ) && is_file( $stripped ) ) {
				$file = $stripped;
			}
		}

		if ( preg_match( '/-scaled\.[a-z0-9]{2,5}$/i', $file ) ) {
			return $file;
		}

		$bare = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]{2,5}$)/i', '', $file );
		if ( ! is_string( $bare ) ) {
			return $file;
		}

		$scaled = preg_replace( '/(?=\.[a-z0-9]{2,5}$)/i', '-scaled', $bare, 1 );
		if ( is_string( $scaled ) && is_file( $scaled ) ) {
			return $scaled;
		}
		return is_file( $bare ) ? $bare : $file;
	}

	private static function uploads_file( $src ) {
		$path = wp_parse_url( $src, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}
		$path = rawurldecode( $path );

		$uploads = wp_upload_dir();
		$base    = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( ! is_string( $base ) || '' === $base || 0 !== strpos( $path, $base ) ) {
			return null;
		}

		$relative = substr( $path, strlen( $base ) );

		if ( false !== strpos( $relative, '..' ) ) {
			return null;
		}

		$file = $uploads['basedir'] . $relative;
		return is_file( $file ) ? $file : null;
	}

	public static function delivery_url( $attachment_id, $width ) {
		$origin = self::origin_for( $attachment_id );
		if ( null === $origin ) {
			return null;
		}

		$creds = Settings_Store::credentials();
		$host  = wp_parse_url( $origin, PHP_URL_HOST );

		if ( ! Signer::same_site( $host, $creds['domain'] ) ) {
			return null;
		}

		if ( ! Settings_Store::is_served( $origin ) ) {
			return null;
		}

		return Signer::build_url(
			Settings_Store::cdn_host(),
			$creds['tenant_id'],
			$creds['license_key'],
			$creds['domain'],
			$origin,
			$width,
			self::wordpress_bytes( $attachment_id, $width )
		);
	}

	public static function origin_for( $attachment_id ) {
		$origin = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $origin ) || '' === $origin ) {
			return null;
		}
		$file  = get_attached_file( $attachment_id );
		$stamp = is_string( $file ) && '' !== $file && is_file( $file ) ? filemtime( $file ) : false;
		if ( ! $stamp ) {
			$modified = get_post_field( 'post_modified_gmt', $attachment_id, 'raw' );
			$stamp    = is_string( $modified ) ? strtotime( $modified . ' UTC' ) : false;
		}
		if ( ! $stamp ) {
			return $origin;
		}
		return add_query_arg( 'v', base_convert( (string) $stamp, 10, 36 ), $origin );
	}

	private static function wordpress_bytes( $attachment_id, $width ) {
		$src = wp_get_attachment_image_src( $attachment_id, array( (int) $width, 0 ) );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return 0;
		}

		$path = wp_parse_url( $src[0], PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return 0;
		}

		$uploads = wp_upload_dir();
		$base    = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( ! is_string( $base ) || 0 !== strpos( $path, $base ) ) {
			return 0;
		}

		$file = $uploads['basedir'] . substr( $path, strlen( $base ) );
		if ( ! file_exists( $file ) ) {
			return 0;
		}
		$size = filesize( $file );
		return is_int( $size ) ? $size : 0;
	}

	public static function filter_attributes( $attr, $attachment, $size ) {
		if ( ! self::should_rewrite() || empty( $attr['src'] ) ) {
			return $attr;
		}
		if ( false !== strpos( $attr['src'], self::MARKER ) ) {
			return $attr;
		}

		$width = self::width_from_size( $attachment->ID, $size, $attr );
		$url   = self::delivery_url( $attachment->ID, $width );
		if ( null === $url ) {
			return $attr;
		}

		$fallback              = $attr['src'];
		$attr['onerror']       = "this.onerror=null;this.srcset='';this.src='" . esc_js( $fallback ) . "';";
		$attr['src']           = $url;
		$attr['data-nimbocdn'] = '1';

		return $attr;
	}

	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! self::should_rewrite() || ! is_array( $sources ) || empty( $sources ) ) {
			return $sources;
		}

		$original_width = isset( $image_meta['width'] ) ? (int) $image_meta['width'] : 0;
		if ( $original_width <= 0 ) {
			return $sources;
		}

		$largest_offered = 0;
		foreach ( array_keys( $sources ) as $candidate_width ) {
			$largest_offered = max( $largest_offered, (int) $candidate_width );
		}

		$rewritten = array();
		foreach ( Signer::OFFERED_BUCKETS as $bucket ) {
			$delivered = min( $bucket, $original_width );

			if ( isset( $rewritten[ $delivered ] ) ) {
				continue;
			}

			if ( $largest_offered > 0 && $rewritten && $delivered > $largest_offered ) {
				break;
			}

			$url = self::delivery_url( $attachment_id, $bucket );
			if ( null === $url ) {
				return $sources;
			}

			$rewritten[ $delivered ] = array(
				'url'        => $url,
				'descriptor' => 'w',
				'value'      => $delivered,
			);
		}

		return $rewritten;
	}

	private static function width_from_size( $attachment_id, $size, $attr ) {
		if ( is_array( $size ) && isset( $size[0] ) ) {
			return (int) $size[0];
		}
		if ( ! empty( $attr['width'] ) ) {
			return (int) $attr['width'];
		}
		$src = wp_get_attachment_image_src( $attachment_id, $size );
		return ( is_array( $src ) && isset( $src[1] ) ) ? (int) $src[1] : 0;
	}
}
