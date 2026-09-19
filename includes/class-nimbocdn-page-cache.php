<?php

namespace NimboCDN;

defined( 'ABSPATH' ) || exit;

class Page_Cache {

	const OPTION = 'nimbocdn_page_cache_purge';

	public static function purge() {
		static $done = false;
		if ( $done ) {
			return array();
		}
		if ( ! did_action( 'init' ) ) {
			add_action( 'init', array( __CLASS__, 'purge' ), 99 );
			return array();
		}
		$done    = true;
		$reached = array();
		foreach ( self::steps() as $name => $step ) {
			try {
				if ( $step() ) {
					$reached[] = $name;
				}
			} catch ( \Throwable $e ) {
				$reached[] = $name . ':error';
			}
		}
		update_option(
			self::OPTION,
			array(
				'at'      => time(),
				'reached' => $reached,
			),
			false
		);
		return $reached;
	}

	private static function steps() {
		return array(
			'wp-rocket'          => static function () {
				if ( ! function_exists( 'rocket_clean_domain' ) ) {
					return false;
				}
				rocket_clean_domain();
				return true;
			},
			'litespeed'          => static function () {
				if ( ! is_callable( array( 'LiteSpeed\\Purge', 'purge_all' ) ) ) {
					return false;
				}
				call_user_func( array( 'LiteSpeed\\Purge', 'purge_all' ), 'NimboCDN' );
				return true;
			},
			'w3-total-cache'     => static function () {
				if ( ! function_exists( 'w3tc_flush_posts' ) ) {
					return false;
				}
				w3tc_flush_posts();
				return true;
			},
			'wp-super-cache'     => static function () {
				if ( ! function_exists( 'wp_cache_clear_cache' ) ) {
					return false;
				}
				wp_cache_clear_cache( is_multisite() ? get_current_blog_id() : 0 );
				return true;
			},
			'wp-fastest-cache'   => static function () {
				if ( ! function_exists( 'wpfc_clear_all_cache' ) ) {
					return false;
				}
				wpfc_clear_all_cache();
				return true;
			},
			'siteground'         => static function () {
				if ( ! function_exists( 'sg_cachepress_purge_cache' ) ) {
					return false;
				}
				sg_cachepress_purge_cache();
				return true;
			},
			'cache-enabler'      => static function () {
				if ( ! is_callable( array( 'Cache_Enabler', 'clear_complete_cache' ) ) ) {
					return false;
				}
				call_user_func( array( 'Cache_Enabler', 'clear_complete_cache' ) );
				return true;
			},
			'hummingbird'        => static function () {
				if ( ! is_callable( array( 'Hummingbird\\Core\\Utils', 'get_module' ) ) ) {
					return false;
				}
				$module = call_user_func( array( 'Hummingbird\\Core\\Utils', 'get_module' ), 'page_cache' );
				if ( ! is_object( $module ) || ! is_callable( array( $module, 'clear_cache_action' ) ) ) {
					return false;
				}
				$module->clear_cache_action();
				return true;
			},
			'wp-optimize'        => static function () {
				if ( ! function_exists( 'WP_Optimize' ) ) {
					return false;
				}
				$cache = WP_Optimize()->get_page_cache();
				if ( ! is_object( $cache ) || ! is_callable( array( $cache, 'purge' ) ) ) {
					return false;
				}
				$cache->purge();
				return true;
			},
			'comet-cache'        => static function () {
				if ( ! is_callable( array( 'comet_cache', 'clear' ) ) ) {
					return false;
				}
				call_user_func( array( 'comet_cache', 'clear' ) );
				return true;
			},
			'swift-performance' => static function () {
				if ( ! is_callable( array( 'Swift_Performance_Cache', 'clear_all_cache' ) ) ) {
					return false;
				}
				call_user_func( array( 'Swift_Performance_Cache', 'clear_all_cache' ) );
				return true;
			},

			'proxy-cache-purge'  => static function () {
				if ( ! is_callable( array( 'VarnishPurger', 'purge_url' ) ) || ! is_callable( array( 'VarnishPurger', 'the_home_url' ) ) ) {
					return false;
				}
				call_user_func( array( 'VarnishPurger', 'purge_url' ), call_user_func( array( 'VarnishPurger', 'the_home_url' ) ) . '/?vhp-regex' );
				return true;
			},
			'kinsta'             => static function () {
				$kinsta = isset( $GLOBALS['kinsta_cache'] ) ? $GLOBALS['kinsta_cache'] : null;
				$purger = is_object( $kinsta ) && isset( $kinsta->kinsta_cache_purge ) ? $kinsta->kinsta_cache_purge : null;
				if ( ! is_object( $purger ) || ! is_callable( array( $purger, 'purge_complete_site_cache' ) ) ) {
					return false;
				}
				$purger->purge_complete_site_cache();
				return true;
			},
			'wp-engine'          => static function () {
				if ( ! is_callable( array( 'WpeCommon', 'purge_varnish_cache' ) ) ) {
					return false;
				}
				call_user_func( array( 'WpeCommon', 'purge_varnish_cache' ) );
				return true;
			},
			'pantheon'           => static function () {
				if ( ! function_exists( 'pantheon_wp_clear_edge_all' ) ) {
					return false;
				}
				pantheon_wp_clear_edge_all();
				return true;
			},

			'godaddy'            => static function () {
				if ( ! is_callable( array( '\WPaaS\Cache', 'has_ban' ) ) || ! is_callable( array( '\WPaaS\Cache', 'ban' ) ) ) {
					return false;
				}
				if ( ! call_user_func( array( '\WPaaS\Cache', 'has_ban' ) ) ) {
					add_action( 'shutdown', array( '\WPaaS\Cache', 'ban' ), PHP_INT_MAX );
				}
				return true;
			},
			'pressable'          => static function () {
				if ( ! is_callable( array( 'Edge_Cache_Plugin', 'get_instance' ) ) ) {
					return false;
				}
				$edge = call_user_func( array( 'Edge_Cache_Plugin', 'get_instance' ) );
				if ( ! is_object( $edge ) || ! is_callable( array( $edge, 'purge_domain_now' ) ) ) {
					return false;
				}
				$edge->purge_domain_now( 'nimbocdn' );
				return true;
			},
			'cloudflare'         => static function () {
				$hooks = isset( $GLOBALS['cloudflareHooks'] ) ? $GLOBALS['cloudflareHooks'] : null;
				if ( ! is_object( $hooks ) || ! is_a( $hooks, 'Cloudflare\\APO\\WordPress\\Hooks' ) || ! is_callable( array( $hooks, 'purgeCacheEverything' ) ) ) {
					return false;
				}
				$hooks->purgeCacheEverything();
				return true;
			},
		);
	}
}
