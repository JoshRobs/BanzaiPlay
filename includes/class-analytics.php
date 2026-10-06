<?php
/**
 * Analytics dashboard.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * BanzaiPlay → Analytics: plays, time played, completion rate and devices
 * per game, from the plays table (see Plays). Server-rendered, chart
 * included, so it needs no script and no service outside the site.
 */
final class Analytics {

	const PAGE = 'banzaiplay-analytics';

	/**
	 * Periods offered, in days; 0 is all time.
	 */
	const PERIODS = array( 7, 30, 90, 365, 0 );

	/**
	 * @var Game_Manager
	 */
	private $games;

	/**
	 * @var Plays
	 */
	private $plays;

	/**
	 * @var Admin|null
	 */
	private $admin = null;

	/**
	 * Constructor.
	 *
	 * @param Game_Manager $games Game store.
	 * @param Plays        $plays Play statistics.
	 */
	public function __construct( Game_Manager $games, Plays $plays ) {
		$this->games = $games;
		$this->plays = $plays;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		add_action( 'bzpl/admin_menu', array( $this, 'add_page' ), 10 );
	}

	/**
	 * The Analytics screen under the BanzaiPlay menu.
	 *
	 * @param Admin $admin The admin screens.
	 */
	public function add_page( Admin $admin ) {
		$this->admin = $admin;
		$admin->add_screen( __( 'BanzaiPlay Analytics', 'banzaiplay' ), __( 'Analytics', 'banzaiplay' ), self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param string $game Game slug, or '' for every game.
	 * @param int    $days Period.
	 * @return string
	 */
	public static function url( $game = '', $days = 30 ) {
		$args = array( 'page' => self::PAGE );

		if ( '' !== $game ) {
			$args['game'] = $game;
		}

		if ( 30 !== $days ) {
			$args['days'] = $days;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Seconds as 0:42, 12:05 or 1:02:09.
	 *
	 * @param float $seconds Seconds.
	 * @return string
	 */
	public static function duration( $seconds ) {
		$seconds = (int) round( $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$rest    = $seconds % 60;

		return $hours ? sprintf( '%d:%02d:%02d', $hours, $minutes, $rest ) : sprintf( '%d:%02d', $minutes, $rest );
	}

	/**
	 * A share as "42%", or "—" when there is nothing to share.
	 *
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 * @return string
	 */
	public static function percent( $part, $whole ) {
		return $whole > 0 ? number_format_i18n( 100 * $part / $whole ) . '%' : '—';
	}

	/**
	 * Print the screen.
	 */
	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$days = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 30;
		$slug = isset( $_GET['game'] ) ? Game_Manager::sanitize_slug( wp_unslash( $_GET['game'] ) ) : '';
		// phpcs:enable

		$days  = in_array( $days, self::PERIODS, true ) ? $days : 30;
		$games = $this->games->all();
		$slug  = isset( $games[ $slug ] ) ? $slug : '';
		$rows  = $this->plays->per_game( $days, $slug );

		$totals = array(
			'plays'      => 0,
			'seconds'    => 0,
			'timed'      => 0,
			'completed'  => 0,
			// Plays of games that ever sent "complete" — the only ones a
			// completion rate means anything for.
			'finishable' => 0,
			'desktop'    => 0,
			'tablet'     => 0,
			'mobile'     => 0,
		);

		foreach ( $rows as $row ) {
			foreach ( array( 'plays', 'seconds', 'timed', 'completed', 'desktop', 'tablet', 'mobile' ) as $key ) {
				$totals[ $key ] += $row[ $key ];
			}

			if ( $row['completed'] ) {
				$totals['finishable'] += $row['plays'];
			}
		}

		// Every game in the table, played or not, most played first.
		$table = array();

		foreach ( '' !== $slug ? array( $slug => $games[ $slug ] ) : $games as $key => $game ) {
			$table[ $key ] = array(
				'game'  => $game,
				'stats' => isset( $rows[ $key ] ) ? $rows[ $key ] : null,
			);
		}

		uasort(
			$table,
			static function ( $a, $b ) {
				$pa = $a['stats'] ? $a['stats']['plays'] : 0;
				$pb = $b['stats'] ? $b['stats']['plays'] : 0;

				return $pb - $pa ?: strcasecmp( $a['game']['name'], $b['game']['name'] );
			}
		);

		$this->admin->render_screen(
			BZPL_PLUGIN_PATH . 'templates/admin-analytics.php',
			array(
				'days'      => $days,
				'slug'      => $slug,
				'games'     => $games,
				'totals'    => $totals,
				'table'     => $table,
				'daily'     => $this->plays->per_day( $days ? $days : 90, $slug ),
				'recording' => $this->plays->recording(),
			)
		);
	}
}
