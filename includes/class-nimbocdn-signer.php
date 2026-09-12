<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Signer {

	const WIDTH_BUCKETS = array( 400, 800, 1200, 2048 );

	const OFFERED_BUCKETS = array( 400, 800, 1200 );

	const MAX_OFFERED_WIDTH = 1200;

	const HERO_WIDTH = 2048;

	public static function bucket_width( $requested, $hero = false ) {
		$width = (int) $requested;
		if ( $width <= 0 ) {
			return self::WIDTH_BUCKETS[0];
		}
		$table = $hero ? self::WIDTH_BUCKETS : self::OFFERED_BUCKETS;
		foreach ( $table as $bucket ) {
			if ( $width <= $bucket ) {
				return $bucket;
			}
		}
		return $hero ? self::HERO_WIDTH : self::MAX_OFFERED_WIDTH;
	}

	public static function site_key( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( false !== strpos( $host, '://' ) ) {
			$parsed = wp_parse_url( $host, PHP_URL_HOST );
			$host   = is_string( $parsed ) ? $parsed : '';
		}
		$host = preg_replace( '/:\d+$/', '', $host );
		return (string) preg_replace( '/^www\./', '', $host );
	}

	public static function same_site( $a, $b ) {
		$key = self::site_key( $a );
		return '' !== $key && self::site_key( $b ) === $key;
	}

	public static function sign( $license_key, $origin_domain, $origin_url ) {
		$mac = hash_hmac( 'sha256', $origin_domain . ':' . $origin_url, $license_key );
		return substr( $mac, 0, 32 );
	}

	public static function build_url( $cdn_host, $tenant_id, $license_key, $origin_domain, $origin_url, $width, $wp_bytes = 0, $hero = false ) {
		$encoded   = rawurlencode( $origin_url );
		$signature = self::sign( $license_key, $origin_domain, $origin_url );
		$bucket    = self::bucket_width( $width, $hero );

		$url = sprintf(
			'https://%s/i/%s/%d/%s/%s',
			$cdn_host,
			rawurlencode( $tenant_id ),
			$bucket,
			$signature,
			$encoded
		);

		if ( $wp_bytes > 0 ) {
			$url .= '?w=' . (int) $wp_bytes;
		}

		return $url;
	}
}
