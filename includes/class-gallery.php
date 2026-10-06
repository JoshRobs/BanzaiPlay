<?php
/**
 * Game portfolio gallery.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * [banzai-play-gallery]: a grid of games — cover, name, description, tags —
 * that opens each one in a lightbox, or goes to its page.
 *
 *     [banzai-play-gallery]                       every active game
 *     [banzai-play-gallery tags="puzzle,jam"]      games with any of these tags
 *     [banzai-play-gallery games="a,b,c"]          these games, in this order
 *     [banzai-play-gallery engine="unity"]
 *     [banzai-play-gallery columns="4" filter="engine" open="page" orderby="date"]
 *
 * The lightbox's player is printed in a <template> per game, so nothing of a
 * game loads until it is opened, and gallery.js removes it
 * again when the lightbox closes — one game in memory at most.
 *
 * Stored on the game as `gallery`: { tags: string[], page: url }. The
 * picture is the game's cover (Branding).
 */
final class Gallery {

	const TAG = 'banzai-play-gallery';

	const HANDLE = 'banzaiplay-gallery';

	/**
	 * Cap on tags per game, and on a tag's length.
	 */
	const MAX_TAGS = 12;

	/**
	 * @var Game_Manager
	 */
	private $games;

	/**
	 * @var Embed
	 */
	private $embed;

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * Galleries rendered this request, for unique IDs.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Constructor.
	 *
	 * @param Game_Manager $games    Game store.
	 * @param Embed        $embed    Player renderer.
	 * @param Settings     $settings Site settings, for the colour.
	 */
	public function __construct( Game_Manager $games, Embed $embed, Settings $settings ) {
		$this->games    = $games;
		$this->embed    = $embed;
		$this->settings = $settings;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'bzpl/edit_cards', array( $this, 'render_card' ), 20 );
		add_filter( 'bzpl/save_game', array( $this, 'save' ) );
	}

	/**
	 * Register the shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( self::TAG, array( $this, 'shortcode' ) );
	}

	/**
	 * Register the gallery's script and style.
	 */
	public function register_assets() {
		wp_register_style( self::HANDLE, BZPL_PLUGIN_URL . 'assets/css/gallery.css', array( Embed::HANDLE ), BZPL_VERSION );
		wp_register_script(
			self::HANDLE,
			BZPL_PLUGIN_URL . 'assets/js/gallery.js',
			array( Embed::HANDLE ),
			BZPL_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.banzaiPlayGalleryL10n=' . wp_json_encode(
				array(
					/* translators: %d: number of games. */
					'count' => __( '%d games', 'banzaiplay' ),
					'one'   => __( '1 game', 'banzaiplay' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * A game's gallery fields, with every key present.
	 *
	 * @param array $game Record.
	 * @return array{tags:string[], page:string}
	 */
	public static function fields( array $game ) {
		$fields = isset( $game['gallery'] ) && is_array( $game['gallery'] ) ? $game['gallery'] : array();

		return array_merge(
			array(
				'tags' => array(),
				'page' => '',
			),
			$fields
		);
	}

	/**
	 * Tags from a comma-separated string: trimmed, unique case-insensitively,
	 * in the order given.
	 *
	 * @param string $text Input.
	 * @return string[]
	 */
	public static function parse_tags( $text ) {
		$tags = array();

		foreach ( explode( ',', (string) $text ) as $tag ) {
			$tag = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $tag ) ) );
			$tag = function_exists( 'mb_substr' ) ? mb_substr( $tag, 0, 40 ) : substr( $tag, 0, 40 );
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tag ) : strtolower( $tag );

			if ( '' !== $tag && ! isset( $tags[ $key ] ) ) {
				$tags[ $key ] = $tag;
			}
		}

		return array_slice( array_values( $tags ), 0, self::MAX_TAGS );
	}

	/**
	 * A tag as a token for data attributes and filter buttons.
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	private static function token( $tag ) {
		$token = sanitize_title( $tag );

		return '' !== $token ? $token : 'tag-' . substr( md5( $tag ), 0, 8 );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'games'   => '',
				'tags'    => '',
				'engine'  => '',
				'columns' => '',
				'filter'  => '',
				'open'    => 'lightbox',
				'orderby' => 'name',
				'limit'   => 0,
				'class'   => '',
			),
			$atts,
			self::TAG
		);

		return $this->render( $atts );
	}

	/**
	 * The games a gallery shows, in order.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return array[]
	 */
	private function select( array $atts ) {
		$playable = array_filter(
			$this->games->all(),
			static function ( $game ) {
				return 'ready' === Game_Manager::status( $game ) && Game_Manager::is_active( $game );
			}
		);

		$chosen = array();

		if ( '' !== trim( $atts['games'] ) ) {
			foreach ( explode( ',', $atts['games'] ) as $slug ) {
				$slug = Game_Manager::sanitize_slug( $slug );

				if ( isset( $playable[ $slug ] ) ) {
					$chosen[ $slug ] = $playable[ $slug ];
				}
			}
		} else {
			$chosen = $playable;

			if ( 'date' === $atts['orderby'] ) {
				uasort(
					$chosen,
					static function ( $a, $b ) {
						return max( $b['uploaded'], $b['created'] ) - max( $a['uploaded'], $a['created'] );
					}
				);
			}
		}

		$engine = sanitize_key( $atts['engine'] );

		if ( in_array( $engine, Game_Manager::ENGINES, true ) ) {
			$chosen = array_filter(
				$chosen,
				static function ( $game ) use ( $engine ) {
					return $engine === $game['engine'];
				}
			);
		}

		$wanted = array_map( array( __CLASS__, 'token' ), self::parse_tags( $atts['tags'] ) );

		if ( $wanted ) {
			$chosen = array_filter(
				$chosen,
				static function ( $game ) use ( $wanted ) {
					return (bool) array_intersect( $wanted, array_map( array( __CLASS__, 'token' ), self::fields( $game )['tags'] ) );
				}
			);
		}

		$limit = absint( $atts['limit'] );

		return $limit ? array_slice( $chosen, 0, $limit, true ) : $chosen;
	}

	/**
	 * Render a gallery.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( array $atts ) {
		$games = $this->select( $atts );

		if ( ! $games ) {
			return current_user_can( 'edit_posts' )
				? sprintf( '<div class="banzaiplay-problem" style="padding:1em;border:1px dashed #d63638;color:#d63638;">%s</div>', esc_html__( 'BanzaiPlay: no active games match this gallery.', 'banzaiplay' ) )
				: '';
		}

		$id      = 'banzaiplay-gallery-' . ++$this->count;
		$open    = 'page' === $atts['open'] ? 'page' : 'lightbox';
		$columns = min( 6, absint( $atts['columns'] ) );
		$style   = $columns ? '--banzaiplay-columns:' . $columns . ';' : '';

		// The site's colour from Settings, as on its players.
		foreach ( Branding::accent_vars( (string) $this->settings->get( 'color' ) ) as $property => $value ) {
			$style .= $property . ':' . $value . ';';
		}
		$tags    = array();
		$engines = array();

		foreach ( $games as $game ) {
			foreach ( self::fields( $game )['tags'] as $tag ) {
				$tags[ self::token( $tag ) ] = $tag;
			}

			$engines[ $game['engine'] ] = Game_Manager::engine_label( $game['engine'] );
		}

		// Filter buttons: by tag when any game has tags, unless asked otherwise.
		$filter = in_array( $atts['filter'], array( 'tags', 'engine', 'none' ), true ) ? $atts['filter'] : ( $tags ? 'tags' : 'none' );
		$chips  = 'tags' === $filter ? $tags : ( 'engine' === $filter ? $engines : array() );

		// Nothing to choose between: one game, or every game the same engine.
		if ( count( $games ) < 2 || ( 'engine' === $filter && count( $chips ) < 2 ) ) {
			$chips = array();
		}

		natcasesort( $chips );

		$classes = array( 'banzaiplay-gallery' );

		foreach ( preg_split( '/\s+/', (string) $atts['class'] ) as $class ) {
			$class = sanitize_html_class( $class );

			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
		$this->embed->enqueue_assets();

		ob_start();
		include BZPL_PLUGIN_PATH . 'templates/gallery.php';

		return (string) ob_get_clean();
	}

	/**
	 * A game's lightbox player, rendered now for a <template>.
	 *
	 * @param array $game Record.
	 * @return string
	 */
	public function player( array $game ) {
		return $this->embed->render(
			$game['slug'],
			array(
				// The lightbox starts the game itself, on the click that opened it.
				'autoplay' => false,
				'class'    => 'banzaiplay-in-lightbox',
			)
		);
	}

	/**
	 * The token of each of a game's tags, for data-tags.
	 *
	 * @param array $game Record.
	 * @return string[]
	 */
	public static function tokens( array $game ) {
		return array_map( array( __CLASS__, 'token' ), self::fields( $game )['tags'] );
	}

	/**
	 * The Gallery card.
	 *
	 * @param array $game Record.
	 */
	public function render_card( $game ) {
		$fields   = self::fields( $game );
		$cover    = Branding::cover( $game );
		$all_tags = array();

		foreach ( $this->games->all() as $other ) {
			foreach ( self::fields( $other )['tags'] as $tag ) {
				$all_tags[ strtolower( $tag ) ] = $tag;
			}
		}

		natcasesort( $all_tags );

		include BZPL_PLUGIN_PATH . 'templates/admin-gallery.php';
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
		if ( ! isset( $_POST['bzpl_gallery'] ) ) {
			return $game;
		}

		// A full http(s) URL or a path on this site.
		$page = isset( $_POST['gallery_page'] ) ? esc_url_raw( trim( wp_unslash( $_POST['gallery_page'] ) ), array( 'http', 'https' ) ) : '';

		$game['gallery'] = array(
			'tags' => self::parse_tags( isset( $_POST['gallery_tags'] ) ? wp_unslash( $_POST['gallery_tags'] ) : '' ),
			'page' => $page,
		);
		// phpcs:enable

		return $game;
	}
}
