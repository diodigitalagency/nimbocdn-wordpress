<?php
/**
 * Plugin Name: NimboCDN
 * Plugin URI: https://nimbocdn.net
 * Description: Serves your images resized and in modern formats from our global network, without touching your originals. Deactivate and everything goes back exactly as it was.
 * Version:           0.5.6
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: DIO Digital
 * Author URI: https://www.diodigital.agency
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nimbocdn
 * Domain Path: /languages
 *
 * @package NimboCDN
 */


namespace NimboCDN;

defined( 'ABSPATH' ) || exit;


const VERSION = '0.5.6';

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

add_action(
	'init',
	static function () {
		add_filter( 'plugin_locale', __NAMESPACE__ . '\\fallback_locale', 10, 2 );
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- the catalogues ship inside /languages; WordPress's just-in-time loading only covers wordpress.org language packs.
		load_plugin_textdomain( 'nimbocdn', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		if ( ! is_textdomain_loaded( 'nimbocdn' ) ) {
			$mofile = NIMBOCDN_DIR . 'languages/nimbocdn-'
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- `plugin_locale` is a core filter, not one of ours.
				. apply_filters( 'plugin_locale', determine_locale(), 'nimbocdn' ) . '.mo';
			if ( is_readable( $mofile ) ) {
				load_textdomain( 'nimbocdn', $mofile ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_textdomainFound -- see above.
			}
		}
	}
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

function fallback_locale( $locale, $domain ) {
	if ( 'nimbocdn' !== $domain || ! is_string( $locale ) ) {
		return $locale;
	}
	$own = __DIR__ . '/languages/nimbocdn-' . $locale . '.mo';
	if ( file_exists( $own ) ) {
		return $locale;
	}
	if ( 0 === strpos( $locale, 'es_' ) ) {
		return 'es_ES';
	}
	if ( 0 === strpos( $locale, 'pt_' ) ) {
		return 'pt_BR';
	}
	return $locale;
}
