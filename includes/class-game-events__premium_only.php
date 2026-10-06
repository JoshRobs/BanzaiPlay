<?php
/**
 * Game-to-WordPress communication (Pro).
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
 * What happens when a game calls BanzaiPlay.emit( name, data ):
 *
 * - on the page, pro__premium_only.js fires `banzaiplay:event` on the
 *   player ({ name, data }), for the site's own scripts;
 * - on the server, Plays fires `bzpl/game_event` and records "complete" and
 *   "score" for Analytics;
 * - with the results screen on, "complete" shows the score and time over
 *   the game, with Play again.
 *
 * This class is the per-game part: the results screen switch, and the card
 * that documents the API. Stored on the game as `events`: { results }.
 */
final class Game_Events {

	/**
	 * Hook in.
	 */
	public function register() {
		add_filter( 'bzpl/player_config', array( $this, 'player_config' ), 10, 2 );
		add_action( 'bzpl/edit_cards', array( $this, 'render_card' ), 40 );
		add_filter( 'bzpl/save_game', array( $this, 'save' ) );
	}

	/**
	 * Whether "complete" shows the results screen.
	 *
	 * @param array $game Record.
	 * @return bool
	 */
	public static function shows_results( array $game ) {
		return isset( $game['events']['results'] ) && $game['events']['results'];
	}

	/**
	 * Tell the player.
	 *
	 * @param array $config Player config.
	 * @param array $game   Record.
	 * @return array
	 */
	public function player_config( $config, $game ) {
		if ( self::shows_results( $game ) ) {
			$config['results'] = true;
		}

		return $config;
	}

	/**
	 * The Game events card.
	 *
	 * @param array $game Record.
	 */
	public function render_card( $game ) {
		$licensed = bzpl_has_valid_license();
		$results  = self::shows_results( $game );

		include BZPL_PLUGIN_PATH . 'templates/admin-events__premium_only.php';
	}

	/**
	 * Read the card into the record. Runs inside Admin::handle_save(), after
	 * its capability and nonce checks.
	 *
	 * @param array $game Record.
	 * @return array
	 */
	public function save( $game ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in Admin::handle_save().
		if ( ! isset( $_POST['bzpl_events'] ) || ! bzpl_has_valid_license() ) {
			return $game;
		}

		$game['events'] = array( 'results' => ! empty( $_POST['events_results'] ) );
		// phpcs:enable

		return $game;
	}
}
