<?php
/**
 * Relocating builds compiled for another path.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * Points a build's internal asset references at where it now lives.
 *
 * Ported from BanzaiEmbed. A web game built with a root-absolute base —
 * Vite's default `base: '/'`, or a path left over from wherever it was hosted
 * before — has that base baked into its HTML, JS and CSS:
 *
 *     <script type="module" src="/assets/index-3f2a.js">
 *     url(/assets/Font.woff2)
 *
 * Under WordPress those 404, because the files are in uploads/banzaiplay.
 * This rewrites them in place, at upload time.
 *
 * Only exact `{base}{file}` references to files that are actually in the
 * build are touched. A bare base, or a path that is not one of the build's
 * own files, is left alone: rewriting a router basename or an API route
 * would break the game in ways much harder to diagnose than a missing image.
 *
 * One runtime use of the bare base is recognisable, and the one that matters
 * most: Vite's preload helper, which every lazy chunk goes through —
 *
 *     il=`modulepreload`,al=function(e){return`/`+e}
 *
 * relocate_preload() points that at the build too. What is left — URLs
 * assembled at runtime, such as `import.meta.env.BASE_URL + 'sfx.mp3'` —
 * Asset_Redirect catches when the browser requests them.
 */
final class Path_Rewriter {

	/**
	 * Files whose contents are rewritten.
	 */
	const TEXT_FILES = '/\.(m?js|cjs|css|html?)$/i';

	/**
	 * Rewrite every reference to `{base}{file}` in the build's HTML, JS and
	 * CSS, and the base in Vite's preload helper.
	 *
	 * @param string $dir    Folder the build was deployed from (where its page is).
	 * @param string $base   Base the build was compiled for: '/' or '/x/y/'.
	 * @param string $target Base the files now live at, with trailing slash.
	 * @return array{references: int, files: int, preload: bool}
	 */
	public function rewrite( $dir, $base, $target ) {
		$files      = Filesystem::list_files( $dir );
		$known      = array_flip( $files );
		$references = 0;
		$changed    = 0;
		$preload    = false;

		// Opened by a quote, backtick or `url(`; the path runs to the next
		// character that cannot be part of one (a quote, paren, ?, # or space).
		$pattern = '#(?<=["\'`(])' . preg_quote( $base, '#' ) . '([A-Za-z0-9_@~%+.\-/]+)#';

		foreach ( $files as $file ) {
			if ( ! preg_match( self::TEXT_FILES, $file ) ) {
				continue;
			}

			$count  = 0;
			$source = Filesystem::read( $dir . '/' . $file, 67108864 );
			$result = preg_replace_callback(
				$pattern,
				static function ( $m ) use ( $known, $target, &$count ) {
					if ( ! isset( $known[ $m[1] ] ) && ! isset( $known[ rawurldecode( $m[1] ) ] ) ) {
						return $m[0];
					}

					++$count;

					return $target . $m[1];
				},
				$source
			);

			$helpers = 0;

			if ( null !== $result && preg_match( '/\.m?js$/i', $file ) ) {
				$result = $this->relocate_preload( $result, $base, $target, $helpers );
			}

			if ( ( $count || $helpers ) && null !== $result && Filesystem::write( $dir . '/' . $file, $result ) ) {
				$references += $count;
				$preload     = $preload || $helpers > 0;
				++$changed;
			}
		}

		return array(
			'references' => $references,
			'files'      => $changed,
			'preload'    => $preload,
		);
	}

	/**
	 * Point Vite's preload helper at the build. Vite (Rollup or Rolldown,
	 * minified or not) emits it right after the "modulepreload" string:
	 *
	 *     const scriptRel = 'modulepreload';const assetsURL = function(dep) { return "/"+dep };
	 *     il=`modulepreload`,al=function(e){return`/`+e}
	 *
	 * Anchoring on that keeps every other `"/"+x` in the bundle untouched.
	 *
	 * @param string $source JS.
	 * @param string $base   Base the build was compiled for.
	 * @param string $target Base the files now live at.
	 * @param int    $count  Set to the number of helpers rewritten.
	 * @return string|null
	 */
	private function relocate_preload( $source, $base, $target, &$count ) {
		$q       = '["\'`]';
		$pattern = '#(' . $q . 'modulepreload' . $q . '\s*[,;]\s*(?:(?:const|let|var)\s+)?[\w$]+\s*=\s*'
			. '(?|function\s*\(\s*([\w$]+)\s*\)\s*\{\s*return\s*|\(?\s*([\w$]+)\s*\)?\s*=>\s*))'
			. '(' . $q . ')' . preg_quote( $base, '#' ) . '\3(\s*\+\s*\2(?![\w$]))#';

		return preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $target ) {
				return $m[1] . $m[3] . $target . $m[3] . $m[4];
			},
			$source,
			-1,
			$count
		);
	}

	/**
	 * Whether a relocated build may still have broken lazy loading: it has
	 * more scripts than its page loads (lazy chunks, most likely) and its
	 * preload helper could not be relocated.
	 *
	 * @param string $dir     Folder the build was deployed from.
	 * @param string $page    Its page, relative to $dir.
	 * @param bool   $preload Whether the preload helper was relocated.
	 * @return bool
	 */
	public static function needs_rebuild( $dir, $page, $preload ) {
		if ( $preload ) {
			return false;
		}

		$scripts = count( preg_grep( '/\.m?js$/i', Filesystem::list_files( $dir ) ) );
		$loaded  = preg_match_all( '#<script\b[^>]*\ssrc\s*=#i', Filesystem::read( $dir . '/' . $page ) );

		return $scripts > $loaded;
	}

	/**
	 * Explanation shown when needs_rebuild() is true.
	 *
	 * @param string $base The base the build was compiled for.
	 * @return string
	 */
	public static function rebuild_warning( $base ) {
		return sprintf(
			/* translators: %s: base path such as "/" or "/my-game/". */
			__( 'This build was compiled for the path %s. Its images, sounds and other files have been pointed at their new location, but it also has scripts that are loaded at runtime, which may fail to load. If parts of the game are missing, rebuild with a relative base (base: \'./\' in vite.config, publicPath: \'auto\' in webpack) and upload again.', 'banzaiplay' ),
			$base
		);
	}
}
