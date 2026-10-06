<?php
/**
 * Plugin Name: BanzaiPlay — Game Embed for WordPress
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

/*
 * The free build ships to wp.org as `banzaiplay/`; the paid one downloads from
 * Freemius as `banzaiplay-premium/`. WordPress lets both be active at once —
 * typically for a few seconds while someone who has just paid installs the
 * premium copy over the free one.
 *
 * Freemius deactivates one of them, and set_basename() is how it learns which
 * file is which. The second copy to load must not run the rest of this file:
 * everything in the `else` declares bare functions, and declaring any of them
 * twice in one request is a fatal error.
 */
if ( function_exists( 'banzaiplay_fs' ) ) {
	banzaiplay_fs()->set_basename( true, __FILE__ );
} else {
	/**
	 * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
	 * `function_exists` CALL ABOVE TO PROPERLY WORK.
	 */
	if ( ! function_exists( 'banzaiplay_fs' ) ) {
		// Create a helper function for easy SDK access.
		function banzaiplay_fs() {
			global $banzaiplay_fs;

			if ( ! isset( $banzaiplay_fs ) ) {
				// Include Freemius SDK.
				require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

				$banzaiplay_fs = fs_dynamic_init( array(
					'id'                  => '40781',
					'slug'                => 'banzaiplay',
					'type'                => 'plugin',
					'public_key'          => 'pk_ecf4cdcd3d33e01c3e32630ed14e1',
					'is_premium'          => true,
					'premium_suffix'      => 'Professional',
					// If your plugin is a serviceware, set this option to false.
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'is_org_compliant'    => true,
					// Automatically removed in the free version. If you're not using the
					// auto-generated free version, delete this line before uploading to wp.org.
					'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
					'menu'                => array(
						'slug'    => 'banzaiplay',
						'support' => false,
					),
				) );
			}

			return $banzaiplay_fs;
		}

		// Init Freemius.
		banzaiplay_fs();
		// Signal that SDK was initiated.
		do_action( 'banzaiplay_fs_loaded' );
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
	 * Whether this site's license unlocks the pro features.
	 *
	 * The single gate check — every pro feature goes through here. A plain
	 * function so it is callable before the autoloader has loaded License.
	 *
	 * @return bool
	 */
	function bzpl_has_valid_license() {
		return \BanzaiPlay\License::is_valid();
	}

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
	 * On Freemius' after_uninstall rather than an uninstall.php or
	 * register_uninstall_hook(): WordPress keeps one uninstall callback per
	 * plugin, and Freemius needs it to report uninstalls. The statistics table
	 * is dropped here too, so a site that downgraded to the free build is
	 * still cleaned up.
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
	banzaiplay_fs()->add_action( 'after_uninstall', 'bzpl_uninstall' );

	/**
	 * Stop the daily clean-up of old play statistics (Pro schedules it again
	 * when it next runs). Games and statistics are kept.
	 */
	function bzpl_deactivate() {
		wp_clear_scheduled_hook( 'bzpl_prune_plays' );
	}
	register_deactivation_hook( __FILE__, 'bzpl_deactivate' );
}
