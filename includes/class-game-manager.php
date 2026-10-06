<?php
/**
 * Game records.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for game records, and where each game's files live.
 *
 * Every game is one entry in the `banzaiplay_games` option, keyed by slug.
 * Its files live at uploads/banzaiplay/{slug}/ — one stable directory per
 * game, replaced as a whole when a new build is uploaded. Stable, because
 * engines key a player's saved data to the URL the game is served from:
 * Unity's persistentDataPath and PlayerPrefs hash the folder of the .data
 * file, so a new folder per upload would wipe every player's saves on every
 * update. Browsers are kept from serving stale files by the build ID in the
 * query string of everything BanzaiPlay itself requests (see Frame).
 */
final class Game_Manager {

	const OPTION = 'banzaiplay_games';

	const DIR = 'banzaiplay';

	/**
	 * Where uploads are unpacked before they replace a game's files. Slugs
	 * cannot contain an underscore, so this can never collide with a game.
	 */
	const STAGING = '_staging';

	const ENGINES = array( 'unity', 'godot', 'construct', 'playcanvas', 'phaser', 'pixi', 'generic' );

	const FITS = array( 'resize', 'scale' );

	const RELEASE_KEYS = array( 'escape', 'shift_escape', 'none' );

	const STARTS = array( 'click', 'load' );

	const DEFAULT_WIDTH = 960;

	const DEFAULT_HEIGHT = 540;

	/**
	 * Request-level cache of the option.
	 *
	 * @var array|null
	 */
	private $games = null;

	/**
	 * The shape of a record. Anything missing from a stored record is filled
	 * from here, so old records survive new fields.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'name'              => '',
			'slug'              => '',
			'description'       => '',
			// The engine the game is played as: the override if one is set,
			// else the detected one.
			'engine'            => 'generic',
			'engine_override'   => '',
			'detected_engine'   => '',
			// The file that gave the engine away, for the edit screen.
			'detected_by'       => '',
			// What boots the game, relative to the game directory: an HTML
			// page, a Unity loader (or legacy build JSON) or a Godot engine
			// script. '' when none was found.
			'entry'             => '',
			'entry_override'    => '',
			// Other files that could be the entry, for the picker.
			'entries'           => array(),
			// What Frame needs to start the game; its `mode` is 'unity',
			// 'godot' or 'html'. See Engine_Detector.
			'engine_data'       => array(),
			// Native resolution set on the game; 0 = detected, else default.
			'width'             => 0,
			'height'            => 0,
			'detected_width'    => 0,
			'detected_height'   => 0,
			// 'resize': the frame takes the player's size and the game adapts.
			// 'scale': the frame stays at the native size and is scaled.
			'fit'               => 'resize',
			// When the game starts: 'click' (on Play) or 'load' (with the page).
			// A shortcode's or block's own choice wins over this.
			'start'             => 'click',
			// Which key hands the keyboard back to the page.
			'release_key'       => 'escape',
			// Show "best experienced on desktop" on touch devices.
			'desktop_only'      => false,
			// Inactive games render nothing on the front end.
			'active'            => true,
			// Build ID — changes on every upload; the cache-busting version.
			'build'             => '',
			'uploaded'          => 0,
			'size'              => 0,
			'files'             => 0,
			// Root-absolute base an HTML build was compiled for ('/' or
			// '/x/y/'), whose references Path_Rewriter relocated; '' if relative.
			'base'              => '',
			'relocated'         => 0,
			'preload_relocated' => false,
			// Root-based build with lazy chunks whose loader wasn't relocated.
			'needs_rebuild'     => false,
			'warnings'          => array(),
			'created'           => 0,
			'modified'          => 0,
		);
	}

	/**
	 * All games, keyed by slug, sorted by name.
	 *
	 * @return array[]
	 */
	public function all() {
		if ( null === $this->games ) {
			$stored = get_option( self::OPTION, array() );
			$games  = array();

			foreach ( is_array( $stored ) ? $stored : array() as $slug => $game ) {
				if ( is_array( $game ) ) {
					$games[ $slug ] = array_merge( self::defaults(), $game );
				}
			}

			uasort(
				$games,
				static function ( $a, $b ) {
					return strcasecmp( $a['name'], $b['name'] );
				}
			);

			$this->games = $games;
		}

		return $this->games;
	}

	/**
	 * One game, or null.
	 *
	 * @param string $slug Game slug.
	 * @return array|null
	 */
	public function get( $slug ) {
		$games = $this->all();

		return isset( $games[ $slug ] ) ? $games[ $slug ] : null;
	}

	/**
	 * Insert or replace a record.
	 *
	 * @param array $game Record; `slug` is the key.
	 */
	public function save( array $game ) {
		$game  = array_merge( self::defaults(), $game );
		$games = $this->all();

		if ( ! $game['created'] ) {
			$game['created'] = time();
		}

		$game['modified']       = time();
		$games[ $game['slug'] ] = $game;
		$this->write( $games );
	}

	/**
	 * Remove a record and every file it owns.
	 *
	 * @param string $slug Game slug.
	 */
	public function delete( $slug ) {
		$games = $this->all();

		unset( $games[ $slug ] );
		$this->write( $games );

		Filesystem::delete( $this->game_dir( $slug ) );

		/**
		 * Fires after a game and its files are deleted.
		 *
		 * @param string $slug Game slug.
		 */
		do_action( 'bzpl/game_deleted', $slug );
	}

	/**
	 * Persist. Autoloaded: the front end reads it on any page with a game.
	 *
	 * @param array $games All records.
	 */
	private function write( array $games ) {
		update_option( self::OPTION, $games, true );
		$this->games = null;
	}

	/**
	 * Turn a name into a slug that is safe as a directory, a shortcode
	 * attribute, an object key and part of an element ID.
	 *
	 * @param string $text Source text.
	 * @return string
	 */
	public static function sanitize_slug( $text ) {
		$slug = sanitize_title( (string) $text );
		$slug = preg_replace( '/[^a-z0-9-]/', '', $slug );

		return substr( trim( $slug, '-' ), 0, 60 );
	}

	/**
	 * A slug based on $base that no game has yet.
	 *
	 * @param string $base Desired slug.
	 * @return string
	 */
	public function unique_slug( $base ) {
		$slug = $base;
		$n    = 2;

		while ( $this->get( $slug ) ) {
			$slug = $base . '-' . $n++;
		}

		return $slug;
	}

	/**
	 * Machine status: 'ready', 'needs-entry' or 'no-build'.
	 *
	 * @param array $game Record.
	 * @return string
	 */
	public static function status( array $game ) {
		if ( '' === $game['build'] ) {
			return 'no-build';
		}

		return '' === $game['entry'] || empty( $game['engine_data']['mode'] ) ? 'needs-entry' : 'ready';
	}

	/**
	 * Whether the game is switched on. Separate from status(), which is about
	 * whether the build can be played at all.
	 *
	 * @param array $game Record.
	 * @return bool
	 */
	public static function is_active( array $game ) {
		return ! empty( $game['active'] );
	}

	/**
	 * How Frame starts the game: 'unity' and 'godot' in a document BanzaiPlay
	 * writes, 'html' by serving the build's own page.
	 *
	 * @param array $game Record.
	 * @return string
	 */
	public static function mode( array $game ) {
		$mode = isset( $game['engine_data']['mode'] ) ? (string) $game['engine_data']['mode'] : '';

		return in_array( $mode, array( 'unity', 'godot', 'html' ), true ) ? $mode : '';
	}

	/**
	 * Native width and height: as set on the game, else as detected in the
	 * build, else 960 × 540.
	 *
	 * @param array $game Record.
	 * @return int[] [ width, height ]
	 */
	public static function dimensions( array $game ) {
		if ( $game['width'] > 0 && $game['height'] > 0 ) {
			return array( (int) $game['width'], (int) $game['height'] );
		}

		if ( $game['detected_width'] > 0 && $game['detected_height'] > 0 ) {
			return array( (int) $game['detected_width'], (int) $game['detected_height'] );
		}

		return array( self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT );
	}

	/**
	 * The key that releases the keyboard, valid whatever was stored.
	 *
	 * @param array $game Record.
	 * @return string
	 */
	public static function release_key( array $game ) {
		return in_array( $game['release_key'], self::RELEASE_KEYS, true ) ? $game['release_key'] : 'escape';
	}

	/**
	 * Whether the game starts with the page rather than waiting for Play,
	 * valid whatever was stored.
	 *
	 * @param array $game Record.
	 * @return bool
	 */
	public static function starts_on_load( array $game ) {
		return 'load' === $game['start'];
	}

	/**
	 * When the record last changed, for display.
	 *
	 * @param array $game Record.
	 * @return int Timestamp, or 0.
	 */
	public static function modified( array $game ) {
		return (int) max( $game['modified'], $game['uploaded'], $game['created'] );
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status From status().
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'ready'       => __( 'Ready', 'banzaiplay' ),
			'needs-entry' => __( 'Needs attention', 'banzaiplay' ),
			'no-build'    => __( 'No build uploaded', 'banzaiplay' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Human label for an engine.
	 *
	 * @param string $engine One of ENGINES.
	 * @return string
	 */
	public static function engine_label( $engine ) {
		$labels = array(
			'unity'      => __( 'Unity', 'banzaiplay' ),
			'godot'      => __( 'Godot', 'banzaiplay' ),
			'construct'  => __( 'Construct 3', 'banzaiplay' ),
			'playcanvas' => __( 'PlayCanvas', 'banzaiplay' ),
			'phaser'     => __( 'Phaser', 'banzaiplay' ),
			'pixi'       => __( 'PixiJS', 'banzaiplay' ),
			'generic'    => __( 'HTML5', 'banzaiplay' ),
		);

		return isset( $labels[ $engine ] ) ? $labels[ $engine ] : $engine;
	}

	/**
	 * The shortcode that embeds a game.
	 *
	 * @param array $game Record.
	 * @return string
	 */
	public static function shortcode( array $game ) {
		return sprintf( '[%s game="%s"]', Shortcode::TAG, $game['slug'] );
	}

	/**
	 * uploads/banzaiplay, as a path.
	 *
	 * @return string
	 */
	public function base_dir() {
		$uploads = wp_upload_dir( null, false );

		return trailingslashit( $uploads['basedir'] ) . self::DIR;
	}

	/**
	 * uploads/banzaiplay, as a URL.
	 *
	 * @return string
	 */
	public function base_url() {
		$uploads = wp_upload_dir( null, false );

		return set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::DIR );
	}

	/**
	 * Where uploads are unpacked before they go live.
	 *
	 * @return string
	 */
	public function staging_dir() {
		return $this->base_dir() . '/' . self::STAGING;
	}

	/**
	 * Directory of a game's files.
	 *
	 * @param string $slug Game slug.
	 * @return string
	 */
	public function game_dir( $slug ) {
		return $this->base_dir() . '/' . $slug;
	}

	/**
	 * URL of a game's files, with a trailing slash.
	 *
	 * @param string $slug Game slug.
	 * @return string
	 */
	public function game_url( $slug ) {
		return $this->base_url() . '/' . rawurlencode( $slug ) . '/';
	}

	/**
	 * How a build's own files should refer to the game folder once
	 * Path_Rewriter has relocated them: root-relative, so the same files keep
	 * working over http and https and on any domain pointing at this site.
	 * When uploads are served from another host, only the full URL works.
	 *
	 * @param string $slug Game slug.
	 * @return string With a trailing slash.
	 */
	public function game_ref( $slug ) {
		$url = $this->game_url( $slug );

		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return $url;
		}

		return (string) wp_parse_url( $url, PHP_URL_PATH );
	}

	/**
	 * URL of a file in a game's build.
	 *
	 * @param string $slug Game slug.
	 * @param string $path Path relative to the game directory.
	 * @return string
	 */
	public function file_url( $slug, $path ) {
		return $this->game_url( $slug ) . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}
}
