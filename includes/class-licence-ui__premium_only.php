<?php
/**
 * Licence activation on BanzaiPlay's own screens (Pro).
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
 * Lets an administrator activate or check the Pro licence without leaving
 * BanzaiPlay.
 *
 * Freemius only puts its "Activate License" link on the Plugins screen, and
 * its Account page exists only after the opt-in — someone who skipped the
 * opt-in had no way in from BanzaiPlay at all. This reuses Freemius's own
 * activation dialog (forms/license-activation.php), which opens from any
 * element with the classes `activate-license-trigger {unique affix}`; the
 * AJAX handler behind it is registered on every admin page already. So the
 * activation itself — key check, opt-in consent, sync — stays Freemius's.
 *
 * - Header: "Activate licence" while unlicensed; "Pro licence active", linking
 *   to the Account page, once licensed.
 * - Menu: an "Activate Licence" item while unlicensed, opening the dialog on
 *   the All Games screen.
 */
final class Licence_Ui {

	/**
	 * Query arg that opens the dialog on arrival.
	 */
	const OPEN_ARG = 'bzpl_activate';

	/**
	 * Hook in.
	 */
	public function register() {
		add_action( 'bzpl/header_actions', array( $this, 'render_status' ) );
		// After Admin::add_pages() (10), so the item sits below All Games and Add New.
		add_action( 'admin_menu', array( $this, 'add_menu_item' ), 20 );
	}

	/**
	 * Whether to offer activation to the current user: Freemius only lets
	 * administrators activate, and there must be nothing active already.
	 *
	 * @return bool
	 */
	private static function can_activate() {
		return current_user_can( 'manage_options' ) && ! bzpl_has_valid_license();
	}

	/**
	 * URL that opens the activation dialog on the All Games screen.
	 *
	 * @return string
	 */
	public static function activate_url() {
		return add_query_arg( self::OPEN_ARG, '1', Admin::list_url() );
	}

	/**
	 * "Activate Licence" under the BanzaiPlay menu, while unlicensed.
	 */
	public function add_menu_item() {
		if ( ! self::can_activate() ) {
			return;
		}

		// A menu slug containing ".php" is used as the link itself.
		add_submenu_page(
			Admin::PAGE,
			__( 'Activate Licence', 'banzaiplay' ),
			__( 'Activate Licence', 'banzaiplay' ),
			'manage_options',
			'admin.php?page=' . Admin::PAGE . '&' . self::OPEN_ARG . '=1'
		);
	}

	/**
	 * Licence status in the brand bar of every BanzaiPlay screen.
	 */
	public function render_status() {
		$fs = banzaiplay_fs();

		if ( bzpl_has_valid_license() ) {
			$account = $fs->is_registered() ? $fs->get_account_url() : '';

			printf(
				'<%1$s class="bzpl-licence is-active"%2$s><span class="dashicons dashicons-star-filled" aria-hidden="true"></span>%3$s</%1$s>',
				'' !== $account ? 'a' : 'span',
				'' !== $account ? ' href="' . esc_url( $account ) . '" title="' . esc_attr__( 'Manage your licence', 'banzaiplay' ) . '"' : '',
				esc_html__( 'Pro licence active', 'banzaiplay' )
			);

			return;
		}

		if ( ! self::can_activate() ) {
			return;
		}

		printf(
			'<button type="button" class="button bzpl-licence activate-license-trigger %1$s" data-bzpl-activate><span class="dashicons dashicons-admin-network" aria-hidden="true"></span>%2$s</button>',
			esc_attr( $fs->get_unique_affix() ),
			esc_html__( 'Activate licence', 'banzaiplay' )
		);

		// Freemius's dialog, printed only where the button is.
		add_action( 'admin_footer', array( $fs, '_add_license_activation_dialog_box' ) );
	}
}
