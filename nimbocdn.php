<?php
/**
 * Plugin Name: NimboCDN – Image Optimization & Image CDN | Convert WebP & AVIF
 * Plugin URI: https://nimbocdn.net
 * Description: Serves your images resized and in modern formats from our global network, without touching your originals. Deactivate and everything goes back exactly as it was.
 * Version:           0.5.12
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: DIO Digital
 * Author URI: https://www.diodigital.agency
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nimbocdn
 *
 * @package NimboCDN
 */


namespace NimboCDN;

defined( 'ABSPATH' ) || exit;


const VERSION = '0.5.12';

define( 'NIMBOCDN_FILE', __FILE__ );
define( 'NIMBOCDN_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$file     = NIMBOCDN_DIR . 'includes/class-nimbocdn-' .
			strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

add_filter(
	'lang_dir_for_domain',
	static function ( $path, $domain, $locale ) {
		if ( 'nimbocdn' !== $domain || ! is_dir( __DIR__ . '/languages' ) ) {
			return $path;
		}
		$catalog = rtrim( (string) $path, '/' ) . '/nimbocdn-' . $locale;
		if ( is_readable( $catalog . '.mo' ) || is_readable( $catalog . '.l10n.php' ) ) {
			return $path;
		}
		return __DIR__ . '/languages/';
	},
	10,
	3
);

add_action(
	'plugins_loaded',
	static function () {
		Health::init();
		Challenge::init();
		Home::init();
		Rewriter::init();
		if ( is_admin() ) {
			Settings::init();
		}
	}
);

register_activation_hook( __FILE__, array( Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Activation::class, 'deactivate' ) );
