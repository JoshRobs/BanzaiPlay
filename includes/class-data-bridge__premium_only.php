<?php
/**
 * Data Bridge — WordPress to game (Pro).
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
 * Hands WordPress data to a game, configured per game on its edit screen.
 * The game reads it inside its frame:
 *
 *     BanzaiPlay.data.levelSet          // a value set here
 *     const user = await BanzaiPlay.user();
 *     user.displayName, user.nonce      // the visitor, fetched
 *
 * and the page around it has the same values at
 * window.BanzaiPlay.games['my-game'].
 *
 * Two channels, split by whether a page cache may store the value — the
 * same split as BanzaiEmbed's Data Bridge:
 *
 * - Page data is the same for every visitor to a URL, so it goes in the
 *   player's config (bzpl/player_config) and frontend.js hands it to the
 *   frame on the iframe element, before the game's first script runs.
 * - User data differs per visitor. In the page, a cache would serve one
 *   user's details to the next visitor and a cached REST nonce goes stale, so
 *   the game fetches it from an uncached admin-ajax endpoint. Not a REST
 *   route: REST treats a cookie without a nonce as logged out, and the nonce
 *   is one of the things being fetched.
 *
 * Output depends on this code being present, not on the licence being
 * valid: a game built to read BanzaiPlay.data would break overnight if a
 * lapsed licence took it away. Changing the configuration needs a licence.
 */
final class Data_Bridge {

	const USER_ACTION = 'bzpl_user';

	/**
	 * Cap on rows — the whole config lives in an autoloaded option.
	 */
	const MAX_ROWS = 50;

	/**
	 * What a key must look like: a JS identifier, so `BanzaiPlay.data.key` works.
	 */
	const KEY_PATTERN = '/^[A-Za-z_$][A-Za-z0-9_$]{0,63}$/';

	/**
	 * @var Game_Manager
	 */
	private $games;

	/**
	 * Constructor.
	 *
	 * @param Game_Manager $games Game store.
	 */
	public function __construct( Game_Manager $games ) {
		$this->games = $games;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		add_filter( 'bzpl/player_config', array( $this, 'player_config' ), 10, 2 );
		add_action( 'wp_ajax_' . self::USER_ACTION, array( $this, 'handle_user' ) );
		add_action( 'wp_ajax_nopriv_' . self::USER_ACTION, array( $this, 'handle_user' ) );
		add_action( 'bzpl/edit_cards', array( $this, 'render_card' ), 30 );
		add_filter( 'bzpl/save_game', array( $this, 'save' ) );
	}

	/**
	 * Where a value can come from. `static` and `post.meta` take a value (the
	 * text, the meta key); the rest do not.
	 *
	 * @return array<string,string> source => label.
	 */
	public static function sources() {
		return array(
			'static'        => __( 'Text', 'banzaiplay' ),
			'post.id'       => __( 'Post: ID', 'banzaiplay' ),
			'post.title'    => __( 'Post: title', 'banzaiplay' ),
			'post.url'      => __( 'Post: URL', 'banzaiplay' ),
			'post.slug'     => __( 'Post: slug', 'banzaiplay' ),
			'post.type'     => __( 'Post: type', 'banzaiplay' ),
			'post.meta'     => __( 'Post: custom field', 'banzaiplay' ),
			'site.name'     => __( 'Site: name', 'banzaiplay' ),
			'site.url'      => __( 'Site: home URL', 'banzaiplay' ),
			'site.rest'     => __( 'Site: REST API root', 'banzaiplay' ),
			'site.language' => __( 'Site: language', 'banzaiplay' ),
		);
	}

	/**
	 * Whether a source takes a value.
	 *
	 * @param string $source From sources().
	 * @return bool
	 */
	public static function takes_value( $source ) {
		return 'static' === $source || 'post.meta' === $source;
	}

	/**
	 * User fields a game can be given. The key is also the property name.
	 *
	 * @return array<string,string> field => label.
	 */
	public static function user_fields() {
		return array(
			'id'          => __( 'User ID', 'banzaiplay' ),
			'displayName' => __( 'Display name', 'banzaiplay' ),
			'email'       => __( 'Email', 'banzaiplay' ),
			'roles'       => __( 'Roles', 'banzaiplay' ),
			'nonce'       => __( 'REST API nonce', 'banzaiplay' ),
		);
	}

	/**
	 * A game's bridge config with every section present.
	 *
	 * @param array $game Record.
	 * @return array{values: array[], user: string[]}
	 */
	public static function config( array $game ) {
		$bridge = isset( $game['bridge'] ) && is_array( $game['bridge'] ) ? $game['bridge'] : array();

		return array_merge(
			array(
				'values' => array(),
				'user'   => array(),
			),
			$bridge
		);
	}

	/**
	 * Add the page data and the user endpoint to the player's config.
	 *
	 * @param array $config Player config.
	 * @param array $game   Record.
	 * @return array
	 */
	public function player_config( $config, $game ) {
		$bridge = self::config( $game );

		if ( ! $bridge['values'] && ! $bridge['user'] ) {
			return $config;
		}

		$post   = self::current_post();
		$values = array();

		foreach ( $bridge['values'] as $row ) {
			$values[ $row['key'] ] = self::resolve( $row, $post );
		}

		// An object, so an empty set is {} in JS rather than [].
		$config['bridge'] = array( 'data' => (object) $values );

		if ( $bridge['user'] ) {
			$config['bridge']['userUrl'] = add_query_arg(
				array(
					'action' => self::USER_ACTION,
					'game'   => $game['slug'],
				),
				// Relative: same origin as the page, so the login cookie goes with it.
				admin_url( 'admin-ajax.php', 'relative' )
			);
		}

		return $config;
	}

	/**
	 * The post being viewed, or null off single views. A game in a widget on
	 * an archive gets no post data rather than whichever post was in the loop.
	 *
	 * @return \WP_Post|null
	 */
	private static function current_post() {
		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * One value.
	 *
	 * @param array         $row  { key, source, value }.
	 * @param \WP_Post|null $post Current post.
	 * @return mixed
	 */
	private static function resolve( array $row, $post ) {
		switch ( $row['source'] ) {
			case 'static':
				return $row['value'];
			case 'site.name':
				// Stored HTML-escaped; the game wants text.
				return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			case 'site.url':
				return home_url( '/' );
			case 'site.rest':
				return rest_url();
			case 'site.language':
				return get_bloginfo( 'language' );
		}

		if ( ! $post ) {
			return null;
		}

		switch ( $row['source'] ) {
			case 'post.id':
				return (int) $post->ID;
			case 'post.title':
				return wp_strip_all_tags( $post->post_title );
			case 'post.url':
				return get_permalink( $post );
			case 'post.slug':
				return $post->post_name;
			case 'post.type':
				return $post->post_type;
			case 'post.meta':
				// Custom fields are behind the password like the content is.
				// Protected keys were refused on save; check again in case a
				// plugin has since registered the key as protected.
				if ( post_password_required( $post ) || is_protected_meta( $row['value'], 'post' ) || ! metadata_exists( 'post', $post->ID, $row['value'] ) ) {
					return null;
				}

				return get_post_meta( $post->ID, $row['value'], true );
		}

		return null;
	}

	/**
	 * GET admin-ajax.php?action=bzpl_user&game={slug} — the current visitor's
	 * fields, as enabled for that game. Never cached.
	 */
	public function handle_user() {
		nocache_headers();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read of the requester's own data; the same-origin policy keeps other sites from reading it.
		$slug   = isset( $_GET['game'] ) && is_string( $_GET['game'] ) ? Game_Manager::sanitize_slug( wp_unslash( $_GET['game'] ) ) : '';
		$game   = '' === $slug ? null : $this->games->get( $slug );
		$config = $game ? self::config( $game ) : null;

		if ( ! $config || ! $config['user'] ) {
			wp_send_json( array( 'error' => 'not_found' ), 404 );
		}

		wp_send_json( self::user_payload( $config['user'], $game ) );
	}

	/**
	 * The current visitor's enabled fields. `loggedIn` is always included.
	 *
	 * @param string[] $fields From user_fields().
	 * @param array    $game   Record.
	 * @return array
	 */
	private static function user_payload( array $fields, array $game ) {
		$user = wp_get_current_user();
		$in   = $user->exists();
		$out  = array( 'loggedIn' => $in );

		foreach ( $fields as $field ) {
			switch ( $field ) {
				case 'id':
					$out['id'] = $in ? (int) $user->ID : null;
					break;
				case 'displayName':
					$out['displayName'] = $in ? $user->display_name : null;
					break;
				case 'email':
					$out['email'] = $in ? $user->user_email : null;
					break;
				case 'roles':
					$out['roles'] = $in ? array_values( $user->roles ) : array();
					break;
				case 'nonce':
					// Logged-out REST requests need no nonce.
					$out['nonce'] = $in ? wp_create_nonce( 'wp_rest' ) : null;
					break;
			}
		}

		/**
		 * Filter what BanzaiPlay.user() resolves to — the place to add per-user
		 * data such as a saved high score. Runs on every request, never cached.
		 *
		 * @param array    $out  Payload.
		 * @param array    $game Game record.
		 * @param \WP_User $user Current user; ID 0 when logged out.
		 */
		return (array) apply_filters( 'bzpl/user_data', $out, $game, $user );
	}

	/**
	 * The Data Bridge card on the edit screen.
	 *
	 * @param array $game Record.
	 */
	public function render_card( $game ) {
		$licensed = bzpl_has_valid_license();
		$config   = self::config( $game );
		$sources  = self::sources();
		$fields   = self::user_fields();
		$usage    = self::usage( $config );

		include BZPL_PLUGIN_PATH . 'templates/admin-bridge__premium_only.php';
	}

	/**
	 * Example code for the game, from its current config.
	 *
	 * @param array $config From config().
	 * @return string
	 */
	private static function usage( array $config ) {
		$sources = self::sources();
		$rest    = "'/wp-json/'";
		$lines   = array( '// In the game:' );

		foreach ( $config['values'] as $row ) {
			$lines[] = sprintf( 'BanzaiPlay.data.%s // %s', $row['key'], $sources[ $row['source'] ] );

			if ( 'site.rest' === $row['source'] ) {
				$rest = 'BanzaiPlay.data.' . $row['key'];
			}
		}

		if ( ! $config['values'] ) {
			$lines[] = 'BanzaiPlay.data // {}';
		}

		if ( $config['user'] ) {
			$lines[] = '';
			$lines[] = 'const user = await BanzaiPlay.user();';
			$lines[] = 'user.loggedIn';

			foreach ( $config['user'] as $field ) {
				$lines[] = 'user.' . $field;
			}

			if ( in_array( 'nonce', $config['user'], true ) ) {
				$lines[] = '';
				$lines[] = 'fetch(' . $rest . " + 'wp/v2/users/me', {";
				$lines[] = "  headers: { 'X-WP-Nonce': user.nonce },";
				$lines[] = '});';
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Read the card's fields into the record. Runs inside Admin::handle_save(),
	 * after its capability and nonce checks.
	 *
	 * @param array $game Record.
	 * @return array
	 */
	public function save( $game ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in Admin::handle_save(); every field is validated below.
		// Not on the form, or no licence to change it: keep what was saved.
		if ( ! isset( $_POST['bzpl_bridge'] ) || ! bzpl_has_valid_license() ) {
			return $game;
		}

		$problems = array();
		$values   = self::parse_values( isset( $_POST['bridge_values'] ) ? wp_unslash( $_POST['bridge_values'] ) : array(), $problems );
		$user     = isset( $_POST['bridge_user'] ) ? (array) wp_unslash( $_POST['bridge_user'] ) : array();
		// phpcs:enable

		$game['bridge'] = array(
			'values' => $values,
			// Kept in user_fields() order so the payload is stable.
			'user'   => array_values( array_intersect( array_keys( self::user_fields() ), $user ) ),
		);

		if ( $problems ) {
			Admin::notice( 'warning', __( 'Some Data Bridge rows were not saved:', 'banzaiplay' ), $problems );
		}

		return $game;
	}

	/**
	 * Value rows from the form.
	 *
	 * @param mixed    $rows     Posted rows.
	 * @param string[] $problems Collects reasons rows were dropped.
	 * @return array[]
	 */
	private static function parse_values( $rows, array &$problems ) {
		$out     = array();
		$sources = self::sources();

		foreach ( array_filter( is_array( $rows ) ? $rows : array(), 'is_array' ) as $row ) {
			$key    = self::text( $row, 'key' );
			$source = self::text( $row, 'source' );
			$value  = self::text( $row, 'value', false );

			if ( ! isset( $sources[ $source ] ) ) {
				$source = 'static';
			}

			if ( ! self::takes_value( $source ) ) {
				$value = '';
			} elseif ( 'post.meta' === $source ) {
				$value = trim( $value );
			}

			if ( '' === $key && '' === $value ) {
				continue; // An empty row, or one cleared to delete it.
			}

			$problem = '';

			if ( '' === $key ) {
				$problem = __( 'no key', 'banzaiplay' );
			} elseif ( ! preg_match( self::KEY_PATTERN, $key ) ) {
				$problem = __( 'keys must be letters, numbers, _ or $, not starting with a number', 'banzaiplay' );
			} elseif ( isset( $out[ $key ] ) ) {
				$problem = __( 'the key is used twice', 'banzaiplay' );
			} elseif ( 'post.meta' === $source && '' === $value ) {
				$problem = __( 'no custom field key', 'banzaiplay' );
			} elseif ( 'post.meta' === $source && is_protected_meta( $value, 'post' ) ) {
				$problem = __( 'protected custom fields (starting with _) are private to WordPress', 'banzaiplay' );
			}

			if ( '' !== $problem ) {
				$problems[] = sprintf( '%s: %s', '' !== $key ? $key : __( '(no key)', 'banzaiplay' ), $problem );
				continue;
			}

			$out[ $key ] = array(
				'key'    => $key,
				'source' => $source,
				'value'  => $value,
			);
		}

		foreach ( array_slice( array_keys( $out ), self::MAX_ROWS ) as $key ) {
			/* translators: %d: maximum number of rows. */
			$problems[] = sprintf( '%s: %s', $key, sprintf( __( 'more than %d rows', 'banzaiplay' ), self::MAX_ROWS ) );
		}

		return array_values( array_slice( $out, 0, self::MAX_ROWS ) );
	}

	/**
	 * A string field of a posted row. Values are kept as typed (no tag
	 * stripping): only users with unfiltered_html get here, the result is JSON
	 * in an attribute, and a game may want a literal "<".
	 *
	 * @param array  $row  Posted row.
	 * @param string $name Field.
	 * @param bool   $trim Trim whitespace.
	 * @return string
	 */
	private static function text( array $row, $name, $trim = true ) {
		$text = isset( $row[ $name ] ) && is_string( $row[ $name ] ) ? wp_check_invalid_utf8( $row[ $name ] ) : '';
		$text = substr( $text, 0, 2000 );

		return $trim ? trim( $text ) : $text;
	}
}
