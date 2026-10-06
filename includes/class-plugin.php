<?php
/**
 * Plugin orchestration.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the pieces together.
 *
 * Construction has no side effects — everything happens in run() — so the
 * plugin can be instantiated in a test without registering hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Game_Manager
	 */
	private $games;

	/**
	 * @var Frame
	 */
	private $frame;

	/**
	 * @var Embed
	 */
	private $embed;

	/**
	 * @var Admin
	 */
	private $admin;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->games = new Game_Manager();
		$this->frame = new Frame( $this->games );
		$this->embed = new Embed( $this->games, $this->frame );
		$this->admin = new Admin( $this->games, $this->embed, new Uploader( $this->games ), new Engine_Detector(), new Path_Rewriter() );
	}

	/**
	 * Register everything.
	 *
	 * @return bool Whether the plugin started.
	 */
	public function run() {
		$this->frame->register();
		$this->embed->register();
		( new Shortcode( $this->embed ) )->register();
		( new Block( $this->embed, $this->games ) )->register();
		( new Asset_Redirect( $this->games ) )->register();

		if ( is_admin() ) {
			$this->admin->register();
		}

		$settings = new Settings();
		$plays    = new Plays( $this->games, $settings );

		$settings->register();
		( new Player_Extras( $settings, $plays ) )->register();
		( new Branding( $settings ) )->register();
		( new Gallery( $this->games, $this->embed, $settings ) )->register();
		$plays->register();
		( new Data_Bridge( $this->games ) )->register();
		( new Game_Events() )->register();

		if ( is_admin() ) {
			( new Analytics( $this->games, $plays ) )->register();
		}

		/**
		 * Fires once the plugin is active. Add-ons hook here.
		 *
		 * @param Plugin $plugin The running plugin.
		 */
		do_action( 'bzpl/init', $this );

		return true;
	}

	/**
	 * The game store, for add-ons.
	 *
	 * @return Game_Manager
	 */
	public function games() {
		return $this->games;
	}
}
