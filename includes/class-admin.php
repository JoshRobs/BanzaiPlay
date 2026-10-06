<?php
/**
 * Admin screens.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * The BanzaiPlay menu: the game list, the add/edit screen, and the form
 * handlers behind them.
 *
 * Forms post to admin-post.php rather than AJAX. A zip upload is a full round
 * trip either way, and a plain post works with JavaScript off and gives every
 * error a page to land on. (admin.js sends the same post with XMLHttpRequest
 * to show upload progress, then follows the redirect.)
 *
 * Who may manage games: `manage_options` AND `unfiltered_html`. Uploading a
 * game is uploading JavaScript that runs for every visitor — exactly what
 * `unfiltered_html` exists to gate. On multisite, site admins do not have it,
 * and without the second check this screen would be a way round that.
 */
final class Admin {

	const PAGE = 'banzaiplay';

	const PAGE_NEW = 'banzaiplay-new';

	const SAVE_ACTION = 'banzaiplay_save_game';

	const DELETE_ACTION = 'banzaiplay_delete_game';

	const TOGGLE_ACTION = 'banzaiplay_toggle_game';

	/**
	 * @var Game_Manager
	 */
	private $games;

	/**
	 * @var Embed
	 */
	private $embed;

	/**
	 * @var Uploader
	 */
	private $uploader;

	/**
	 * @var Engine_Detector
	 */
	private $detector;

	/**
	 * @var Path_Rewriter
	 */
	private $rewriter;

	/**
	 * Hook suffixes of our screens, from add_menu_page()/add_submenu_page().
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Game_Manager    $games    Game store.
	 * @param Embed           $embed    Player renderer, for the preview.
	 * @param Uploader        $uploader Zip extraction.
	 * @param Engine_Detector $detector Engine detection.
	 * @param Path_Rewriter   $rewriter Base-path relocation.
	 */
	public function __construct( Game_Manager $games, Embed $embed, Uploader $uploader, Engine_Detector $detector, Path_Rewriter $rewriter ) {
		$this->games    = $games;
		$this->embed    = $embed;
		$this->uploader = $uploader;
		$this->detector = $detector;
		$this->rewriter = $rewriter;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Whether the current user may manage games.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
	}

	/**
	 * Add the menu.
	 */
	public function add_pages() {
		$this->hooks[] = add_menu_page(
			__( 'BanzaiPlay', 'banzaiplay' ),
			__( 'BanzaiPlay', 'banzaiplay' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_main' ),
			self::menu_icon(),
			81
		);

		$this->hooks[] = add_submenu_page(
			self::PAGE,
			__( 'All Games', 'banzaiplay' ),
			__( 'All Games', 'banzaiplay' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_main' )
		);

		$this->hooks[] = add_submenu_page(
			self::PAGE,
			__( 'Add New Game', 'banzaiplay' ),
			__( 'Add New', 'banzaiplay' ),
			'manage_options',
			self::PAGE_NEW,
			array( $this, 'render_new' )
		);

		/**
		 * Add screens under the BanzaiPlay menu, with Admin::add_screen().
		 *
		 * @param Admin $admin This.
		 */
		do_action( 'bzpl/admin_menu', $this );

		$this->hooks = array_values( array_filter( array_unique( $this->hooks ) ) );
	}

	/**
	 * Add a screen under the BanzaiPlay menu that gets the plugin's chrome:
	 * stylesheet, script and brand bar (render it with render_screen()).
	 *
	 * @param string   $title    Page title.
	 * @param string   $menu     Menu label.
	 * @param string   $slug     Page slug.
	 * @param callable $callback Renders the screen.
	 * @return string Hook suffix.
	 */
	public function add_screen( $title, $menu, $slug, $callback ) {
		$hook          = (string) add_submenu_page( self::PAGE, $title, $menu, 'manage_options', $slug, $callback );
		$this->hooks[] = $hook;

		return $hook;
	}

	/**
	 * Render a screen's template inside the plugin's chrome.
	 *
	 * @param string $file Absolute path of the template.
	 * @param array  $vars Variables for the template.
	 */
	public function render_screen( $file, array $vars = array() ) {
		$this->render_template( $file, $vars );
	}

	/**
	 * Whether the current admin screen is one of ours.
	 *
	 * @return bool
	 */
	private function is_own_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && in_array( $screen->id, $this->hooks, true );
	}

	/**
	 * Mark our screens so the stylesheet can run the brand bar edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		return $this->is_own_screen() ? $classes . ' bzpl-admin-page' : $classes;
	}

	/**
	 * Admin assets, on our screens only — plus the player's, for the
	 * preview on the edit screen.
	 *
	 * @param string $hook Screen hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'bzpl-admin', BZPL_PLUGIN_URL . 'assets/css/admin.css', array(), BZPL_VERSION );
		wp_enqueue_script( 'bzpl-admin', BZPL_PLUGIN_URL . 'assets/js/admin.js', array( 'wp-i18n' ), BZPL_VERSION, true );
		wp_set_script_translations( 'bzpl-admin', 'banzaiplay' );

		if ( self::is_edit_screen() ) {
			$this->embed->enqueue_assets();
		}

		/**
		 * Fires after the admin assets are enqueued on a BanzaiPlay screen.
		 *
		 * @param string $hook Screen hook suffix.
		 */
		do_action( 'bzpl/admin_enqueue', $hook );
	}

	/**
	 * Whether this is a game's edit screen.
	 *
	 * @return bool
	 */
	public static function is_edit_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'], $_GET['action'] ) && self::PAGE === $_GET['page'] && 'edit' === $_GET['action'];
	}

	/**
	 * URL of the list screen.
	 *
	 * @param array $args Query arguments: engine, status, s, orderby, order.
	 * @return string
	 */
	public static function list_url( array $args = array() ) {
		return add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/**
	 * URL of the Add New screen.
	 *
	 * @return string
	 */
	public static function new_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_NEW );
	}

	/**
	 * URL of a game's edit screen.
	 *
	 * @param string $slug Game slug.
	 * @return string
	 */
	public static function edit_url( $slug ) {
		return add_query_arg(
			array(
				'page'   => self::PAGE,
				'action' => 'edit',
				'game'   => $slug,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Nonced URL that deletes a game.
	 *
	 * @param string $slug Game slug.
	 * @return string
	 */
	public static function delete_url( $slug ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DELETE_ACTION,
					'game'   => $slug,
				),
				admin_url( 'admin-post.php' )
			),
			self::DELETE_ACTION . '_' . $slug
		);
	}

	/**
	 * The brand mark: a play button in the Banzai gradient frame.
	 *
	 * @param int $size Pixels.
	 * @return string SVG markup; static apart from the size.
	 */
	public static function logo( $size = 36 ) {
		static $n = 0;

		$id = 'bzpl-logo-gradient-' . ++$n;

		return sprintf(
			'<svg class="bzpl-logo" width="%1$d" height="%1$d" viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
			. '<defs><linearGradient id="%2$s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe200"/><stop offset="1" stop-color="#ff7000"/></linearGradient></defs>'
			. '<rect width="32" height="32" rx="7" fill="#282828"/>'
			. '<rect x="6.5" y="6.5" width="19" height="19" rx="3.5" fill="none" stroke="url(#%2$s)" stroke-width="2"/>'
			. '<path d="M13.5 11.8v8.4a.6.6 0 0 0 .9.5l6.6-4.2a.6.6 0 0 0 0-1l-6.6-4.2a.6.6 0 0 0-.9.5z" fill="url(#%2$s)"/>'
			. '</svg>',
			(int) $size,
			$id
		);
	}

	/**
	 * The admin menu icon: the logo as a single-colour shape, which WordPress
	 * recolours to match the admin colour scheme.
	 *
	 * @return string Data URI.
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M5 2h10a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V5a3 3 0 0 1 3-3zm0 2a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1zm3 2.8v6.4a.6.6 0 0 0 .9.5l5-3.2a.6.6 0 0 0 0-1l-5-3.2a.6.6 0 0 0-.9.5z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon data URI, the format add_menu_page() takes.
	}

	/**
	 * An engine badge: a coloured dot and the engine's name.
	 *
	 * @param string $engine One of Game_Manager::ENGINES.
	 * @return string HTML.
	 */
	public static function engine_badge( $engine ) {
		return sprintf(
			'<span class="bzpl-engine bzpl-engine-%s"><span class="bzpl-engine-dot" aria-hidden="true"></span>%s</span>',
			esc_attr( $engine ),
			esc_html( Game_Manager::engine_label( $engine ) )
		);
	}

	/**
	 * The All Games screen, or the edit screen when ?action=edit.
	 */
	public function render_main() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$slug   = isset( $_GET['game'] ) ? Game_Manager::sanitize_slug( wp_unslash( $_GET['game'] ) ) : '';
		// phpcs:enable

		if ( 'edit' === $action ) {
			$game = $this->games->get( $slug );

			if ( ! $game ) {
				$this->render_template( 'admin-missing', array() );
				return;
			}

			$this->render_edit( $game );
			return;
		}

		$this->render_list();
	}

	/**
	 * The All Games screen: the current tab's games, filtered and sorted.
	 */
	private function render_list() {
		$query  = $this->list_query();
		$in_tab = array_filter(
			$this->games->all(),
			static function ( $game ) use ( $query ) {
				return '' === $query['engine'] || $query['engine'] === $game['engine'];
			}
		);

		$active = count( array_filter( $in_tab, array( Game_Manager::class, 'is_active' ) ) );
		$counts = array(
			'all'      => count( $in_tab ),
			'active'   => $active,
			'inactive' => count( $in_tab ) - $active,
		);

		$games = array_filter(
			$in_tab,
			static function ( $game ) use ( $query ) {
				if ( '' !== $query['status'] && ( 'active' === $query['status'] ) !== Game_Manager::is_active( $game ) ) {
					return false;
				}

				return '' === $query['s']
					|| false !== stripos( $game['name'], $query['s'] )
					|| false !== stripos( $game['slug'], $query['s'] );
			}
		);

		uasort(
			$games,
			static function ( $a, $b ) use ( $query ) {
				if ( 'uploaded' === $query['orderby'] ) {
					$cmp = $a['uploaded'] - $b['uploaded'];
				} elseif ( 'size' === $query['orderby'] ) {
					$cmp = $a['size'] - $b['size'];
				} else {
					$cmp = strcasecmp( $a['name'], $b['name'] );
				}

				return 'desc' === $query['order'] ? -$cmp : $cmp;
			}
		);

		$this->render_template(
			'admin-list',
			array(
				'games'  => $games,
				'query'  => $query,
				'counts' => $counts,
				'total'  => count( $this->games->all() ),
			),
			'' !== $query['engine'] ? $query['engine'] : 'all'
		);
	}

	/**
	 * The list screen's filters, from the URL.
	 *
	 * @return array { engine, status, s, orderby, order }
	 */
	private function list_query() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$engine  = isset( $_GET['engine'] ) ? sanitize_key( wp_unslash( $_GET['engine'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
		// phpcs:enable

		$orderby = in_array( $orderby, array( 'uploaded', 'size' ), true ) ? $orderby : 'name';

		return array(
			'engine'  => in_array( $engine, Game_Manager::ENGINES, true ) ? $engine : '',
			'status'  => in_array( $status, array( 'active', 'inactive' ), true ) ? $status : '',
			's'       => $search,
			'orderby' => $orderby,
			// Names A→Z, dates and sizes biggest first, unless asked otherwise.
			'order'   => in_array( $order, array( 'asc', 'desc' ), true ) ? $order : ( 'name' === $orderby ? 'asc' : 'desc' ),
		);
	}

	/**
	 * The Add New screen.
	 */
	public function render_new() {
		$this->render_edit( null );
	}

	/**
	 * The add/edit form.
	 *
	 * @param array|null $game Record, or null for a new game.
	 */
	private function render_edit( $game ) {
		$this->render_template(
			'admin-edit',
			array(
				'game'   => $game,
				'is_new' => null === $game,
				'limits' => self::upload_limits(),
				'embed'  => $this->embed,
			),
			null === $game ? 'new' : ''
		);
	}

	/**
	 * What limits a build upload on this server, for the upload card.
	 *
	 * @return array { max: int, upload_max_filesize: string, post_max_size: string, zip: bool }
	 */
	public static function upload_limits() {
		return array(
			'max'                 => (int) wp_max_upload_size(),
			'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'       => (string) ini_get( 'post_max_size' ),
			'zip'                 => class_exists( '\ZipArchive' ),
		);
	}

	/**
	 * Include a template with the given variables, wrapped in the common
	 * chrome: brand bar and tabs, capability gate and notices.
	 *
	 * @param string $name Template name, without .php, or an absolute path.
	 * @param array  $vars Variables for the template.
	 * @param string $tab  Highlighted tab: 'all', an engine, 'new' or ''.
	 */
	private function render_template( $name, array $vars, $tab = '' ) {
		$bzpl_template = '.php' === substr( $name, -4 ) ? $name : BZPL_PLUGIN_PATH . 'templates/' . $name . '.php';

		$this->render_header( $tab );

		echo '<div class="wrap bzpl-wrap">';

		if ( ! self::can_manage() ) {
			printf(
				'<h1>%s</h1><div class="notice notice-error"><p>%s</p></div></div>',
				esc_html__( 'BanzaiPlay', 'banzaiplay' ),
				esc_html__( 'Managing games uploads JavaScript that runs for every visitor, so it needs the unfiltered_html capability as well as manage_options. Your account does not have it — on multisite, ask a network administrator.', 'banzaiplay' )
			);
			return;
		}

		$this->print_notices();

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template variables.
		extract( $vars, EXTR_SKIP );
		include $bzpl_template;

		echo '</div>';
	}

	/**
	 * The brand bar and engine tabs.
	 *
	 * @param string $tab Highlighted tab.
	 */
	private function render_header( $tab ) {
		$counts = array( 'all' => 0 ) + array_fill_keys( Game_Manager::ENGINES, 0 );

		foreach ( $this->games->all() as $game ) {
			++$counts['all'];

			if ( isset( $counts[ $game['engine'] ] ) ) {
				++$counts[ $game['engine'] ];
			}
		}

		include BZPL_PLUGIN_PATH . 'templates/admin-header.php';
	}

	/**
	 * Create or update a game, with an optional build upload.
	 */
	public function handle_save() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage BanzaiPlay games.', 'banzaiplay' ), 403 );
		}

		// Over post_max_size PHP throws the whole body away, nonce included,
		// and check_admin_referer() would show a baffling "link expired".
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nothing is read or changed.
		if ( empty( $_POST ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) ) {
			$this->fail(
				wp_get_referer() ? wp_get_referer() : self::list_url(),
				/* translators: %s: maximum upload size, e.g. "64 MB". */
				sprintf( __( 'The zip is larger than this server accepts (%s). Raise upload_max_filesize and post_max_size — see "Uploading large builds" below the upload box.', 'banzaiplay' ), size_format( wp_max_upload_size() ) )
			);
		}

		check_admin_referer( self::SAVE_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$original = isset( $_POST['original_slug'] ) ? Game_Manager::sanitize_slug( wp_unslash( $_POST['original_slug'] ) ) : '';
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		// phpcs:enable

		$is_new = '' === $original;
		$back   = $is_new ? self::new_url() : self::edit_url( $original );

		if ( $is_new ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			$wanted = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			$slug   = Game_Manager::sanitize_slug( '' !== $wanted ? $wanted : $name );
			$game   = Game_Manager::defaults();

			if ( '' === $slug ) {
				$this->fail( $back, __( 'Give the game a name.', 'banzaiplay' ) );
			}

			if ( $this->games->get( $slug ) ) {
				$this->fail(
					$back,
					/* translators: 1: taken slug, 2: suggested slug. */
					sprintf( __( 'A game with the slug "%1$s" already exists. Try "%2$s".', 'banzaiplay' ), $slug, $this->games->unique_slug( $slug ) )
				);
			}

			$game['slug'] = $slug;
		} else {
			$game = $this->games->get( $original );

			if ( ! $game ) {
				$this->fail( self::list_url(), __( 'That game no longer exists.', 'banzaiplay' ) );
			}
		}

		if ( '' === $name ) {
			$this->fail( $back, __( 'Give the game a name.', 'banzaiplay' ) );
		}

		$game = $this->read_settings( $game, $name );

		/**
		 * Filter a game record as the edit form saves it, after the nonce and
		 * capability checks. Feature modules read their own fields from $_POST here.
		 *
		 * @param array $game   Record about to be saved.
		 * @param bool  $is_new Whether the game is being created.
		 */
		$game = (array) apply_filters( 'bzpl/save_game', $game, $is_new );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$has_zip = isset( $_FILES['build'] ) && is_array( $_FILES['build'] ) && ! empty( $_FILES['build']['name'] );

		if ( $has_zip ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; the uploader validates the file.
			$game = $this->apply_upload( $game, $_FILES['build'] );
		} elseif ( ! $is_new && '' !== $game['build'] ) {
			$game = $this->apply_engine( $game );
		}

		$this->games->save( $game );

		if ( $is_new ) {
			/* translators: %s: game name. */
			$this->notice( 'success', sprintf( __( '"%s" created.', 'banzaiplay' ), $game['name'] ) );
		} elseif ( ! $has_zip ) {
			$this->notice( 'success', __( 'Game saved.', 'banzaiplay' ) );
		}

		$this->redirect( self::edit_url( $game['slug'] ) );
	}

	/**
	 * Go to a screen after a form post. admin.js sends uploads with
	 * XMLHttpRequest and `ajax=1`, and gets the URL as JSON instead: an XHR
	 * would follow a redirect itself, rendering the screen — and using up the
	 * queued notices — before the browser got there.
	 *
	 * @param string $url Where to go.
	 */
	private function redirect( $url ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only chooses the response format.
		if ( ! empty( $_POST['ajax'] ) ) {
			wp_send_json( array( 'redirect' => $url ) );
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Read the edit form's settings into a record.
	 *
	 * @param array  $game Record.
	 * @param string $name Sanitised name.
	 * @return array
	 */
	private function read_settings( array $game, $name ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_save().
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$fit         = isset( $_POST['fit'] ) ? sanitize_key( wp_unslash( $_POST['fit'] ) ) : 'resize';
		$release     = isset( $_POST['release_key'] ) ? sanitize_key( wp_unslash( $_POST['release_key'] ) ) : 'escape';
		$start       = isset( $_POST['start'] ) ? sanitize_key( wp_unslash( $_POST['start'] ) ) : 'click';
		$width       = isset( $_POST['width'] ) ? absint( wp_unslash( $_POST['width'] ) ) : 0;
		$height      = isset( $_POST['height'] ) ? absint( wp_unslash( $_POST['height'] ) ) : 0;

		$game['name']         = $name;
		$game['description']  = function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 500 ) : substr( $description, 0, 500 );
		$game['active']       = ! empty( $_POST['active'] );
		$game['desktop_only'] = ! empty( $_POST['desktop_only'] );
		$game['fit']          = in_array( $fit, Game_Manager::FITS, true ) ? $fit : 'resize';
		$game['release_key']  = in_array( $release, Game_Manager::RELEASE_KEYS, true ) ? $release : 'escape';
		$game['start']        = in_array( $start, Game_Manager::STARTS, true ) ? $start : 'click';

		// Both sides or neither: one side alone has no aspect ratio to keep.
		$valid          = $width >= 100 && $width <= 8192 && $height >= 100 && $height <= 8192;
		$game['width']  = $valid ? $width : 0;
		$game['height'] = $valid ? $height : 0;

		if ( ! $valid && ( $width || $height ) ) {
			$this->notice( 'warning', __( 'The width and height were not saved: give both, between 100 and 8192 pixels, or leave both empty to use the build\'s own.', 'banzaiplay' ) );
		}

		if ( isset( $_POST['engine_override'] ) ) {
			$engine                  = sanitize_key( wp_unslash( $_POST['engine_override'] ) );
			$game['engine_override'] = in_array( $engine, Game_Manager::ENGINES, true ) ? $engine : '';
		}

		if ( isset( $_POST['entry_override'] ) ) {
			$game['entry_override'] = sanitize_text_field( wp_unslash( $_POST['entry_override'] ) );
		}
		// phpcs:enable

		return $game;
	}

	/**
	 * Unpack an uploaded build, detect its engine, relocate it if it was
	 * built for another path, and make it the game's build. On failure the
	 * game keeps the build it had.
	 *
	 * @param array $game Record.
	 * @param array $file $_FILES entry.
	 * @return array Updated record.
	 */
	private function apply_upload( array $game, array $file ) {
		$result = $this->uploader->extract( $file );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );

			return $game;
		}

		// Choices made for the previous build may not fit this one.
		$found = $this->detector->analyze( $result['dir'], $game['engine_override'], $game['entry_override'] );

		$game['base']              = '';
		$game['relocated']         = 0;
		$game['preload_relocated'] = false;
		$game['needs_rebuild']     = false;

		if ( isset( $found['data']['mode'] ) && 'html' === $found['data']['mode'] && '' !== $found['base'] ) {
			$folder = self::dir_of( $found['entry'] );
			$moved  = $this->rewriter->rewrite( untrailingslashit( $result['dir'] . '/' . $folder ), $found['base'], $this->games->game_ref( $game['slug'] ) . $folder );

			$game['base']              = $found['base'];
			$game['relocated']         = $moved['references'];
			$game['preload_relocated'] = $moved['preload'];
			$game['needs_rebuild']     = Path_Rewriter::needs_rebuild( untrailingslashit( $result['dir'] . '/' . $folder ), basename( $found['entry'] ), $moved['preload'] );

			if ( $moved['references'] || $moved['preload'] ) {
				$this->notice(
					'info',
					sprintf(
						/* translators: 1: base path such as "/", 2: number of references, 3: number of files. */
						__( 'This build was compiled for the path %1$s, so %2$s references to its scripts, images and other files in %3$s files were pointed at their new location.', 'banzaiplay' ),
						$found['base'],
						number_format_i18n( $moved['references'] ),
						number_format_i18n( $moved['files'] )
					)
				);
			}
		}

		$committed = $this->uploader->commit( $game['slug'], $result['dir'] );

		if ( is_wp_error( $committed ) ) {
			$this->uploader->discard( $result['dir'] );
			$this->notice( 'error', $committed->get_error_message() );

			return $game;
		}

		$game             = $this->apply_found( $game, $found );
		$game['build']    = gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		$game['uploaded'] = time();
		$game['size']     = $result['bytes'];
		$game['files']    = $result['files'];

		$this->notice(
			'success',
			sprintf(
				/* translators: 1: engine name, 2: number of files, 3: total size. */
				__( 'Build uploaded: %1$s, %2$s files, %3$s.', 'banzaiplay' ),
				Game_Manager::engine_label( $game['engine'] ),
				number_format_i18n( $result['files'] ),
				size_format( $result['bytes'] )
			)
		);

		if ( $result['skipped'] ) {
			$this->notice(
				'warning',
				__( 'These files were left out because they are not static game files, or their path was unsafe:', 'banzaiplay' ),
				array_slice( $result['skipped'], 0, 50 )
			);
		}

		return $game;
	}

	/**
	 * Re-analyse the current build when the engine or entry chosen on the
	 * edit screen changed.
	 *
	 * @param array $game Record, with the form's choices read in.
	 * @return array Updated record.
	 */
	private function apply_engine( array $game ) {
		$stored = $this->games->get( $game['slug'] );

		if ( $stored && $stored['engine_override'] === $game['engine_override'] && $stored['entry_override'] === $game['entry_override'] ) {
			return $game;
		}

		// A new engine has different candidate entries.
		if ( $stored && $stored['engine_override'] !== $game['engine_override'] ) {
			$game['entry_override'] = '';
		}

		$found = $this->detector->analyze( $this->games->game_dir( $game['slug'] ), $game['engine_override'], $game['entry_override'] );

		return $this->apply_found( $game, $found );
	}

	/**
	 * Copy an analysis onto a record.
	 *
	 * @param array $game  Record.
	 * @param array $found From Engine_Detector::analyze().
	 * @return array
	 */
	private function apply_found( array $game, array $found ) {
		$game['detected_engine'] = $found['detected_engine'];
		$game['detected_by']     = $found['detected_by'];
		$game['engine']          = $found['engine'];
		$game['entry']           = $found['entry'];
		$game['entries']         = $found['entries'];
		$game['engine_data']     = $found['data'];
		$game['detected_width']  = $found['width'];
		$game['detected_height'] = $found['height'];
		$game['warnings']        = $found['warnings'];

		// An entry chosen for an earlier build that this one lacks.
		if ( '' !== $game['entry_override'] && ! in_array( $game['entry_override'], $found['entries'], true ) ) {
			$game['entry_override'] = '';
		}

		return $game;
	}

	/**
	 * The folder part of a relative path: '' or 'a/b/'.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private static function dir_of( $path ) {
		$slash = strrpos( $path, '/' );

		return false === $slash ? '' : substr( $path, 0, $slash + 1 );
	}

	/**
	 * Delete a game and its files.
	 */
	public function handle_delete() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage BanzaiPlay games.', 'banzaiplay' ), 403 );
		}

		$slug = isset( $_GET['game'] ) ? Game_Manager::sanitize_slug( wp_unslash( $_GET['game'] ) ) : '';
		check_admin_referer( self::DELETE_ACTION . '_' . $slug );

		$game = $this->games->get( $slug );

		if ( $game ) {
			$this->games->delete( $slug );
			/* translators: %s: game name. */
			$this->notice( 'success', sprintf( __( '"%s" deleted.', 'banzaiplay' ), $game['name'] ) );
		}

		wp_safe_redirect( self::list_url() );
		exit;
	}

	/**
	 * Switch a game on or off from the list screen.
	 *
	 * A plain form post that redirects back, so the switch works without
	 * JavaScript; admin.js sends the same form with `ajax=1` and gets JSON.
	 */
	public function handle_toggle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below, before anything changes.
		$ajax = ! empty( $_POST['ajax'] );
		$slug = isset( $_POST['game'] ) ? Game_Manager::sanitize_slug( wp_unslash( $_POST['game'] ) ) : '';
		$on   = ! empty( $_POST['active'] );
		// phpcs:enable

		if ( ! self::can_manage() ) {
			$message = __( 'You are not allowed to manage BanzaiPlay games.', 'banzaiplay' );

			if ( $ajax ) {
				wp_send_json_error( $message, 403 );
			}

			wp_die( esc_html( $message ), 403 );
		}

		if ( $ajax ) {
			if ( ! check_ajax_referer( self::TOGGLE_ACTION . '_' . $slug, '_wpnonce', false ) ) {
				wp_send_json_error( __( 'Your session has expired. Reload the page and try again.', 'banzaiplay' ), 403 );
			}
		} else {
			check_admin_referer( self::TOGGLE_ACTION . '_' . $slug );
		}

		$game = $this->games->get( $slug );
		$back = wp_get_referer() ? wp_get_referer() : self::list_url();

		if ( ! $game ) {
			if ( $ajax ) {
				wp_send_json_error( __( 'That game no longer exists.', 'banzaiplay' ), 404 );
			}

			$this->fail( $back, __( 'That game no longer exists.', 'banzaiplay' ) );
		}

		$game['active'] = $on;
		$this->games->save( $game );

		$message = $on
			/* translators: %s: game name. */
			? sprintf( __( '"%s" activated.', 'banzaiplay' ), $game['name'] )
			/* translators: %s: game name. */
			: sprintf( __( '"%s" deactivated. Pages that embed it now show nothing to visitors.', 'banzaiplay' ), $game['name'] );

		if ( $ajax ) {
			wp_send_json_success(
				array(
					'active'  => $on,
					'message' => $message,
				)
			);
		}

		$this->notice( 'success', $message );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Queue an error and go back.
	 *
	 * @param string $url     Where to go.
	 * @param string $message Plain text.
	 */
	private function fail( $url, $message ) {
		$this->notice( 'error', $message );
		$this->redirect( $url );
	}

	/**
	 * Queue a notice for the next screen this user sees. Public for feature
	 * modules saving through handle_save().
	 *
	 * @param string   $type    success | error | warning | info.
	 * @param string   $message Plain text.
	 * @param string[] $items   Optional list shown under it.
	 */
	public static function notice( $type, $message, array $items = array() ) {
		$key       = 'bzpl_notices_' . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
			'items'   => $items,
		);

		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Print and clear queued notices.
	 */
	private function print_notices() {
		$key     = 'bzpl_notices_' . get_current_user_id();
		$notices = get_transient( $key );

		if ( ! is_array( $notices ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $notices as $notice ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );

			if ( ! empty( $notice['items'] ) ) {
				echo '<ul class="bzpl-notice-list">';

				foreach ( $notice['items'] as $item ) {
					printf( '<li><code>%s</code></li>', esc_html( $item ) );
				}

				echo '</ul>';
			}

			echo '</div>';
		}
	}
}
