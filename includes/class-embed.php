<?php
/**
 * Front-end rendering.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a game's player — the one implementation behind both the shortcode
 * and the block, so the two cannot drift.
 *
 * The player is a container holding the "Click to Play" overlay, the loading
 * screen and an empty stage; assets/js/frontend.js creates the game's iframe
 * (see Frame) in the stage when Play is pressed. Nothing of the game itself
 * downloads before then, so a page can hold several 50 MB games and load
 * none of them until a visitor chooses one, and the click is the user
 * gesture browsers want before a game may start its audio.
 *
 * The page only ever loads frontend.js and frontend.css. Engine scripts load
 * inside the game's own frame, and only the one for its engine.
 */
final class Embed {

	const HANDLE = 'banzaiplay-frontend';

	/**
	 * @var Game_Manager
	 */
	private $games;

	/**
	 * @var Frame
	 */
	private $frame;

	/**
	 * Players rendered this request, per game, for unique IDs.
	 *
	 * @var array<string,int>
	 */
	private $count = array();

	/**
	 * Constructor.
	 *
	 * @param Game_Manager $games Game store.
	 * @param Frame        $frame Frame documents.
	 */
	public function __construct( Game_Manager $games, Frame $frame ) {
		$this->games = $games;
		$this->frame = $frame;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'prescan' ) );
	}

	/**
	 * Register the player's script and style, for the front end and for the
	 * preview on the edit screen.
	 */
	public function register_assets() {
		wp_register_style( self::HANDLE, BZPL_PLUGIN_URL . 'assets/css/frontend.css', array(), BZPL_VERSION );
		wp_register_script(
			self::HANDLE,
			BZPL_PLUGIN_URL . 'assets/js/frontend.js',
			/**
			 * Filter the scripts the player's script depends on. A script listed
			 * here runs first, so its listeners for the player's events are in
			 * place before any player starts.
			 *
			 * @param string[] $deps Script handles.
			 */
			(array) apply_filters( 'bzpl/frontend_script_deps', array() ),
			BZPL_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$strings = array(
			'play'           => __( 'Play', 'banzaiplay' ),
			'playAnyway'     => __( 'Play anyway', 'banzaiplay' ),
			'playAgain'      => __( 'Play again', 'banzaiplay' ),
			'loading'        => __( 'Loading…', 'banzaiplay' ),
			/* translators: %d: percentage loaded. */
			'loadingPercent' => __( 'Loading… %d%%', 'banzaiplay' ),
			'starting'       => __( 'Starting…', 'banzaiplay' ),
			'ended'          => __( 'The game has ended.', 'banzaiplay' ),
			'failed'         => __( 'The game could not be loaded.', 'banzaiplay' ),
			'fullscreen'     => __( 'Fullscreen', 'banzaiplay' ),
			'exitFullscreen' => __( 'Exit fullscreen', 'banzaiplay' ),
			'escape'         => __( 'Press Esc to give the keyboard back to the page', 'banzaiplay' ),
			'shift_escape'   => __( 'Press Shift+Esc to give the keyboard back to the page', 'banzaiplay' ),
			'none'           => __( 'Click outside the game to give the keyboard back to the page', 'banzaiplay' ),
			'released'       => __( 'Keyboard released — click the game to keep playing', 'banzaiplay' ),
		);

		wp_add_inline_script( self::HANDLE, 'window.banzaiPlayL10n=' . wp_json_encode( $strings ) . ';', 'before' );
	}

	/**
	 * Enqueue the player's script and style.
	 */
	public function enqueue_assets() {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		/**
		 * Fires when the player's assets are enqueued, for stylesheets and
		 * scripts that go with them.
		 */
		do_action( 'bzpl/enqueue_assets' );
	}

	/**
	 * Enqueue the player's assets before wp_head when the queried post holds
	 * a game, so its CSS lands in <head>. Players rendered elsewhere
	 * (widgets, templates) still work; their CSS prints in the footer.
	 */
	public function prescan() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( $post instanceof \WP_Post && '' !== $post->post_content && self::find_slugs( $post->post_content ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Game slugs referenced by shortcodes and blocks in some content.
	 *
	 * @param string $content Post content.
	 * @return string[]
	 */
	public static function find_slugs( $content ) {
		$slugs = array();

		if ( false !== strpos( $content, '[' . Shortcode::TAG ) ) {
			preg_match_all( '/' . get_shortcode_regex( array( Shortcode::TAG ) ) . '/', $content, $matches );

			foreach ( $matches[3] as $raw ) {
				$atts = shortcode_parse_atts( $raw );

				if ( is_array( $atts ) && ! empty( $atts['game'] ) ) {
					$slugs[] = Game_Manager::sanitize_slug( $atts['game'] );
				}
			}
		}

		if ( function_exists( 'has_block' ) && has_block( Block::NAME, $content ) ) {
			$slugs = array_merge( $slugs, self::block_slugs( parse_blocks( $content ) ) );
		}

		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	/**
	 * Game slugs in a parsed block tree, nested blocks included.
	 *
	 * @param array $blocks From parse_blocks().
	 * @return string[]
	 */
	private static function block_slugs( array $blocks ) {
		$slugs = array();

		foreach ( $blocks as $block ) {
			if ( Block::NAME === $block['blockName'] && ! empty( $block['attrs']['game'] ) ) {
				$slugs[] = Game_Manager::sanitize_slug( $block['attrs']['game'] );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$slugs = array_merge( $slugs, self::block_slugs( $block['innerBlocks'] ) );
			}
		}

		return $slugs;
	}

	/**
	 * Render one player.
	 *
	 * @param string $slug Game slug.
	 * @param array  $args {
	 *     @type int    $width      Player width, overriding the game's.
	 *     @type int    $height     Player height, overriding the game's.
	 *     @type string $class      Extra classes.
	 *     @type bool|null $autoplay Start with the page instead of on Play; null
	 *                               (or absent) for the game's own setting.
	 *     @type bool   $fullscreen Show the fullscreen button.
	 *     @type bool   $preview    Render an inactive game too, for those who manage games.
	 * }
	 * @return string HTML.
	 */
	public function render( $slug, array $args = array() ) {
		$slug = Game_Manager::sanitize_slug( (string) $slug );
		$game = '' === $slug ? null : $this->games->get( $slug );

		if ( ! $game ) {
			/* translators: %s: game slug. */
			return $this->problem( sprintf( __( 'No game with the slug "%s" exists.', 'banzaiplay' ), $slug ) );
		}

		if ( 'ready' !== Game_Manager::status( $game ) ) {
			/* translators: 1: game name, 2: status such as "No build uploaded". */
			return $this->problem( sprintf( __( '"%1$s" is not ready to play: %2$s.', 'banzaiplay' ), $game['name'], Game_Manager::status_label( Game_Manager::status( $game ) ) ) );
		}

		if ( ! Game_Manager::is_active( $game ) && ! ( ! empty( $args['preview'] ) && Admin::can_manage() ) ) {
			/* translators: %s: game name. */
			return $this->problem( sprintf( __( '"%s" is inactive, so visitors see nothing here. Activate it under BanzaiPlay → All Games.', 'banzaiplay' ), $game['name'] ) );
		}

		list( $native_w, $native_h ) = Game_Manager::dimensions( $game );
		list( $width, $height )      = self::size( $native_w, $native_h, isset( $args['width'] ) ? absint( $args['width'] ) : 0, isset( $args['height'] ) ? absint( $args['height'] ) : 0 );

		$this->count[ $slug ] = isset( $this->count[ $slug ] ) ? $this->count[ $slug ] + 1 : 1;

		$id = 'banzaiplay-' . $slug . ( $this->count[ $slug ] > 1 ? '-' . $this->count[ $slug ] : '' );

		// The page's choice, else the game's. The edit screen's preview always
		// waits for Play: opening a game's settings shouldn't start a 50 MB
		// download.
		if ( ! empty( $args['preview'] ) ) {
			$autoplay = false;
		} elseif ( isset( $args['autoplay'] ) ) {
			$autoplay = (bool) $args['autoplay'];
		} else {
			$autoplay = Game_Manager::starts_on_load( $game );
		}

		$fullscreen = ! isset( $args['fullscreen'] ) || (bool) $args['fullscreen'];
		$classes    = array( 'banzaiplay-container', 'banzaiplay-engine-' . $game['engine'] );

		foreach ( preg_split( '/\s+/', isset( $args['class'] ) ? (string) $args['class'] : '' ) as $class ) {
			$class = sanitize_html_class( $class );

			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}

		$config = array(
			'game'        => $slug,
			'src'         => $this->frame->url( $game ),
			'title'       => $game['name'],
			'engine'      => $game['engine'],
			'fit'         => in_array( $game['fit'], Game_Manager::FITS, true ) ? $game['fit'] : 'resize',
			'native'      => array( $native_w, $native_h ),
			'release'     => Game_Manager::release_key( $game ),
			'autoplay'    => $autoplay,
			'desktopOnly' => ! empty( $game['desktop_only'] ),
			'preview'     => ! empty( $args['preview'] ),
		);

		/**
		 * Filter what frontend.js is told about a player (its data-banzaiplay
		 * JSON). The same for every visitor: pages with players get cached.
		 *
		 * @param array $config Player config.
		 * @param array $game   Game record.
		 * @param array $args   Render arguments.
		 */
		$config = (array) apply_filters( 'bzpl/player_config', $config, $game, $args );

		/**
		 * Filter the player container's classes.
		 *
		 * @param string[] $classes Class names, already sanitised.
		 * @param array    $game    Game record.
		 */
		$classes = array_map( 'sanitize_html_class', (array) apply_filters( 'bzpl/player_classes', $classes, $game ) );

		/**
		 * Filter the player container's CSS custom properties — the colours in
		 * frontend.css are --banzaiplay-accent and friends.
		 *
		 * @param array<string,string> $vars Property (starting --) => value.
		 * @param array                $game Game record.
		 */
		$vars  = (array) apply_filters( 'bzpl/player_style', array(), $game );
		$style = sprintf( '--banzaiplay-ar:%s;max-width:%dpx;', self::ratio( $width, $height ), $width );

		foreach ( $vars as $property => $value ) {
			if ( preg_match( '/^--[a-z0-9-]+$/', $property ) && ! preg_match( '/[;{}<>]/', (string) $value ) ) {
				$style .= $property . ':' . $value . ';';
			}
		}

		/**
		 * Filter the logo shown above the game's title on the Play and loading
		 * screens: markup, or '' for none.
		 *
		 * @param string $html Logo markup, escaped.
		 * @param array  $game Game record.
		 */
		$logo = (string) apply_filters( 'bzpl/player_logo', '', $game );

		$this->enqueue_assets();

		ob_start();
		?>
<div id="<?php echo esc_attr( $id ); ?>" class="<?php echo esc_attr( implode( ' ', array_unique( array_filter( $classes ) ) ) ); ?>" data-game="<?php echo esc_attr( $slug ); ?>" data-engine="<?php echo esc_attr( $game['engine'] ); ?>" data-banzaiplay="<?php echo esc_attr( wp_json_encode( $config ) ); ?>" style="<?php echo esc_attr( $style ); ?>" tabindex="-1">
	<div class="banzaiplay-viewport">
		<div class="banzaiplay-stage"></div>
		<div class="banzaiplay-overlay banzaiplay-click-to-play"<?php echo $autoplay ? ' hidden' : ''; ?>>
			<?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the filter. ?>
			<div class="banzaiplay-game-title"><?php echo esc_html( $game['name'] ); ?></div>
			<?php if ( '' !== $game['description'] ) : ?>
				<p class="banzaiplay-description"><?php echo esc_html( $game['description'] ); ?></p>
			<?php endif; ?>
			<button type="button" class="banzaiplay-play-btn">
				<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z" fill="currentColor"/></svg>
				<span class="banzaiplay-play-label"><?php esc_html_e( 'Play', 'banzaiplay' ); ?></span>
			</button>
			<?php if ( $config['desktopOnly'] ) : ?>
				<p class="banzaiplay-mobile-note" hidden><?php esc_html_e( 'This game is best experienced on a desktop computer.', 'banzaiplay' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="banzaiplay-loading" hidden>
			<?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the filter. ?>
			<div class="banzaiplay-loading-title"><?php echo esc_html( $game['name'] ); ?></div>
			<?php /* translators: %s: game name. */ ?>
			<div class="banzaiplay-progress-bar" role="progressbar" aria-label="<?php echo esc_attr( sprintf( __( 'Loading %s', 'banzaiplay' ), $game['name'] ) ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<div class="banzaiplay-progress-fill"></div>
			</div>
			<div class="banzaiplay-progress-text"><?php esc_html_e( 'Loading…', 'banzaiplay' ); ?></div>
			<?php if ( ! bzpl_has_valid_license() ) : ?>
				<div class="banzaiplay-branding"><?php esc_html_e( 'Powered by BanzaiPlay', 'banzaiplay' ); ?></div>
			<?php endif; ?>
		</div>
		<div class="banzaiplay-message" role="alert" hidden>
			<p class="banzaiplay-message-text"></p>
			<button type="button" class="banzaiplay-retry-btn"><?php esc_html_e( 'Try again', 'banzaiplay' ); ?></button>
		</div>
	</div>
	<div class="banzaiplay-controls" hidden>
		<span class="banzaiplay-hint" aria-live="polite"></span>
		<?php if ( $fullscreen ) : ?>
			<button type="button" class="banzaiplay-fullscreen-btn" title="<?php esc_attr_e( 'Fullscreen', 'banzaiplay' ); ?>" aria-label="<?php esc_attr_e( 'Fullscreen', 'banzaiplay' ); ?>" hidden>
				<svg class="banzaiplay-icon-enter" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				<svg class="banzaiplay-icon-exit" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M9 4v5H4M20 9h-5V4M15 20v-5h5M4 15h5v5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</button>
		<?php endif; ?>
	</div>
	<noscript><p class="banzaiplay-noscript"><?php esc_html_e( 'This game needs JavaScript.', 'banzaiplay' ); ?></p></noscript>
</div>
		<?php
		$html = (string) ob_get_clean();

		/**
		 * Filter a game player's markup.
		 *
		 * @param string $html Markup.
		 * @param array  $game Game record.
		 * @param string $id   Container element ID.
		 */
		return (string) apply_filters( 'bzpl/player_html', $html, $game, $id );
	}

	/**
	 * A player's size: the game's own, or the override, keeping the game's
	 * aspect ratio when only one side is given.
	 *
	 * @param int $width   Game width.
	 * @param int $height  Game height.
	 * @param int $want_w  Requested width, or 0.
	 * @param int $want_h  Requested height, or 0.
	 * @return int[] [ width, height ]
	 */
	public static function size( $width, $height, $want_w, $want_h ) {
		if ( $want_w && $want_h ) {
			$width  = $want_w;
			$height = $want_h;
		} elseif ( $want_w ) {
			$height = (int) round( $want_w * $height / $width );
			$width  = $want_w;
		} elseif ( $want_h ) {
			$width  = (int) round( $want_h * $width / $height );
			$height = $want_h;
		}

		return array( min( 8192, max( 50, $width ) ), min( 8192, max( 50, $height ) ) );
	}

	/**
	 * Width over height, for CSS.
	 *
	 * @param int $width  Pixels.
	 * @param int $height Pixels.
	 * @return string
	 */
	private static function ratio( $width, $height ) {
		return rtrim( rtrim( sprintf( '%.6F', $width / $height ), '0' ), '.' );
	}

	/**
	 * A visible explanation for people who can fix it; nothing for visitors.
	 *
	 * @param string $message Plain-text message.
	 * @return string
	 */
	private function problem( $message ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return sprintf(
			'<div class="banzaiplay-problem" style="padding:1em;border:1px dashed #d63638;color:#d63638;">%s %s</div>',
			esc_html__( 'BanzaiPlay:', 'banzaiplay' ),
			esc_html( $message )
		);
	}
}
