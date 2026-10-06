<?php
/**
 * Site-wide settings (Pro).
 *
 * This file is not in the free build: Freemius leaves out files whose names
 * contain __premium_only, and Plugin::run() loads it inside an
 * is__premium_only() block, which Freemius strips too.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * The BanzaiPlay → Settings screen and the option behind it: the loading
 * screen every game gets unless it sets its own, how a page with several
 * games behaves, and whether plays are recorded.
 *
 * Like every Pro module, what is saved keeps working if the licence lapses;
 * changing it needs a licence.
 */
final class Settings {

	const OPTION = 'banzaiplay_settings';

	const PAGE = 'banzaiplay-settings';

	const SAVE_ACTION = 'banzaiplay_save_settings';

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * @var Admin|null
	 */
	private $admin = null;

	/**
	 * Every setting and its default.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Loading screen: an attachment ID and a #rrggbb colour, '' for ours.
			'logo'          => 0,
			'color'         => '',
			// Several games on a page.
			'one_at_a_time' => false,
			'unload_hidden' => false,
			// Play statistics.
			'record'        => true,
			'keep_days'     => 365,
		);
	}

	/**
	 * Hook in.
	 */
	public function register() {
		// After Analytics (10), so the menu reads All Games, Add New, Analytics, Settings.
		add_action( 'bzpl/admin_menu', array( $this, 'add_page' ), 20 );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->settings ) {
			$stored         = get_option( self::OPTION, array() );
			$this->settings = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return $this->settings;
	}

	/**
	 * One setting.
	 *
	 * @param string $key From defaults().
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * A #rrggbb colour, or ''.
	 *
	 * @param mixed $value Input.
	 * @return string
	 */
	public static function color( $value ) {
		$color = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : '';

		if ( ! $color ) {
			return '';
		}

		// #abc → #aabbcc, so every stored colour has one shape.
		if ( 4 === strlen( $color ) ) {
			$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}

		return strtolower( $color );
	}

	/**
	 * An image attachment ID, or 0.
	 *
	 * @param mixed $value Input.
	 * @return int
	 */
	public static function image( $value ) {
		$id = absint( $value );

		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * The Settings screen under the BanzaiPlay menu.
	 *
	 * @param Admin $admin The admin screens.
	 */
	public function add_page( Admin $admin ) {
		$this->admin = $admin;
		$admin->add_screen( __( 'BanzaiPlay Settings', 'banzaiplay' ), __( 'Settings', 'banzaiplay' ), self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * URL of the Settings screen.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Print the screen.
	 */
	public function render() {
		$this->admin->render_screen(
			BZPL_PLUGIN_PATH . 'templates/admin-settings__premium_only.php',
			array(
				'settings' => $this->all(),
				'licensed' => bzpl_has_valid_license(),
			)
		);
	}

	/**
	 * Save the screen.
	 */
	public function handle_save() {
		if ( ! Admin::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage BanzaiPlay games.', 'banzaiplay' ), 403 );
		}

		check_admin_referer( self::SAVE_ACTION );

		if ( ! bzpl_has_valid_license() ) {
			Admin::notice( 'error', __( 'Changing these settings needs an active BanzaiPlay Pro licence. Nothing was changed.', 'banzaiplay' ) );
			wp_safe_redirect( self::url() );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$keep = isset( $_POST['keep_days'] ) ? absint( wp_unslash( $_POST['keep_days'] ) ) : 365;

		$settings = array(
			'logo'          => self::image( isset( $_POST['logo'] ) ? wp_unslash( $_POST['logo'] ) : 0 ),
			'color'         => self::color( isset( $_POST['color'] ) ? wp_unslash( $_POST['color'] ) : '' ),
			'one_at_a_time' => ! empty( $_POST['one_at_a_time'] ),
			'unload_hidden' => ! empty( $_POST['unload_hidden'] ),
			'record'        => ! empty( $_POST['record'] ),
			// 0 keeps everything; otherwise at least a week.
			'keep_days'     => 0 === $keep ? 0 : min( 3650, max( 7, $keep ) ),
		);
		// phpcs:enable

		update_option( self::OPTION, $settings, true );
		$this->settings = null;

		Admin::notice( 'success', __( 'Settings saved.', 'banzaiplay' ) );
		wp_safe_redirect( self::url() );
		exit;
	}
}
