<?php
/**
 * The document a game plays in.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the document inside each game's iframe, and the compressed Unity
 * files that need headers a static file can't send.
 *
 * Every game plays in a same-origin iframe. That one decision is what solves
 * most of what makes games hard to embed:
 *
 * - Keyboard: key events only reach the focused document. The game gets
 *   them while it has focus and the page gets them otherwise — no matter
 *   that Unity captures every key on its window.
 * - Isolation: the game's CSS, globals and engine runtime stay out of the
 *   page, and the theme's stay out of the game. Removing the frame frees all
 *   of it, WebGL context included.
 * - Sizing: the game sees a window exactly the size of the player.
 *
 * The document depends on the engine. Unity and Godot get one BanzaiPlay
 * writes — a canvas, the engine's loader, and engines/{engine}.js, which
 * starts it with a progress callback (that is the real progress bar). Every
 * other engine boots from its own page, so that page is served with a
 * <base href> pointing at the build (relative URLs keep working) and
 * BanzaiPlay's bridge injected first in <head>. The bridge (assets/js/frame.js)
 * reports progress, readiness, errors and focus to the page, hands the
 * keyboard back on the release key, and resumes suspended audio.
 *
 * Served at the site root with a query — /?banzaiplay_frame=slug&v=build —
 * on do_parse_request, before WordPress resolves anything. The version in
 * the URL changes with every upload and plugin update, so caches never serve
 * a stale document; it is also put on every file BanzaiPlay requests for the
 * game.
 */
final class Frame {

	/**
	 * The query arg naming the game whose document is wanted.
	 */
	const FRAME_ARG = 'banzaiplay_frame';

	/**
	 * The query arg naming the game whose compressed file is wanted.
	 */
	const FILE_ARG = 'banzaiplay_file';

	/**
	 * Engines with a hook script that improves on the bridge's estimate.
	 */
	const HOOKS = array( 'phaser', 'playcanvas' );

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
		add_filter( 'do_parse_request', array( $this, 'maybe_serve' ), 5 );
	}

	/**
	 * The cache-busting version of a game's document and files: the build,
	 * plus the plugin version for the document (its scripts change with it).
	 *
	 * @param array $game Record.
	 * @return string
	 */
	public static function version( array $game ) {
		return $game['build'] . '.' . BZPL_VERSION;
	}

	/**
	 * URL of a game's frame document.
	 *
	 * @param array $game Record.
	 * @return string
	 */
	public function url( array $game ) {
		return add_query_arg(
			array(
				self::FRAME_ARG => rawurlencode( $game['slug'] ),
				'v'             => rawurlencode( self::version( $game ) ),
			),
			home_url( '/' )
		);
	}

	/**
	 * URL Unity should fetch one of its files from: the file itself with the
	 * build as a version, or — for a precompressed file — this endpoint,
	 * which adds the Content-Encoding header.
	 *
	 * @param array  $game Record.
	 * @param string $path Path relative to the game directory.
	 * @return string
	 */
	private function unity_url( array $game, $path ) {
		if ( preg_match( '/\.(gz|br)$/i', $path ) ) {
			return add_query_arg(
				array(
					self::FILE_ARG => rawurlencode( $game['slug'] ),
					'path'         => rawurlencode( $path ),
					'v'            => rawurlencode( $game['build'] ),
				),
				home_url( '/' )
			);
		}

		return $this->versioned( $game, $path );
	}

	/**
	 * A build file's URL with the build as a version.
	 *
	 * @param array  $game Record.
	 * @param string $path Path relative to the game directory.
	 * @return string
	 */
	private function versioned( array $game, $path ) {
		return $this->games->file_url( $game['slug'], $path ) . '?v=' . rawurlencode( $game['build'] );
	}

	/**
	 * Serve a frame document or a compressed file, if this request is for one.
	 *
	 * @param bool $parse Whether WordPress should parse the request.
	 * @return bool
	 */
	public function maybe_serve( $parse ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only documents.
		if ( isset( $_GET[ self::FRAME_ARG ] ) && is_string( $_GET[ self::FRAME_ARG ] ) ) {
			$this->serve_frame( Game_Manager::sanitize_slug( wp_unslash( $_GET[ self::FRAME_ARG ] ) ) );
			exit;
		}

		if ( isset( $_GET[ self::FILE_ARG ], $_GET['path'] ) && is_string( $_GET[ self::FILE_ARG ] ) && is_string( $_GET['path'] ) ) {
			// The path is only ever compared with the paths stored on the game.
			$this->serve_file( Game_Manager::sanitize_slug( wp_unslash( $_GET[ self::FILE_ARG ] ) ), (string) wp_unslash( $_GET['path'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			exit;
		}
		// phpcs:enable

		return $parse;
	}

	/**
	 * A game that may be played on this request: playable, and active —
	 * or inactive, for someone who manages games and is previewing it.
	 *
	 * @param string $slug Game slug.
	 * @return array|null
	 */
	private function playable( $slug ) {
		$game = '' === $slug ? null : $this->games->get( $slug );

		if ( ! $game || 'ready' !== Game_Manager::status( $game ) ) {
			return null;
		}

		return Game_Manager::is_active( $game ) || Admin::can_manage() ? $game : null;
	}

	/**
	 * Print a game's frame document.
	 *
	 * @param string $slug Game slug.
	 */
	private function serve_frame( $slug ) {
		$game = $this->playable( $slug );

		if ( ! $game ) {
			$this->headers( 404, false );
			$this->print_problem( __( 'This game is not available.', 'banzaiplay' ) );
			return;
		}

		$mode = Game_Manager::mode( $game );

		if ( 'unity' === $mode ) {
			$this->headers( 200, Game_Manager::is_active( $game ) );
			$this->print_unity( $game );
		} elseif ( 'godot' === $mode ) {
			$this->headers( 200, Game_Manager::is_active( $game ) );
			$this->print_godot( $game );
		} else {
			$this->print_html( $game );
		}
	}

	/**
	 * Headers for a frame document.
	 *
	 * @param int    $status  HTTP status.
	 * @param bool   $public  Whether caches may keep it.
	 * @param string $charset Charset of the document.
	 */
	private function headers( $status, $public, $charset = 'utf-8' ) {
		status_header( $status );
		header( 'Content-Type: text/html; charset=' . $charset );
		header( 'X-Content-Type-Options: nosniff' );
		// Framed by this site only, even where a security plugin denies framing.
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( "Content-Security-Policy: frame-ancestors 'self'" );
		// The page the game is on is what search engines should index.
		header( 'X-Robots-Tag: noindex' );
		// Short even though the URL is versioned: switching a game off should
		// reach cached copies soon.
		header( 'Cache-Control: ' . ( $public ? 'public, max-age=300' : 'private, no-store' ) );
	}

	/**
	 * What the bridge is told, as window.BanzaiPlayFrame.
	 *
	 * @param array $game  Record.
	 * @param array $extra Engine-specific settings.
	 * @return array
	 */
	private function bridge_config( array $game, array $extra = array() ) {
		return array_merge(
			array(
				'game'    => $game['slug'],
				'engine'  => $game['engine'],
				'mode'    => Game_Manager::mode( $game ),
				'release' => Game_Manager::release_key( $game ),
				'i18n'    => array(
					'failed'      => __( 'The game could not be loaded.', 'banzaiplay' ),
					/* translators: %s: list of missing browser features. */
					'unsupported' => __( 'This browser cannot run this game. Missing: %s', 'banzaiplay' ),
					'brotliHttps' => __( 'This game is Brotli-compressed, which browsers only accept over HTTPS. Load this page over https://, or rebuild the game without Brotli compression.', 'banzaiplay' ),
					'threads'     => __( 'This game was exported with threads, which need a cross-origin isolated page. Re-export it with thread support turned off.', 'banzaiplay' ),
				),
			),
			$extra
		);
	}

	/**
	 * The bridge's config and script, and an engine's hook script, as tags.
	 *
	 * @param array    $config Bridge config.
	 * @param string[] $hooks  Engine scripts to load in <head> after the bridge.
	 * @return string
	 */
	private function bridge_tags( array $config, array $hooks = array() ) {
		$tags = wp_get_inline_script_tag( 'window.BanzaiPlayFrame=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . ';' );
		$tags .= $this->script_tag( 'frame.js' );

		foreach ( $hooks as $hook ) {
			$tags .= $this->script_tag( 'engines/' . $hook . '.js' );
		}

		return $tags;
	}

	/**
	 * A plugin script as a tag.
	 *
	 * @param string $file Path under assets/js/.
	 * @return string
	 */
	private function script_tag( $file ) {
		return wp_get_script_tag( array( 'src' => BZPL_PLUGIN_URL . 'assets/js/' . $file . '?ver=' . rawurlencode( BZPL_VERSION ) ) );
	}

	/**
	 * Print a document BanzaiPlay writes: a full-window stage and a script.
	 *
	 * @param array  $game   Record.
	 * @param string $head   Extra markup for <head>, already escaped.
	 * @param string $body   Markup for <body>, already escaped.
	 * @param string $script Engine script under assets/js/engines/, or ''.
	 */
	private function print_document( array $game, $head, $body, $script ) {
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $game['name'] ); ?></title>
<style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#000;color:#fff}canvas,#banzaiplay-unity{display:block;width:100%;height:100%;outline:none;touch-action:none}</style>
		<?php
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		?>
</head>
<body>
		<?php
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.

		if ( '' !== $script ) {
			echo $this->script_tag( 'engines/' . $script . '.js' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by wp_get_script_tag().
		}
		?>
</body>
</html>
		<?php
	}

	/**
	 * A Unity game's document. Unity is started by engines/unity.js with
	 * createUnityInstance() (or UnityLoader.instantiate() for 2019 and
	 * earlier), so its progress callback drives the loading bar.
	 *
	 * @param array $game Record.
	 */
	private function print_unity( array $game ) {
		$data = $game['engine_data'];

		if ( ! empty( $data['legacy'] ) ) {
			$unity = array(
				'legacy' => true,
				'loader' => $this->versioned( $game, $data['loader'] ),
				'json'   => $this->versioned( $game, $data['json'] ),
			);
			$body  = '<div id="banzaiplay-unity"></div>';
		} else {
			$files  = $data['files'];
			$config = array(
				'dataUrl'            => $this->unity_url( $game, $files['data'] ),
				'frameworkUrl'       => $this->unity_url( $game, $files['framework'] ),
				'codeUrl'            => $this->unity_url( $game, $files['code'] ),
				'streamingAssetsUrl' => $this->games->file_url( $game['slug'], $data['streaming'] ),
				'companyName'        => $data['company'],
				'productName'        => $data['product'],
				'productVersion'     => $data['version'],
			);

			if ( ! empty( $files['symbols'] ) ) {
				$config['symbolsUrl'] = $this->unity_url( $game, $files['symbols'] );
			}

			$unity = array(
				'legacy' => false,
				'loader' => $this->versioned( $game, $data['loader'] ),
				'config' => $config,
				'brotli' => (bool) preg_grep( '/\.br$/i', $files ),
			);
			$body  = '<canvas id="banzaiplay-canvas" tabindex="-1"></canvas>';
		}

		$this->print_document( $game, $this->bridge_tags( $this->bridge_config( $game, array( 'unity' => $unity ) ) ), $body, 'unity' );
	}

	/**
	 * A Godot game's document. The engine script and pack are loaded by
	 * engines/godot.js, which reports the preloader's progress. The pack is
	 * fetched with the build as a version (Godot names it the same in every
	 * export) and stored in the virtual filesystem under its plain name.
	 *
	 * @param array $game Record.
	 */
	private function print_godot( array $game ) {
		$data = $game['engine_data'];
		$exe  = $data['executable'];

		$godot = array(
			'script'     => $this->versioned( $game, $data['dir'] . $exe . '.js' ),
			'executable' => $exe,
			'pack'       => $data['pack'],
			'packUrl'    => $data['pack'] . '?v=' . rawurlencode( $game['build'] ),
			'sizes'      => $data['sizes'],
			'config'     => (object) $data['config'],
			'threads'    => (bool) $data['threads'],
		);

		// The engine fetches its .wasm, worklets and side modules relative to
		// the document; the base makes that the export folder.
		$head = sprintf( '<base href="%s">', esc_url( $this->games->file_url( $game['slug'], $data['dir'] ) ) ) . "\n";
		$head .= $this->bridge_tags( $this->bridge_config( $game, array( 'godot' => $godot ) ) );

		$this->print_document( $game, $head, '<canvas id="canvas" tabindex="-1"></canvas>', 'godot' );
	}

	/**
	 * A game played from its own page, with the bridge injected first in
	 * <head> and a <base> pointing at the page's folder in the build.
	 *
	 * The page is printed as uploaded otherwise: it is a static file an
	 * administrator with unfiltered_html put there.
	 *
	 * @param array $game Record.
	 */
	private function print_html( array $game ) {
		$page = (string) $game['engine_data']['html'];
		$html = Filesystem::read( $this->games->game_dir( $game['slug'] ) . '/' . $page, 33554432 );

		if ( '' === $html ) {
			$this->headers( 404, false );
			$this->print_problem( __( 'The game\'s page is missing from its build. Upload the build again.', 'banzaiplay' ) );
			return;
		}

		$slash  = strrpos( $page, '/' );
		$folder = false === $slash ? '' : substr( $page, 0, $slash + 1 );
		$base   = $this->games->file_url( $game['slug'], $folder );
		$hooks  = in_array( $game['engine'], self::HOOKS, true ) ? array( $game['engine'] ) : array();
		$inject = "\n" . $this->bridge_tags( $this->bridge_config( $game ), $hooks );

		if ( preg_match( '#<base\b[^>]*>#i', $html ) ) {
			// The page's own <base> wins, but a relative one meant "relative to
			// the page", which is now the build folder.
			$html = preg_replace_callback(
				'#(<base\b[^>]*\shref\s*=\s*)(["\'])(.*?)\2#i',
				static function ( $m ) use ( $base ) {
					return $m[1] . $m[2] . esc_url( \WP_Http::make_absolute_url( $m[3], $base ) ) . $m[2];
				},
				$html,
				1
			);
		} else {
			$inject = "\n" . sprintf( '<base href="%s">', esc_url( $base ) ) . $inject;
		}

		$html = self::insert_head( (string) $html, $inject );

		// The page's own charset, so a non-UTF-8 page isn't garbled: the
		// injected tags push its <meta charset> past the first 1024 bytes,
		// where the browser stops looking.
		$charset = preg_match( '#<meta\b[^>]*charset\s*=\s*["\']?([\w-]+)#i', $html, $m ) ? $m[1] : 'utf-8';

		$this->headers( 200, Game_Manager::is_active( $game ), $charset );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the game's own page, uploaded by an administrator with unfiltered_html.
	}

	/**
	 * Insert markup at the start of a page's <head>, so it runs before any
	 * of the page's own scripts — or as near there as the page allows.
	 *
	 * @param string $html   Page.
	 * @param string $markup Tags.
	 * @return string
	 */
	private static function insert_head( $html, $markup ) {
		foreach ( array( '#<head\b[^>]*>#i', '#<html\b[^>]*>#i', '#<!doctype\b[^>]*>#i' ) as $pattern ) {
			if ( preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE ) ) {
				$at = $m[0][1] + strlen( $m[0][0] );

				return substr( $html, 0, $at ) . $markup . substr( $html, $at );
			}
		}

		return $markup . $html;
	}

	/**
	 * A document that tells the page the game can't start, and says why to
	 * anyone who opens it directly.
	 *
	 * @param string $message Plain text.
	 */
	private function print_problem( $message ) {
		$config = array(
			'fatal' => $message,
			'i18n'  => array(),
		);
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<title><?php esc_html_e( 'BanzaiPlay', 'banzaiplay' ); ?></title>
		<?php echo $this->bridge_tags( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by wp_get_*_script_tag(). ?>
</head>
<body style="margin:0;padding:2em;font:16px/1.5 system-ui,sans-serif;background:#111;color:#eee"><?php echo esc_html( $message ); ?></body>
</html>
		<?php
	}

	/**
	 * Send one of a Unity game's precompressed files with the
	 * Content-Encoding header Unity needs. Static hosting would need server
	 * configuration (an .htaccess, which can 500 a directory on a restrictive
	 * host, or nginx rules, which a plugin can't write).
	 *
	 * Only the exact compressed files stored on the game are ever served.
	 *
	 * @param string $slug Game slug.
	 * @param string $path Requested path.
	 */
	private function serve_file( $slug, $path ) {
		$game  = $this->playable( $slug );
		$files = $game && 'unity' === Game_Manager::mode( $game ) && isset( $game['engine_data']['files'] ) ? (array) $game['engine_data']['files'] : array();

		if ( ! in_array( $path, $files, true ) || ! preg_match( '/^(.*)\.(gz|br)$/i', $path, $m ) ) {
			status_header( 404 );
			exit;
		}

		$file = $this->games->game_dir( $game['slug'] ) . '/' . $path;

		if ( ! is_file( $file ) ) {
			status_header( 404 );
			exit;
		}

		$encoding = 'br' === strtolower( $m[2] ) ? 'br' : 'gzip';
		$accepted = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) ) : '';

		if ( false === strpos( $accepted, $encoding ) ) {
			status_header( 406 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html__( 'This browser does not accept this file\'s compression. Browsers only accept Brotli over HTTPS.', 'banzaiplay' );
			exit;
		}

		$types = array(
			'js'   => 'application/javascript',
			'wasm' => 'application/wasm',
			'json' => 'application/json',
		);
		$inner = strtolower( pathinfo( $m[1], PATHINFO_EXTENSION ) );

		// Anything already buffered or compressing would corrupt the bytes.
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Content-Type: ' . ( isset( $types[ $inner ] ) ? $types[ $inner ] : 'application/octet-stream' ) );
		header( 'Content-Encoding: ' . $encoding );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Vary: Accept-Encoding' );
		header( 'X-Content-Type-Options: nosniff' );
		// Versioned by the build in the URL.
		header( 'Cache-Control: public, max-age=31536000, immutable' );

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'HEAD' !== $method ) {
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a large file.
		}
	}
}
