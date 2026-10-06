<?php
/**
 * Plugin Name: BanzaiPlay — Game Embed for WordPress
 * Plugin URI:  https://github.com/JoshRobs/BanzaiPlay
 * Description: Embed HTML5 and WebGL games — Unity, Godot, Phaser, Construct 3, PlayCanvas, PixiJS or any HTML5 build — in any page or post. Upload the build as a zip; BanzaiPlay adds the loading screen, keyboard focus handling, responsive sizing and fullscreen.
 * Version:     0.1.0
 * Author:      BanzaiPlay
 * Text Domain: banzaiplay
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package BanzaiPlay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BZPL_VERSION', '0.1.0' );
define( 'BZPL_PLUGIN_FILE', __FILE__ );
define( 'BZPL_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'BZPL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Map BanzaiPlay\Foo_Bar to includes/class-foo-bar.php.
 *
 * @param string $class Fully qualified class name.
 */
function bzpl_autoload( $class ) {
	$prefix = 'BanzaiPlay\\';

	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$name = str_replace( '_', '-', strtolower( substr( $class, strlen( $prefix ) ) ) );
	$file = BZPL_PLUGIN_PATH . 'includes/class-' . $name . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'bzpl_autoload' );

/**
 * Boot once all plugins are loaded.
 */
function bzpl_bootstrap() {
	\BanzaiPlay\Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'bzpl_bootstrap' );

/**
 * Remove every game record, every uploaded build, the settings and the
 * play statistics when the plugin is deleted — never on deactivation,
 * which must not cost anyone their games.
 *
 * WordPress loads this file before calling the hook, so the autoloader is
 * there for it.
 */
function bzpl_uninstall() {
	global $wpdb;

	delete_option( \BanzaiPlay\Game_Manager::OPTION );
	delete_option( 'banzaiplay_settings' );
	delete_option( 'banzaiplay_db_version' );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}banzaiplay_plays" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, on uninstall.
	wp_clear_scheduled_hook( 'bzpl_prune_plays' );
	\BanzaiPlay\Filesystem::delete( ( new \BanzaiPlay\Game_Manager() )->base_dir() );
}

/**
 * Record the uninstall hook. Done on activation, as WordPress recommends —
 * it is stored in an option, so it need not be registered on every request.
 */
function bzpl_activate() {
	register_uninstall_hook( BZPL_PLUGIN_FILE, 'bzpl_uninstall' );
}
register_activation_hook( __FILE__, 'bzpl_activate' );

/**
 * Stop the daily clean-up of old play statistics (it is scheduled again
 * when it next runs). Games and statistics are kept.
 */
function bzpl_deactivate() {
	wp_clear_scheduled_hook( 'bzpl_prune_plays' );
}
register_deactivation_hook( __FILE__, 'bzpl_deactivate' );
