<?php
/**
 * Plays: what the player reports, stored and passed on.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * One row per play in {prefix}banzaiplay_plays, and the endpoint the player
 * reports to: admin-ajax.php?action=bzpl_play, POSTed by player-extras.js.
 *
 *     op=start  game, device, post        → { sid, token }
 *     op=ping   sid, token, game, seconds  time played so far (visible time only)
 *     op=event  sid, token, game, name, data (JSON)
 *
 * Every event a game emits fires the `bzpl/game_event` action, so other
 * plugins can react — award a badge, mark a lesson done. "complete" marks the
 * play completed and "score" records its score, for Analytics.
 *
 * What is stored is anonymous: no IP address, no user ID, no cookie — a
 * random play ID, the game, the post it was played on, the time, the device
 * class, the seconds played, and whether it was completed and with what
 * score. Statistics can be switched off on the Settings screen; events still
 * fire the action then.
 *
 * Everything here arrives from a browser, so it is a claim, not a fact:
 * anyone can send "complete". The token only proves the request belongs to a
 * play this server started — it stops another site from posting events in a
 * logged-in visitor's name (it can't read the token), not a visitor from
 * posting their own. Starts are rate-limited per visitor, and events capped
 * per play. Don't hand out anything of value on an event alone.
 *
 * Not a REST route: a security plugin that blocks REST for visitors would
 * silently stop statistics, and REST ignores the login cookie without a
 * nonce — which a cached page can't carry.
 */
final class Plays {

	const ACTION = 'bzpl_play';

	const DB_VERSION = '1';

	const DB_OPTION = 'banzaiplay_db_version';

	const PURGE_ACTION = 'banzaiplay_purge_plays';

	const CRON = 'bzpl_prune_plays';

	/**
	 * Starts allowed per visitor per ten minutes.
	 */
	const MAX_STARTS = 120;

	/**
	 * Events accepted per play.
	 */
	const MAX_EVENTS = 300;

	/**
	 * Largest event payload, in bytes of JSON.
	 */
	const MAX_DATA = 8192;

	/**
	 * Longest play counted, in seconds.
	 */
	const MAX_SECONDS = 86400;

	const DEVICES = array( 'desktop', 'tablet', 'mobile' );

	/**
	 * @var Game_Manager
	 */
	private $games;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Game_Manager $games    Game store.
	 * @param Settings     $settings Site settings.
	 */
	public function __construct( Game_Manager $games, Settings $settings ) {
		$this->games    = $games;
		$this->settings = $settings;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::PURGE_ACTION, array( $this, 'handle_purge' ) );
		add_action( 'admin_init', array( $this, 'maybe_install' ) );
		add_action( 'bzpl/game_deleted', array( $this, 'forget_game' ) );
		add_filter( 'bzpl/player_config', array( $this, 'player_config' ), 10, 3 );
		add_action( self::CRON, array( $this, 'prune' ) );

		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * The table's name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'banzaiplay_plays';
	}

	/**
	 * Create or upgrade the table when this version hasn't yet.
	 */
	public function maybe_install() {
		if ( self::DB_VERSION === get_option( self::DB_OPTION ) ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta's format: two spaces after PRIMARY KEY, one key per line.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sid char(32) NOT NULL,
				game varchar(60) NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				started datetime NOT NULL,
				seconds int(10) unsigned NOT NULL DEFAULT 0,
				device varchar(8) NOT NULL DEFAULT '',
				completed tinyint(1) unsigned NOT NULL DEFAULT 0,
				score double DEFAULT NULL,
				events smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY sid (sid),
				KEY game_started (game,started),
				KEY started (started)
			) {$charset};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Whether plays are being recorded.
	 *
	 * @return bool
	 */
	public function recording() {
		return (bool) $this->settings->get( 'record' );
	}

	/**
	 * Tell each player to report, with the post it is on. Not the edit
	 * screen's preview: that's the owner trying the game, not a play.
	 *
	 * @param array $config Player config.
	 * @param array $game   Record.
	 * @param array $args   Render arguments.
	 * @return array
	 */
	public function player_config( $config, $game, $args ) {
		if ( empty( $args['preview'] ) ) {
			$config['track'] = true;
			$config['post']  = is_singular() ? (int) get_queried_object_id() : 0;
		}

		return $config;
	}

	/**
	 * The token that proves a request belongs to a play this server started.
	 *
	 * @param string $sid  Play ID.
	 * @param string $game Game slug.
	 * @return string
	 */
	private static function token( $sid, $game ) {
		return substr( hash_hmac( 'sha256', $sid . '|' . $game, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * A POSTed string.
	 *
	 * @param string $key Field.
	 * @param int    $max Longest accepted.
	 * @return string
	 */
	private static function field( $key, $max = 64 ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public endpoint, authenticated by its own token; see the class comment.
		$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		return substr( (string) $value, 0, $max );
	}

	/**
	 * POST admin-ajax.php?action=bzpl_play.
	 */
	public function handle() {
		nocache_headers();

		$op   = self::field( 'op', 8 );
		$slug = Game_Manager::sanitize_slug( self::field( 'game' ) );
		$game = '' === $slug ? null : $this->games->get( $slug );

		// The same games Frame serves: playable, and active unless the visitor manages games.
		if ( ! $game || 'ready' !== Game_Manager::status( $game ) || ! ( Game_Manager::is_active( $game ) || Admin::can_manage() ) ) {
			wp_send_json( array( 'error' => 'not_found' ), 404 );
		}

		if ( 'start' === $op ) {
			$this->start( $game );
		}

		$sid = preg_replace( '/[^A-Za-z0-9]/', '', self::field( 'sid', 32 ) );

		if ( 32 !== strlen( $sid ) || ! hash_equals( self::token( $sid, $slug ), self::field( 'token', 32 ) ) ) {
			wp_send_json( array( 'error' => 'bad_token' ), 403 );
		}

		if ( 'ping' === $op ) {
			$this->ping( $sid, absint( self::field( 'seconds', 10 ) ) );
		}

		if ( 'event' === $op ) {
			$this->event( $game, $sid );
		}

		wp_send_json( array( 'error' => 'bad_op' ), 400 );
	}

	/**
	 * Start a play.
	 *
	 * @param array $game Record.
	 */
	private function start( array $game ) {
		if ( ! $this->allow_start() ) {
			wp_send_json( array( 'error' => 'rate_limited' ), 429 );
		}

		$sid    = wp_generate_password( 32, false, false );
		$device = self::field( 'device', 8 );

		if ( $this->recording() ) {
			global $wpdb;

			$this->maybe_install();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our own table.
			$wpdb->insert(
				self::table(),
				array(
					'sid'     => $sid,
					'game'    => $game['slug'],
					'post_id' => absint( self::field( 'post', 20 ) ),
					'started' => current_time( 'mysql', true ),
					'device'  => in_array( $device, self::DEVICES, true ) ? $device : '',
				),
				array( '%s', '%s', '%d', '%s', '%s' )
			);
		}

		wp_send_json(
			array(
				'sid'   => $sid,
				'token' => self::token( $sid, $game['slug'] ),
			)
		);
	}

	/**
	 * Whether this visitor may start another play: at most MAX_STARTS per ten
	 * minutes. The address is only hashed into a transient key, never stored.
	 *
	 * @return bool
	 */
	private function allow_start() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		// Ten-minute buckets, so the count resets on its own.
		$key   = 'bzpl_starts_' . substr( hash_hmac( 'sha256', $ip . '|' . floor( time() / 600 ), wp_salt( 'nonce' ) ), 0, 20 );
		$count = (int) get_transient( $key );

		if ( $count >= self::MAX_STARTS ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Record the time played: never less than before, never more than has
	 * passed since the play started.
	 *
	 * @param string $sid     Play ID.
	 * @param int    $seconds Seconds played, as the browser counted them.
	 */
	private function ping( $sid, $seconds ) {
		if ( $this->recording() ) {
			global $wpdb;

			$table = self::table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; its name is not user input.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET seconds = GREATEST(seconds, LEAST(%d, %d, TIMESTAMPDIFF(SECOND, started, UTC_TIMESTAMP()) + 5)) WHERE sid = %s", $seconds, self::MAX_SECONDS, $sid ) );
		}

		wp_send_json( array( 'ok' => true ) );
	}

	/**
	 * Record an event and pass it on.
	 *
	 * @param array  $game Record.
	 * @param string $sid  Play ID.
	 */
	private function event( array $game, $sid ) {
		global $wpdb;

		$name = self::field( 'name', 64 );
		$raw  = self::field( 'data', self::MAX_DATA + 1 );

		if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $name ) || strlen( $raw ) > self::MAX_DATA ) {
			wp_send_json( array( 'error' => 'bad_event' ), 400 );
		}

		$data  = '' === $raw ? null : json_decode( $raw, true );
		$table = self::table();
		$row   = null;

		if ( $this->recording() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, post_id, events FROM {$table} WHERE sid = %s", $sid ), ARRAY_A );
			$count = $row ? (int) $row['events'] : 0;
		} else {
			$count = (int) get_transient( 'bzpl_events_' . $sid );
		}

		if ( $count >= self::MAX_EVENTS ) {
			wp_send_json( array( 'error' => 'too_many_events' ), 429 );
		}

		$score = self::score( $name, $data );

		if ( $row ) {
			$sets = array( 'events = events + 1' );

			if ( 'complete' === $name ) {
				$sets[] = 'completed = 1';
			}

			if ( null !== $score ) {
				$sets[] = $wpdb->prepare( 'score = %f', $score );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; $sets are fixed or prepared above.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id = %d', $row['id'] ) );
		} elseif ( ! $this->recording() ) {
			set_transient( 'bzpl_events_' . $sid, $count + 1, DAY_IN_SECONDS );
		}

		/**
		 * A game sent an event: BanzaiPlay.emit( name, data ) in the game.
		 *
		 * Sent by the visitor's browser, so treat it as a claim — see Plays.
		 * Runs during an admin-ajax request, logged in as the visitor if they
		 * are, so get_current_user_id() is the player (0 for a visitor).
		 *
		 * @param string $name    Event name: "score", "complete" or the game's own.
		 * @param mixed  $data    The event's data, decoded from JSON.
		 * @param array  $context {
		 *     @type array  $game    Game record.
		 *     @type string $play    Play ID.
		 *     @type int    $user_id Logged-in user, or 0.
		 *     @type int    $post_id Post the game was played on, or 0 (when recorded).
		 * }
		 */
		do_action(
			'bzpl/game_event',
			$name,
			$data,
			array(
				'game'    => $game,
				'play'    => $sid,
				'user_id' => get_current_user_id(),
				'post_id' => $row ? (int) $row['post_id'] : 0,
			)
		);

		/**
		 * A game sent a particular event — bzpl/game_event/complete, say.
		 *
		 * @param mixed $data    The event's data.
		 * @param array $context As for bzpl/game_event.
		 */
		do_action(
			'bzpl/game_event/' . $name,
			$data,
			array(
				'game'    => $game,
				'play'    => $sid,
				'user_id' => get_current_user_id(),
				'post_id' => $row ? (int) $row['post_id'] : 0,
			)
		);

		wp_send_json( array( 'ok' => true ) );
	}

	/**
	 * The score an event carries: `score` (or `points`) on "score" and
	 * "complete", or a bare number sent as "score".
	 *
	 * @param string $name Event name.
	 * @param mixed  $data Event data.
	 * @return float|null
	 */
	public static function score( $name, $data ) {
		if ( 'score' !== $name && 'complete' !== $name ) {
			return null;
		}

		if ( is_array( $data ) ) {
			$data = isset( $data['score'] ) ? $data['score'] : ( isset( $data['points'] ) ? $data['points'] : null );
		}

		return is_numeric( $data ) && is_finite( (float) $data ) ? (float) $data : null;
	}

	/**
	 * Delete plays older than the Settings screen keeps them. Daily, on cron.
	 */
	public function prune() {
		$days = (int) $this->settings->get( 'keep_days' );

		if ( $days <= 0 || self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			return;
		}

		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE started < UTC_TIMESTAMP() - INTERVAL %d DAY", $days ) );
	}

	/**
	 * A deleted game's plays go with it.
	 *
	 * @param string $slug Game slug.
	 */
	public function forget_game( $slug ) {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table.
		$wpdb->delete( self::table(), array( 'game' => $slug ), array( '%s' ) );
	}

	/**
	 * Delete every recorded play, from the Settings screen.
	 */
	public function handle_purge() {
		if ( ! Admin::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage BanzaiPlay games.', 'banzaiplay' ), 403 );
		}

		check_admin_referer( self::PURGE_ACTION );

		global $wpdb;

		$this->maybe_install();
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		Admin::notice( 'success', __( 'Every recorded play was deleted.', 'banzaiplay' ) );
		wp_safe_redirect( Settings::url() );
		exit;
	}

	// ---------------------------------------------------------------------
	// Queries, for Analytics.

	/**
	 * The WHERE clause for a period and, optionally, one game.
	 *
	 * @param int    $days Days back from now; 0 for all time.
	 * @param string $game Game slug, or ''.
	 * @return string Prepared SQL.
	 */
	private static function where( $days, $game ) {
		global $wpdb;

		$where = array( '1=1' );

		if ( $days > 0 ) {
			$where[] = $wpdb->prepare( 'started >= UTC_TIMESTAMP() - INTERVAL %d DAY', $days );
		}

		if ( '' !== $game ) {
			$where[] = $wpdb->prepare( 'game = %s', $game );
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Totals per game for a period.
	 *
	 * @param int    $days Days back; 0 for all time.
	 * @param string $game One game's slug, or '' for all.
	 * @return array<string,array> slug => { plays, seconds, timed, completed, best, desktop, tablet, mobile }
	 */
	public function per_game( $days, $game = '' ) {
		global $wpdb;

		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			return array();
		}

		$table = self::table();
		$where = self::where( $days, $game );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; $where is prepared.
		$rows = $wpdb->get_results(
			"SELECT game,
				COUNT(*) AS plays,
				SUM(seconds) AS seconds,
				SUM(seconds > 0) AS timed,
				SUM(completed) AS completed,
				MAX(score) AS best,
				SUM(device = 'desktop') AS desktop,
				SUM(device = 'tablet') AS tablet,
				SUM(device = 'mobile') AS mobile
			FROM {$table} WHERE {$where} GROUP BY game",
			ARRAY_A
		);
		// phpcs:enable

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['game'] ] = array(
				'plays'     => (int) $row['plays'],
				'seconds'   => (int) $row['seconds'],
				'timed'     => (int) $row['timed'],
				'completed' => (int) $row['completed'],
				'best'      => null === $row['best'] ? null : (float) $row['best'],
				'desktop'   => (int) $row['desktop'],
				'tablet'    => (int) $row['tablet'],
				'mobile'    => (int) $row['mobile'],
			);
		}

		return $out;
	}

	/**
	 * Plays per day, in the site's time zone, oldest first, with empty days.
	 *
	 * @param int    $days Days back (1–366).
	 * @param string $game One game's slug, or ''.
	 * @return array<string,int> Y-m-d => plays.
	 */
	public function per_day( $days, $game = '' ) {
		global $wpdb;

		$days   = max( 1, min( 366, (int) $days ) );
		$offset = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$out    = array();
		$today  = (int) floor( ( time() + $offset ) / DAY_IN_SECONDS );

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[ gmdate( 'Y-m-d', ( $today - $i ) * DAY_IN_SECONDS ) ] = 0;
		}

		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			return $out;
		}

		$table = self::table();
		$where = self::where( $days, $game );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; $where is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(started + INTERVAL %d SECOND) AS day, COUNT(*) AS plays FROM {$table} WHERE {$where} GROUP BY day", $offset ),
			ARRAY_A
		);
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['day'] ] ) ) {
				$out[ $row['day'] ] = (int) $row['plays'];
			}
		}

		return $out;
	}
}
