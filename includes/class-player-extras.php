<?php
/**
 * Player extras: scripts and styles.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the player's extras wherever the player loads, and the scripts the
 * edit and Settings screens' cards need.
 *
 * player-extras.js is a dependency of frontend.js rather than the other
 * way round, so its listeners for the player's events are in place before
 * frontend.js starts the first player — a game that starts with the page
 * starts while frontend.js runs. It handles the data handed to the game,
 * events from it, the results screen, play statistics, and pages with
 * several games.
 */
final class Player_Extras {

	const HANDLE = 'banzaiplay-extras';

	const ADMIN_HANDLE = 'bzpl-admin-extras';

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Plays
	 */
	private $plays;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Site settings.
	 * @param Plays    $plays    Play statistics.
	 */
	public function __construct( Settings $settings, Plays $plays ) {
		$this->settings = $settings;
		$this->plays    = $plays;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		// Before Embed::register_assets() (10), which reads the dependency filter.
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_filter( 'bzpl/frontend_script_deps', array( $this, 'deps' ) );
		add_action( 'bzpl/enqueue_assets', array( $this, 'enqueue' ) );
		add_action( 'bzpl/admin_enqueue', array( $this, 'enqueue_admin' ) );
	}

	/**
	 * Register the player's extras script and style.
	 */
	public function register_assets() {
		wp_register_script(
			self::HANDLE,
			BZPL_PLUGIN_URL . 'assets/js/player-extras.js',
			array(),
			BZPL_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'ajax'         => admin_url( 'admin-ajax.php', 'relative' ),
			'action'       => Plays::ACTION,
			'record'       => $this->plays->recording(),
			'oneAtATime'   => (bool) $this->settings->get( 'one_at_a_time' ),
			'unloadHidden' => (bool) $this->settings->get( 'unload_hidden' ),
			'i18n'         => array(
				'waiting'   => __( 'Waiting for the other game to load…', 'banzaiplay' ),
				'complete'  => __( 'Well played!', 'banzaiplay' ),
				'score'     => __( 'Score', 'banzaiplay' ),
				'time'      => __( 'Time', 'banzaiplay' ),
				'playAgain' => __( 'Play again', 'banzaiplay' ),
				'close'     => __( 'Keep playing', 'banzaiplay' ),
				'closed'    => __( 'The game was closed while out of view.', 'banzaiplay' ),
			),
		);

		wp_add_inline_script( self::HANDLE, 'window.banzaiPlayExtras=' . wp_json_encode( $config ) . ';', 'before' );

		wp_register_style( self::HANDLE, BZPL_PLUGIN_URL . 'assets/css/player-extras.css', array( Embed::HANDLE ), BZPL_VERSION );
	}

	/**
	 * Make frontend.js wait for the extras script.
	 *
	 * @param string[] $deps Handles.
	 * @return string[]
	 */
	public function deps( $deps ) {
		$deps[] = self::HANDLE;

		return $deps;
	}

	/**
	 * The extras stylesheet, with the player's. (The script comes as a
	 * dependency of frontend.js.)
	 */
	public function enqueue() {
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * The media library and colour picker for the loading screen, gallery and
	 * Data Bridge cards, on the edit and Settings screens.
	 *
	 * @param string $hook Screen hook suffix.
	 */
	public function enqueue_admin( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( ! Admin::is_edit_screen() && Settings::PAGE !== $page ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( self::ADMIN_HANDLE, BZPL_PLUGIN_URL . 'assets/js/admin-extras.js', array( 'wp-color-picker', 'wp-i18n' ), BZPL_VERSION, true );
		wp_set_script_translations( self::ADMIN_HANDLE, 'banzaiplay' );
	}
}
