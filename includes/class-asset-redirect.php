<?php
/**
 * Requests for a game's files at the site root.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a request for one of a game's files at the wrong path — almost always
 * the site root — to where the file really is.
 *
 * Ported from BanzaiEmbed. Path_Rewriter fixes the references it can see at
 * upload. What it cannot see is a URL the game assembles at runtime:
 * `import.meta.env.BASE_URL + 'pig.png'` (Vite compiles BASE_URL to a bare
 * "/"), `` `/sounds/${name}` ``, a hard-coded `setDecoderPath('/draco/')`.
 * Those reach the server as /pig.png and /sounds/win.mp3, which WordPress
 * answers with a 404.
 *
 * So when a request is about to 404, and its path names a file in an active
 * game's build, this redirects to that file. It only ever acts on requests
 * that would have been a 404 — a page, post, feed, sitemap or robots.txt at
 * the same path is never touched — and only for files already public in
 * uploads/, so it exposes nothing new.
 */
final class Asset_Redirect {

	/**
	 * How long browsers may cache a redirect, in seconds. Short: a new build
	 * may not contain the same file.
	 */
	const MAX_AGE = 600;

	/**
	 * Files at the site root that belong to the site, not a game, even when
	 * a build happens to contain one and WordPress has none to serve.
	 */
	const SITE_FILES = array( 'robots.txt', 'favicon.ico', 'ads.txt', 'app-ads.txt', 'humans.txt', 'sitemap.xml', 'sitemap_index.xml', 'manifest.json', 'site.webmanifest', 'sw.js', 'service-worker.js' );

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
		// pre_handle_404 runs before redirect_canonical, which would otherwise
		// "guess" a post from the file name and redirect there.
		add_filter( 'pre_handle_404', array( $this, 'maybe_redirect' ), 5, 2 );
	}

	/**
	 * Redirect a would-be 404 that names one of a game's files.
	 *
	 * @param bool      $preempt  Whether another plugin already handled it.
	 * @param \WP_Query $wp_query Main query.
	 * @return bool
	 */
	public function maybe_redirect( $preempt, $wp_query ) {
		if ( $preempt || ! $wp_query->is_main_query() ) {
			return $preempt;
		}

		// Only a request that found nothing. A path no rewrite rule matches
		// is flagged 404 but may still have run the default query.
		if ( ( $wp_query->posts && ! $wp_query->is_404() ) || $wp_query->is_feed() || $wp_query->is_robots() || $wp_query->is_favicon() ) {
			return $preempt;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return $preempt;
		}

		$url = $this->find( $this->request_path() );

		if ( null === $url ) {
			return $preempt;
		}

		header( 'Cache-Control: public, max-age=' . self::MAX_AGE );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the target is one of this site's uploads, possibly on an offload host.
		wp_redirect( $url, 302, 'BanzaiPlay' );
		exit;
	}

	/**
	 * The request's path from the domain root, decoded, without a query.
	 *
	 * @return string
	 */
	private function request_path() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only ever compared against files on disk, by find().
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		return rawurldecode( $path );
	}

	/**
	 * URL of the build file a root-absolute path names, if any.
	 *
	 * @param string $path Request path from the domain root.
	 * @return string|null
	 */
	public function find( $path ) {
		if ( '' === $path || false !== strpos( $path, "\0" ) || false !== strpos( $path, '\\' ) ) {
			return null;
		}

		// A file, not a directory: only the last segment may name it, and
		// WordPress slugs never contain a dot, so this skips every page path.
		if ( false === strpos( basename( $path ), '.' ) ) {
			return null;
		}

		if ( in_array( strtolower( ltrim( $path, '/' ) ), self::SITE_FILES, true ) ) {
			return null;
		}

		foreach ( $this->candidates() as $game ) {
			$prefix = isset( $game['engine_data']['html'] ) ? self::dir_of( $game['engine_data']['html'] ) : '';

			foreach ( $this->relative_to( $path, $game['base'] ) as $relative ) {
				if ( is_file( $this->games->game_dir( $game['slug'] ) . '/' . $prefix . $relative ) ) {
					return $this->games->file_url( $game['slug'], $prefix . $relative );
				}
			}
		}

		return null;
	}

	/**
	 * Active, playable games served from their own page, in the order to try
	 * them: the game whose frame or file asked first, then the most recently
	 * uploaded.
	 *
	 * @return array[]
	 */
	private function candidates() {
		$games = array_filter(
			$this->games->all(),
			static function ( $game ) {
				return Game_Manager::is_active( $game ) && 'ready' === Game_Manager::status( $game ) && 'html' === Game_Manager::mode( $game );
			}
		);

		uasort(
			$games,
			static function ( $a, $b ) {
				return $b['uploaded'] - $a['uploaded'];
			}
		);

		$from = $this->referring_slug();

		if ( null !== $from && isset( $games[ $from ] ) ) {
			$games = array( $from => $games[ $from ] ) + $games;
		}

		/**
		 * Filter which games a root-absolute request for a missing file may be
		 * redirected into, in the order they are tried. Return an empty array
		 * to switch the redirect off.
		 *
		 * @param array[] $games Records keyed by slug.
		 */
		return (array) apply_filters( 'bzpl/asset_redirect_games', $games );
	}

	/**
	 * The game the Referer belongs to: a game's frame document (its query
	 * names the game) or a file in its folder (a stylesheet's url(), a
	 * chunk's import).
	 *
	 * @return string|null
	 */
	private function referring_slug() {
		$referer = wp_get_raw_referer();

		if ( ! $referer ) {
			return null;
		}

		wp_parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );

		if ( isset( $query[ Frame::FRAME_ARG ] ) && is_string( $query[ Frame::FRAME_ARG ] ) ) {
			return Game_Manager::sanitize_slug( $query[ Frame::FRAME_ARG ] );
		}

		$prefix = (string) wp_parse_url( $this->games->base_url(), PHP_URL_PATH ) . '/';
		$path   = (string) wp_parse_url( $referer, PHP_URL_PATH );

		if ( 0 !== strpos( $path, $prefix ) ) {
			return null;
		}

		$slug = strtok( substr( $path, strlen( $prefix ) ), '/' );

		return false === $slug ? null : rawurldecode( $slug );
	}

	/**
	 * The paths inside a build that $path could mean: with the base the
	 * build was compiled for removed, then from the domain root.
	 *
	 * @param string $path Request path from the domain root.
	 * @param string $base The game's compiled-for base, or ''.
	 * @return string[]
	 */
	private function relative_to( $path, $base ) {
		$tries = array();

		if ( '' !== $base && '/' !== $base && 0 === strpos( $path, $base ) ) {
			$tries[] = '/' . substr( $path, strlen( $base ) );
		}

		$tries[] = $path;

		return array_values( array_filter( array_map( array( $this, 'safe_relative' ), $tries ) ) );
	}

	/**
	 * A root path as a build-relative one, or null if any segment could step
	 * outside the build or is a dotfile.
	 *
	 * @param string $path Path from the domain root.
	 * @return string|null
	 */
	private function safe_relative( $path ) {
		$segments = explode( '/', ltrim( $path, '/' ) );

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment[0] ) {
				return null;
			}
		}

		return implode( '/', $segments );
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
}
