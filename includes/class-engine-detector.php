<?php
/**
 * Engine detection.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which engine made a build, and what Frame needs to start it.
 *
 * Detection looks for each engine's tell-tale files, most specific first:
 *
 * - Unity: a `*.loader.js` beside `.framework.js`, `.data` and `.wasm` files
 *   (2020+), or `UnityLoader.js` and a build JSON (5.6–2019).
 * - Godot: a `.pck` pack, or `godot.js`.
 * - Construct 3: `c3runtime.js` / `c3main.js`, a Construct `data.json`, or
 *   its `sw.js` + `offline.json`.
 * - PlayCanvas: `__start__.js` / `__loading__.js`, or a `config.json` with
 *   `application_properties`.
 * - Phaser, then PixiJS: the library's file name, else its banner or API in
 *   the build's scripts.
 * - Anything else is a generic HTML5 game.
 *
 * Unity and Godot are started by BanzaiPlay itself (mode `unity`/`godot`),
 * which is what gives them a real progress bar. Everything else is played
 * by serving the build's own page (mode `html`): those engines boot from it.
 * A Godot build too old for the config-object API (3.2 and earlier) is
 * played from its own page as well.
 */
final class Engine_Detector {

	/**
	 * Largest script read when looking for an engine's markers.
	 */
	const SCAN_FILE_MAX = 16777216;

	/**
	 * Most script bytes read per build when looking for markers.
	 */
	const SCAN_TOTAL_MAX = 67108864;

	/**
	 * Keys of Godot's GODOT_CONFIG passed on to the engine. The rest
	 * (executable, mainPack, fileSizes) BanzaiPlay sets itself.
	 */
	const GODOT_KEYS = array( 'args', 'canvasResizePolicy', 'experimentalVK', 'focusCanvas', 'gdextensionLibs', 'gdnativeLibs', 'persistentDrops', 'persistentPaths', 'emscriptenPoolSize', 'godotPoolSize' );

	/**
	 * Analyse a build.
	 *
	 * @param string $dir    Build directory.
	 * @param string $engine Engine to treat it as; '' to use the detected one.
	 * @param string $entry  Entry to use, if it is one of the candidates; '' for the default.
	 * @return array {
	 *     detected_engine, detected_by, engine, entry, entries[], data, width, height, base, warnings[]
	 * }
	 */
	public function analyze( $dir, $engine = '', $entry = '' ) {
		$files = Filesystem::list_files( $dir );
		sort( $files );

		$found = $this->detect( $dir, $files );
		$use   = in_array( $engine, Game_Manager::ENGINES, true ) ? $engine : $found['engine'];

		if ( 'unity' === $use ) {
			$result = $this->unity( $dir, $files, $entry );
		} elseif ( 'godot' === $use ) {
			$result = $this->godot( $dir, $files, $entry );
		} else {
			$result = $this->html( $dir, $files, $entry );
		}

		if ( 'playcanvas' === $use && ! $result['width'] && '' !== $result['entry'] ) {
			$result = array_merge( $result, $this->playcanvas_size( $dir . '/' . self::dir_of( $result['entry'] ) ) );
		}

		return array_merge(
			$result,
			array(
				'detected_engine' => $found['engine'],
				'detected_by'     => $found['marker'],
				'engine'          => $use,
			)
		);
	}

	/**
	 * The engine a build was made with, and the file that says so.
	 *
	 * @param string   $dir   Build directory.
	 * @param string[] $files Relative paths, sorted.
	 * @return array{engine: string, marker: string}
	 */
	private function detect( $dir, array $files ) {
		$by_name = array();

		foreach ( $files as $file ) {
			$by_name[ strtolower( basename( $file ) ) ][] = $file;
		}

		$hit = static function ( $engine, $marker ) {
			return array(
				'engine' => $engine,
				'marker' => $marker,
			);
		};

		foreach ( $files as $file ) {
			if ( preg_match( '#\.loader\.js$#i', $file ) && $this->unity_files( $files, $file ) ) {
				return $hit( 'unity', $file );
			}
		}

		if ( isset( $by_name['unityloader.js'] ) ) {
			return $hit( 'unity', $by_name['unityloader.js'][0] );
		}

		foreach ( $files as $file ) {
			if ( preg_match( '#\.pck$#i', $file ) ) {
				return $hit( 'godot', $file );
			}
		}

		foreach ( array( 'godot.js', 'godot.tools.js' ) as $name ) {
			if ( isset( $by_name[ $name ] ) ) {
				return $hit( 'godot', $by_name[ $name ][0] );
			}
		}

		foreach ( array( 'c3runtime.js', 'c3main.js' ) as $name ) {
			if ( isset( $by_name[ $name ] ) ) {
				return $hit( 'construct', $by_name[ $name ][0] );
			}
		}

		if ( isset( $by_name['data.json'] ) ) {
			foreach ( $by_name['data.json'] as $file ) {
				if ( false !== strpos( Filesystem::read( $dir . '/' . $file ), '"project":[' ) ) {
					return $hit( 'construct', $file );
				}
			}
		}

		if ( isset( $by_name['sw.js'], $by_name['offline.json'] ) ) {
			return $hit( 'construct', $by_name['offline.json'][0] );
		}

		foreach ( array( '__start__.js', '__loading__.js' ) as $name ) {
			if ( isset( $by_name[ $name ] ) ) {
				return $hit( 'playcanvas', $by_name[ $name ][0] );
			}
		}

		if ( isset( $by_name['config.json'] ) ) {
			foreach ( $by_name['config.json'] as $file ) {
				if ( false !== strpos( Filesystem::read( $dir . '/' . $file ), '"application_properties"' ) ) {
					return $hit( 'playcanvas', $file );
				}
			}
		}

		$pixi = null;

		foreach ( $files as $file ) {
			$name = basename( $file );

			if ( preg_match( '/^phaser([.\-][\w.\-]*)?\.m?js$/i', $name ) ) {
				return $hit( 'phaser', $file );
			}

			if ( null === $pixi && preg_match( '/^pixi([.\-][\w.\-]*)?\.m?js$/i', $name ) ) {
				$pixi = $file;
			}
		}

		if ( null !== $pixi ) {
			return $hit( 'pixi', $pixi );
		}

		$scanned = $this->scan_scripts( $dir, $files );

		if ( $scanned ) {
			return $scanned;
		}

		return $hit( 'generic', '' );
	}

	/**
	 * Look inside the build's scripts for a bundled Phaser or PixiJS — a
	 * webpack or Vite build has no phaser.min.js to find by name.
	 *
	 * The largest scripts are read first: the library is usually most of a
	 * bundle. Phaser wins over Pixi, since Phaser 2 bundled Pixi.
	 *
	 * @param string   $dir   Build directory.
	 * @param string[] $files Relative paths.
	 * @return array{engine: string, marker: string}|null
	 */
	private function scan_scripts( $dir, array $files ) {
		$scripts = array();

		foreach ( $files as $file ) {
			if ( preg_match( '/\.m?js$/i', $file ) ) {
				$scripts[ $file ] = (int) filesize( $dir . '/' . $file );
			}
		}

		arsort( $scripts );

		$budget = self::SCAN_TOTAL_MAX;
		$pixi   = null;

		foreach ( $scripts as $file => $size ) {
			if ( $size > self::SCAN_FILE_MAX || $size > $budget ) {
				continue;
			}

			$budget -= $size;
			$source  = Filesystem::read( $dir . '/' . $file, self::SCAN_FILE_MAX );

			if ( preg_match( '/Phaser\.Game\b|phaser\.io|Phaser v\d/', $source ) ) {
				return array(
					'engine' => 'phaser',
					'marker' => $file,
				);
			}

			if ( null === $pixi && preg_match( '/PIXI\.Application\b|pixi\.js - v\d|PixiJS/', $source ) ) {
				$pixi = $file;
			}
		}

		return null === $pixi ? null : array(
			'engine' => 'pixi',
			'marker' => $pixi,
		);
	}

	/**
	 * An empty result.
	 *
	 * @return array
	 */
	private static function result() {
		return array(
			'entry'    => '',
			'entries'  => array(),
			'data'     => array(),
			'width'    => 0,
			'height'   => 0,
			'base'     => '',
			'warnings' => array(),
		);
	}

	/**
	 * A Unity build: its loader, and the files the loader needs.
	 *
	 * @param string   $dir   Build directory.
	 * @param string[] $files Relative paths, sorted.
	 * @param string   $entry Preferred loader (or legacy build JSON).
	 * @return array
	 */
	private function unity( $dir, array $files, $entry ) {
		$r       = self::result();
		$loaders = array();
		$jsons   = array();
		$legacy  = '';

		foreach ( $files as $file ) {
			if ( preg_match( '#\.loader\.js$#i', $file ) && $this->unity_files( $files, $file ) ) {
				$loaders[] = $file;
			} elseif ( 'unityloader.js' === strtolower( basename( $file ) ) && '' === $legacy ) {
				$legacy = $file;
			}
		}

		if ( '' !== $legacy ) {
			foreach ( preg_grep( '#^' . preg_quote( self::dir_of( $legacy ), '#' ) . '[^/]+\.json$#i', $files ) as $json ) {
				$source = Filesystem::read( $dir . '/' . $json, 1048576 );

				if ( false !== strpos( $source, '"dataUrl"' ) && preg_match( '/"(wasm|asm)CodeUrl"/', $source ) ) {
					$jsons[] = $json;
				}
			}
		}

		$r['entries'] = array_merge( $loaders, $jsons );
		$page         = $this->find_page( $files, '' );
		$html         = '' !== $page ? Filesystem::read( $dir . '/' . $page ) : '';

		if ( in_array( $entry, $r['entries'], true ) ) {
			$r['entry'] = $entry;
		} else {
			// The one the build's own page loads, if it says.
			foreach ( $r['entries'] as $candidate ) {
				if ( '' !== $html && false !== strpos( $html, basename( $candidate ) ) ) {
					$r['entry'] = $candidate;
					break;
				}
			}

			if ( '' === $r['entry'] && $r['entries'] ) {
				$r['entry'] = $r['entries'][0];
			}
		}

		if ( '' === $r['entry'] ) {
			$r['warnings'][] = __( 'No Unity WebGL loader was found. A Unity build has a Build/ folder with a .loader.js file (Unity 2020 and later) or UnityLoader.js (Unity 2019 and earlier). Zip the whole folder Unity exported.', 'banzaiplay' );

			return $r;
		}

		$r = array_merge( $r, $this->canvas_size( $html ) );

		if ( preg_match( '/\.json$/i', $r['entry'] ) ) {
			$r['data']       = array(
				'mode'   => 'unity',
				'legacy' => true,
				'loader' => $legacy,
				'json'   => $r['entry'],
			);
			$r['warnings'][] = __( 'This build was made with Unity 2019 or earlier (UnityLoader.js). It plays, but rebuilding with a current Unity version loads faster and works on more browsers.', 'banzaiplay' );

			return $r;
		}

		$parts = $this->unity_files( $files, $r['entry'] );

		if ( ! $parts ) {
			$r['entry']      = '';
			$r['warnings'][] = __( 'The Unity loader was found, but not its .framework.js, .data and .wasm files beside it. Zip the whole folder Unity exported.', 'banzaiplay' );

			return $r;
		}

		$r['data'] = array(
			'mode'      => 'unity',
			'legacy'    => false,
			'loader'    => $r['entry'],
			'files'     => $parts,
			// Beside the Build folder, at the root of the export.
			'streaming' => self::dir_of( rtrim( self::dir_of( $r['entry'] ), '/' ) ) . 'StreamingAssets',
			'company'   => self::js_string( $html, 'companyName', 'DefaultCompany' ),
			'product'   => self::js_string( $html, 'productName', basename( $r['entry'], '.loader.js' ) ),
			'version'   => self::js_string( $html, 'productVersion', '1.0' ),
		);

		$encodings = array();

		foreach ( $parts as $path ) {
			if ( preg_match( '/\.(gz|br)$/i', $path, $m ) ) {
				$encodings[ strtolower( $m[1] ) ] = true;
			}
		}

		if ( isset( $encodings['br'] ) ) {
			$r['warnings'][] = __( 'This build is Brotli-compressed. BanzaiPlay serves it with the Content-Encoding header Unity needs, but browsers only accept Brotli over HTTPS, so it will not load on a plain http:// site. For the fastest loading, rebuild with Compression Format set to Disabled, or with Decompression Fallback on.', 'banzaiplay' );
		} elseif ( isset( $encodings['gz'] ) ) {
			$r['warnings'][] = __( 'This build is gzip-compressed. BanzaiPlay serves it with the Content-Encoding header Unity needs, through PHP. For the fastest loading, rebuild with Decompression Fallback on, so your web server can send the files directly.', 'banzaiplay' );
		}

		$build_dir = self::dir_of( $r['entry'] );

		if ( preg_grep( '#^' . preg_quote( $build_dir, '#' ) . '[^/]+\.worker\.js$#i', $files ) || false !== strpos( $html, 'workerUrl' ) ) {
			$r['warnings'][] = __( 'This build uses multithreading, which needs a cross-origin isolated page — something a WordPress page cannot be without breaking other embeds. Turn off Multithreading in the WebGL Player Settings and rebuild.', 'banzaiplay' );
		}

		return $r;
	}

	/**
	 * The .framework.js, .data and .wasm (and optional .symbols.json) that go
	 * with a Unity loader — compressed or not — or null if any required one
	 * is missing. Files named after the build are preferred; with "Name
	 * Files As Hashes" on, any match in the loader's folder is taken.
	 *
	 * @param string[] $files  Relative paths.
	 * @param string   $loader Path of the .loader.js.
	 * @return array|null { framework, data, code, symbols? }
	 */
	private function unity_files( array $files, $loader ) {
		$prefix = self::dir_of( $loader );
		$name   = substr( basename( $loader ), 0, -strlen( '.loader.js' ) );
		$kinds  = array(
			'framework' => 'framework\.js',
			'data'      => 'data',
			'code'      => 'wasm',
			'symbols'   => 'symbols\.json',
		);
		$out    = array();

		foreach ( $kinds as $kind => $suffix ) {
			$pattern = '#^' . preg_quote( $prefix, '#' ) . '([^/]+)\.' . $suffix . '(\.(?:gz|br|unityweb))?$#i';
			$matches = array_values( preg_grep( $pattern, $files ) );

			foreach ( $matches as $match ) {
				preg_match( $pattern, $match, $m );

				if ( $m[1] === $name ) {
					$out[ $kind ] = $match;
					break;
				}
			}

			if ( ! isset( $out[ $kind ] ) && $matches ) {
				$out[ $kind ] = $matches[0];
			}
		}

		return isset( $out['framework'], $out['data'], $out['code'] ) ? $out : null;
	}

	/**
	 * A Godot web export: its engine script, wasm and pack, and the config
	 * its own page would have started it with.
	 *
	 * @param string   $dir   Build directory.
	 * @param string[] $files Relative paths, sorted.
	 * @param string   $entry Preferred engine script.
	 * @return array
	 */
	private function godot( $dir, array $files, $entry ) {
		$r     = self::result();
		$known = array_flip( $files );

		foreach ( $files as $file ) {
			if ( preg_match( '#^(.*)\.pck$#i', $file, $m ) && isset( $known[ $m[1] . '.js' ], $known[ $m[1] . '.wasm' ] ) ) {
				$r['entries'][] = $m[1] . '.js';
			}
		}

		if ( ! $r['entries'] ) {
			$html            = $this->html( $dir, $files, '' );
			$html['warnings'][] = __( 'No Godot engine files were found — a .js, .wasm and .pck with the same name — so the game\'s own page is played instead. Export with Godot\'s Web preset and zip the whole export folder.', 'banzaiplay' );

			return $html;
		}

		if ( in_array( $entry, $r['entries'], true ) ) {
			$r['entry'] = $entry;
		} else {
			// Shallowest first, then index.js, which is what Godot names an
			// export saved as index.html.
			$sorted = $r['entries'];
			usort(
				$sorted,
				static function ( $a, $b ) {
					$depth = substr_count( $a, '/' ) - substr_count( $b, '/' );

					return $depth ? $depth : ( 'index.js' === basename( $b ) ) - ( 'index.js' === basename( $a ) );
				}
			);
			$r['entry'] = $sorted[0];
		}

		$prefix = self::dir_of( $r['entry'] );
		$exe    = basename( $r['entry'], '.js' );
		$page   = $this->find_page( $files, $prefix, 'GODOT_CONFIG', $dir );
		$source = '' !== $page ? Filesystem::read( $dir . '/' . $page ) : '';
		$config = '' !== $source ? self::js_object( $source, 'GODOT_CONFIG' ) : null;

		// Godot 3.2 and earlier start with engine.startGame(name, pack) and
		// have no config object; their own page knows how.
		if ( null === $config && '' !== $source && false !== strpos( $source, 'startGame' ) ) {
			$html               = $this->html( $dir, $files, $page );
			$html['warnings'][] = __( 'This Godot export is from Godot 3.2 or earlier, so it is played from its own page, with an approximate progress bar. Exporting with Godot 3.5 or 4 gives it BanzaiPlay\'s loading screen.', 'banzaiplay' );

			return $html;
		}

		$threads = null;

		if ( preg_match( '/GODOT_THREADS_ENABLED\s*=\s*(true|false)/', $source, $m ) ) {
			$threads = 'true' === $m[1];
		} else {
			// Before 4.3 there was no choice: 4.x exports always used threads,
			// and shipped a worker script to prove it.
			$threads = isset( $known[ $prefix . $exe . '.worker.js' ] );
		}

		$r['data'] = array(
			'mode'       => 'godot',
			'dir'        => $prefix,
			'executable' => $exe,
			'pack'       => $exe . '.pck',
			'sizes'      => array(
				'wasm' => (int) filesize( $dir . '/' . $prefix . $exe . '.wasm' ),
				'pck'  => (int) filesize( $dir . '/' . $prefix . $exe . '.pck' ),
			),
			'config'     => array_intersect_key( is_array( $config ) ? $config : array(), array_flip( self::GODOT_KEYS ) ),
			'threads'    => $threads,
		);

		if ( $threads ) {
			$r['warnings'][] = __( 'This Godot export uses threads, which need a cross-origin isolated page — something a WordPress page cannot be without breaking other embeds. Export with Godot 4.3 or later with Thread Support turned off in the Web export preset.', 'banzaiplay' );
		}

		return $r;
	}

	/**
	 * A game played from its own HTML page.
	 *
	 * @param string   $dir   Build directory.
	 * @param string[] $files Relative paths, sorted.
	 * @param string   $entry Preferred page.
	 * @return array
	 */
	private function html( $dir, array $files, $entry ) {
		$r     = self::result();
		$pages = array_values( preg_grep( '#\.html?$#i', $files ) );

		usort( $pages, array( __CLASS__, 'compare_pages' ) );

		$r['entries'] = array_slice( $pages, 0, 100 );
		$r['entry']   = in_array( $entry, $pages, true ) ? $entry : ( $pages ? $pages[0] : '' );

		if ( '' === $r['entry'] ) {
			$r['warnings'][] = __( 'No HTML page was found. Upload the folder your engine exported, with its index.html.', 'banzaiplay' );

			return $r;
		}

		$source    = Filesystem::read( $dir . '/' . $r['entry'] );
		$r['data'] = array(
			'mode' => 'html',
			'html' => $r['entry'],
		);
		$r['base'] = $this->compiled_base( $source, $files, self::dir_of( $r['entry'] ) );

		return array_merge( $r, $this->canvas_size( $source ) );
	}

	/**
	 * Order pages: shallowest first, index.html first among equals, then by
	 * name.
	 *
	 * @param string $a Path.
	 * @param string $b Path.
	 * @return int
	 */
	private static function compare_pages( $a, $b ) {
		$depth = substr_count( $a, '/' ) - substr_count( $b, '/' );

		if ( $depth ) {
			return $depth;
		}

		$index = ( 'index.html' === strtolower( basename( $b ) ) ) - ( 'index.html' === strtolower( basename( $a ) ) );

		return $index ? $index : strcmp( $a, $b );
	}

	/**
	 * The page in a folder that most likely belongs to the build: index.html,
	 * else one containing $marker, else the first page.
	 *
	 * @param string[] $files  Relative paths.
	 * @param string   $prefix Folder, '' or with a trailing slash.
	 * @param string   $marker Text the page should contain, or ''.
	 * @param string   $dir    Build directory, needed with $marker.
	 * @return string Relative path, or ''.
	 */
	private function find_page( array $files, $prefix, $marker = '', $dir = '' ) {
		$pages = array_values( preg_grep( '#^' . preg_quote( $prefix, '#' ) . '[^/]+\.html?$#i', $files ) );

		usort( $pages, array( __CLASS__, 'compare_pages' ) );

		if ( ! $pages ) {
			return '';
		}

		if ( 'index.html' === strtolower( basename( $pages[0] ) ) || '' === $marker ) {
			return $pages[0];
		}

		foreach ( $pages as $page ) {
			if ( false !== strpos( Filesystem::read( $dir . '/' . $page ), $marker ) ) {
				return $page;
			}
		}

		return $pages[0];
	}

	/**
	 * The root-absolute base a page's scripts and stylesheets were built
	 * for: `/assets/index.js` naming the build's assets/index.js means `/`;
	 * `/old/site/assets/index.js` means `/old/site/`. '' when the page uses
	 * relative paths.
	 *
	 * @param string   $html   Page source.
	 * @param string[] $files  Relative paths.
	 * @param string   $prefix The page's folder, '' or with a trailing slash.
	 * @return string
	 */
	private function compiled_base( $html, array $files, $prefix ) {
		$known = array_flip( $files );

		preg_match_all( '#<(?:script|link)\b[^>]*?\s(?:src|href)\s*=\s*(["\'])(/(?!/)[^"\']*)\1#i', $html, $m );

		foreach ( $m[2] as $url ) {
			$path     = rawurldecode( (string) strtok( $url, '?#' ) );
			$segments = explode( '/', ltrim( $path, '/' ) );
			$count    = count( $segments );

			for ( $i = 0; $i < $count; $i++ ) {
				if ( isset( $known[ $prefix . implode( '/', array_slice( $segments, $i ) ) ] ) ) {
					return '/' . ( $i ? implode( '/', array_slice( $segments, 0, $i ) ) . '/' : '' );
				}
			}
		}

		return '';
	}

	/**
	 * The size a page gives its game: the first <canvas width height>, or
	 * an old Unity template's #unityContainer.
	 *
	 * @param string $html Page source.
	 * @return array{width: int, height: int}
	 */
	private function canvas_size( $html ) {
		$width  = 0;
		$height = 0;

		if ( preg_match( '#<canvas\b[^>]*>#i', $html, $tag ) ) {
			if ( preg_match( '#\swidth\s*=\s*["\']?(\d+)#i', $tag[0], $w ) && preg_match( '#\sheight\s*=\s*["\']?(\d+)#i', $tag[0], $h ) ) {
				$width  = (int) $w[1];
				$height = (int) $h[1];
			}
		}

		if ( ! $width && preg_match( '#id\s*=\s*["\']unityContainer["\'][^>]*style\s*=\s*["\'][^"\']*width:\s*(\d+)px;\s*height:\s*(\d+)px#i', $html, $m ) ) {
			$width  = (int) $m[1];
			$height = (int) $m[2];
		}

		return self::valid_size( $width, $height );
	}

	/**
	 * PlayCanvas keeps its resolution in config.json.
	 *
	 * @param string $folder Absolute folder of the entry page, with a trailing slash.
	 * @return array{width: int, height: int}
	 */
	private function playcanvas_size( $folder ) {
		$config = json_decode( Filesystem::read( $folder . 'config.json' ), true );
		$props  = is_array( $config ) && isset( $config['application_properties'] ) && is_array( $config['application_properties'] ) ? $config['application_properties'] : array();

		return self::valid_size( isset( $props['width'] ) ? (int) $props['width'] : 0, isset( $props['height'] ) ? (int) $props['height'] : 0 );
	}

	/**
	 * A size, or zeros if it isn't a plausible game resolution.
	 *
	 * @param int $width  Pixels.
	 * @param int $height Pixels.
	 * @return array{width: int, height: int}
	 */
	private static function valid_size( $width, $height ) {
		$ok = $width >= 100 && $width <= 8192 && $height >= 100 && $height <= 8192;

		return array(
			'width'  => $ok ? $width : 0,
			'height' => $ok ? $height : 0,
		);
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
	 * A string property from a JS object literal in a page — Unity's
	 * `companyName: "Studio"`.
	 *
	 * @param string $source   Page source.
	 * @param string $key      Property name.
	 * @param string $fallback Value when absent.
	 * @return string
	 */
	private static function js_string( $source, $key, $fallback ) {
		if ( preg_match( '/\b' . preg_quote( $key, '/' ) . '\s*:\s*(["\'])((?:(?!\1)[^\\\\]|\\\\.){0,200})\1/', $source, $m ) ) {
			return stripcslashes( $m[2] );
		}

		return $fallback;
	}

	/**
	 * The JSON object assigned to a variable in a page — Godot's
	 * `const GODOT_CONFIG = {…};` — or null. Braces are matched, so nested
	 * objects (fileSizes) are kept whole.
	 *
	 * @param string $source Page source.
	 * @param string $name   Variable name.
	 * @return array|null
	 */
	private static function js_object( $source, $name ) {
		if ( ! preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*\{/', $source, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$start = $m[0][1] + strlen( $m[0][0] ) - 1;
		$len   = strlen( $source );
		$depth = 0;
		$quote = '';

		for ( $i = $start; $i < $len; $i++ ) {
			$c = $source[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c && 0 === --$depth ) {
				$data = json_decode( substr( $source, $start, $i - $start + 1 ), true );

				return is_array( $data ) ? $data : null;
			}
		}

		return null;
	}
}
