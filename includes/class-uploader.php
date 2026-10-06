<?php
/**
 * Build uploads.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an uploaded zip into a game directory.
 *
 * The zip is written into uploads/, which is web-reachable, so what it is
 * allowed to put there is the security boundary of the whole plugin. Entries
 * are read one at a time and only allowlisted static files are ever written —
 * a .php file in the archive never touches the disk, not even briefly in a
 * temp directory, so there is no window in which it could be requested.
 *
 * Every entry path is checked for traversal before anything is written, and
 * the archive is capped by file count and total size so a zip bomb fails
 * cleanly instead of filling the disk. Entries are streamed to disk, never
 * held in memory whole: a Unity .data file alone can be hundreds of MB.
 *
 * An upload is unpacked into uploads/banzaiplay/_staging/ first and only
 * swapped in for the game's files once it has been checked (commit()), so a
 * failed upload leaves the live game untouched.
 */
final class Uploader {

	/**
	 * Static file types a game build may contain: web assets, plus the data,
	 * pack and archive formats engines load at runtime.
	 */
	const ALLOWED_EXTENSIONS = array(
		// Web.
		'html', 'htm', 'js', 'mjs', 'cjs', 'css', 'map', 'json', 'webmanifest', 'txt', 'xml', 'csv', 'tsv', 'yml', 'yaml',
		// Images.
		'svg', 'png', 'apng', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'cur', 'bmp', 'tga', 'ktx', 'ktx2', 'basis', 'dds', 'hdr', 'exr',
		// Fonts.
		'woff', 'woff2', 'ttf', 'otf', 'eot', 'fnt',
		// Audio and video.
		'mp3', 'ogg', 'oga', 'opus', 'wav', 'm4a', 'aac', 'flac', 'weba', 'mid', 'midi', 'mod', 'xm', 's3m', 'it', 'mp4', 'webm', 'ogv',
		// 3D.
		'glb', 'gltf', 'bin', 'obj', 'mtl', 'fbx', 'dae', 'stl', 'ply',
		// Shaders and 2D tooling (Tiled, LDtk, Spine).
		'glsl', 'vert', 'frag', 'wgsl', 'atlas', 'skel', 'tmx', 'tsx', 'tmj', 'tsj', 'ldtk',
		// Engines: WebAssembly, Emscripten data, Godot packs, Unity builds and
		// asset bundles, precompressed files, archives some runtimes load.
		'wasm', 'data', 'mem', 'pck', 'unityweb', 'bundle', 'bytes', 'dat', 'assets', 'ress', 'resource', 'manifest', 'hash',
		'gz', 'br', 'zip', 'love', 'tic', 'p8',
	);

	/**
	 * Defold's archive parts, whose extensions end in a number.
	 */
	const ALLOWED_PATTERN = '/^(arcd|arci|dmanifest|projectc|der)\d+$/';

	const MAX_FILES = 20000;

	const MAX_BYTES = 1073741824; // 1 GB uncompressed.

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
	 * Unpack an uploaded zip into a new staging directory.
	 *
	 * Nothing about any game changes here; the caller checks the result and
	 * calls commit() or discard().
	 *
	 * @param array $file An entry from $_FILES.
	 * @return array|\WP_Error { dir, files, bytes, skipped[] }
	 */
	public function extract( array $file ) {
		$checked = $this->check_upload( $file );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$reader = $this->open( $file['tmp_name'] );

		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$plan = $this->plan( $reader['entries'] );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$this->sweep();

		$dir = $this->games->staging_dir() . '/' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'bzpl_mkdir', __( 'Could not create the game directory in uploads. Check that wp-content/uploads is writable.', 'banzaiplay' ) );
		}

		foreach ( $plan['write'] as $index => $entry ) {
			if ( ! call_user_func( $reader['copy'], $index, $dir . '/' . $entry['path'], $entry['size'] ) ) {
				Filesystem::delete( $dir );

				return new \WP_Error(
					'bzpl_write',
					/* translators: %s: file path inside the zip. */
					sprintf( __( 'Could not extract %s from the zip. The disk may be full, or the zip damaged.', 'banzaiplay' ), $entry['path'] )
				);
			}
		}

		return array(
			'dir'     => $dir,
			'files'   => count( $plan['write'] ),
			'bytes'   => $plan['bytes'],
			'skipped' => $plan['skipped'],
		);
	}

	/**
	 * Make an unpacked upload the game's files, replacing what was there.
	 *
	 * The old files are moved aside first and put back if the swap fails, so
	 * a game is never left half-replaced.
	 *
	 * @param string $slug    Game slug.
	 * @param string $staging Directory from extract().
	 * @return true|\WP_Error
	 */
	public function commit( $slug, $staging ) {
		$target = $this->games->game_dir( $slug );
		$old    = '';

		if ( file_exists( $target ) ) {
			$old = $this->games->staging_dir() . '/old-' . $slug . '-' . strtolower( wp_generate_password( 6, false, false ) );

			if ( ! Filesystem::move( $target, $old ) ) {
				return new \WP_Error( 'bzpl_swap', __( 'Could not replace the game\'s files: the current build could not be moved aside. Check the permissions of wp-content/uploads/banzaiplay.', 'banzaiplay' ) );
			}
		}

		if ( ! Filesystem::move( $staging, $target ) ) {
			if ( '' !== $old ) {
				Filesystem::move( $old, $target );
			}

			return new \WP_Error( 'bzpl_swap', __( 'Could not move the new build into place. The game keeps its previous build.', 'banzaiplay' ) );
		}

		if ( '' !== $old ) {
			Filesystem::delete( $old );
		}

		return true;
	}

	/**
	 * Throw away an unpacked upload.
	 *
	 * @param string $staging Directory from extract().
	 */
	public function discard( $staging ) {
		if ( 0 === strpos( wp_normalize_path( $staging ), wp_normalize_path( $this->games->staging_dir() ) . '/' ) ) {
			Filesystem::delete( $staging );
		}
	}

	/**
	 * Delete staging directories a crashed or abandoned upload left behind.
	 * Anything over a day old cannot still be in use.
	 */
	private function sweep() {
		$staging = $this->games->staging_dir();

		foreach ( Filesystem::list_dirs( $staging ) as $name ) {
			$path = $staging . '/' . $name;

			if ( filemtime( $path ) < time() - DAY_IN_SECONDS ) {
				Filesystem::delete( $path );
			}
		}
	}

	/**
	 * Validate the $_FILES entry itself.
	 *
	 * @param array $file An entry from $_FILES.
	 * @return true|\WP_Error
	 */
	private function check_upload( array $file ) {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return new \WP_Error(
				'bzpl_too_big',
				/* translators: %s: maximum upload size, e.g. "64 MB". */
				sprintf( __( 'The zip is larger than this server accepts (%s). Raise upload_max_filesize and post_max_size — see "Uploading large builds" below the upload box.', 'banzaiplay' ), size_format( wp_max_upload_size() ) )
			);
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			/* translators: %d: PHP upload error code. */
			return new \WP_Error( 'bzpl_upload', sprintf( __( 'The upload failed (PHP error %d).', 'banzaiplay' ), $error ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'bzpl_upload', __( 'The upload failed.', 'banzaiplay' ) );
		}

		$name = isset( $file['name'] ) ? (string) $file['name'] : '';

		if ( ! preg_match( '/\.zip$/i', $name ) ) {
			return new \WP_Error( 'bzpl_not_zip', __( 'Upload a .zip file containing your game build.', 'banzaiplay' ) );
		}

		return true;
	}

	/**
	 * Open a zip with whichever library the server has.
	 *
	 * Returns a uniform reader: a list of entries and a function that copies
	 * one entry, by index, to a path.
	 *
	 * @param string $path Zip path.
	 * @return array|\WP_Error { entries: array{index:int,name:string,size:int,dir:bool}[], copy: callable }
	 */
	private function open( $path ) {
		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();

			if ( true !== $zip->open( $path ) ) {
				return new \WP_Error( 'bzpl_bad_zip', __( 'That file is not a readable zip archive.', 'banzaiplay' ) );
			}

			$entries = array();

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );

				if ( false === $stat ) {
					continue;
				}

				$entries[] = array(
					'index' => $i,
					'name'  => $stat['name'],
					'size'  => (int) $stat['size'],
					'dir'   => '/' === substr( $stat['name'], -1 ),
				);
			}

			return array(
				'entries' => $entries,
				'copy'    => static function ( $index, $dest, $size ) use ( $zip ) {
					$in = method_exists( $zip, 'getStreamIndex' ) ? $zip->getStreamIndex( $index ) : $zip->getStream( (string) $zip->getNameIndex( $index ) );

					if ( ! $in ) {
						return false;
					}

					$ok = Filesystem::write_stream( $dest, $in, $size );
					fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

					return $ok;
				},
			);
		}

		// No ZipArchive: fall back to the PclZip copy WordPress bundles. It
		// can only extract to a string, so every file is held in memory once.
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$zip  = new \PclZip( $path );
		$list = $zip->listContent();

		if ( ! is_array( $list ) ) {
			return new \WP_Error( 'bzpl_bad_zip', __( 'That file is not a readable zip archive.', 'banzaiplay' ) );
		}

		$entries = array();

		foreach ( $list as $item ) {
			$entries[] = array(
				'index' => (int) $item['index'],
				'name'  => $item['filename'],
				'size'  => (int) $item['size'],
				'dir'   => ! empty( $item['folder'] ),
			);
		}

		return array(
			'entries' => $entries,
			'copy'    => static function ( $index, $dest, $size ) use ( $zip ) {
				$out = $zip->extract( PCLZIP_OPT_BY_INDEX, (string) $index, PCLZIP_OPT_EXTRACT_AS_STRING );

				if ( ! is_array( $out ) || ! isset( $out[0]['content'] ) || strlen( $out[0]['content'] ) > $size ) {
					return false;
				}

				return Filesystem::write( $dest, $out[0]['content'] );
			},
		);
	}

	/**
	 * Decide which entries are written, and where.
	 *
	 * @param array $entries From open().
	 * @return array|\WP_Error { write: array<int,array{path:string,size:int}>, skipped: string[], bytes: int }
	 */
	private function plan( array $entries ) {
		$allowed = (array) apply_filters( 'bzpl/allowed_extensions', self::ALLOWED_EXTENSIONS );
		$files   = array();
		$skipped = array();

		foreach ( $entries as $entry ) {
			if ( $entry['dir'] ) {
				continue;
			}

			$name = str_replace( '\\', '/', $entry['name'] );

			// Finder and Explorer litter, not part of the build.
			if ( 0 === strpos( $name, '__MACOSX/' ) || in_array( basename( $name ), array( '.DS_Store', 'Thumbs.db', 'desktop.ini' ), true ) ) {
				continue;
			}

			$path = $this->safe_path( $name );

			if ( null === $path ) {
				$skipped[] = $name;
				continue;
			}

			$files[ $entry['index'] ] = array(
				'path' => $path,
				'size' => $entry['size'],
			);
		}

		if ( ! $files ) {
			return new \WP_Error( 'bzpl_empty', __( 'The zip is empty.', 'banzaiplay' ) );
		}

		if ( count( $files ) > (int) apply_filters( 'bzpl/max_files', self::MAX_FILES ) ) {
			return new \WP_Error( 'bzpl_too_many', __( 'The zip contains too many files. Upload only your exported build, not your project folder.', 'banzaiplay' ) );
		}

		$files = $this->strip_wrapper( $files );
		$write = array();
		$bytes = 0;

		foreach ( $files as $index => $file ) {
			if ( ! $this->is_allowed( $file['path'], $allowed ) ) {
				$skipped[] = $file['path'];
				continue;
			}

			$bytes          += $file['size'];
			$write[ $index ] = $file;
		}

		if ( $bytes > (int) apply_filters( 'bzpl/max_bytes', self::MAX_BYTES ) ) {
			return new \WP_Error( 'bzpl_too_big', __( 'The build is too large once extracted. Upload only your exported build, not your project folder.', 'banzaiplay' ) );
		}

		if ( ! $write ) {
			return new \WP_Error( 'bzpl_nothing', __( 'The zip contains no files that can be served as part of a web game.', 'banzaiplay' ) );
		}

		return array(
			'write'   => $write,
			'skipped' => $skipped,
			'bytes'   => $bytes,
		);
	}

	/**
	 * Normalise an entry name, or return null if it could escape the game
	 * directory or is otherwise unsafe to write.
	 *
	 * @param string $name Forward-slashed entry name.
	 * @return string|null
	 */
	private function safe_path( $name ) {
		if ( false !== strpos( $name, "\0" ) || false !== strpos( $name, ':' ) ) {
			return null;
		}

		$segments = array();

		foreach ( explode( '/', ltrim( $name, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			// Traversal, and dotfiles (.htaccess, .user.ini, .env), which are
			// never build output.
			if ( '.' === $segment[0] ) {
				return null;
			}

			$segments[] = $segment;
		}

		return $segments ? implode( '/', $segments ) : null;
	}

	/**
	 * Most people zip the export folder rather than its contents. When every
	 * file sits under one top-level directory, drop that directory.
	 *
	 * @param array $files index => { path, size }.
	 * @return array
	 */
	private function strip_wrapper( array $files ) {
		$top = null;

		foreach ( $files as $file ) {
			$slash = strpos( $file['path'], '/' );

			if ( false === $slash ) {
				return $files;
			}

			$first = substr( $file['path'], 0, $slash );

			if ( null === $top ) {
				$top = $first;
			} elseif ( $top !== $first ) {
				return $files;
			}
		}

		$cut = strlen( $top ) + 1;

		foreach ( $files as $index => $file ) {
			$files[ $index ]['path'] = substr( $file['path'], $cut );
		}

		return $files;
	}

	/**
	 * Whether a file may be written into uploads/.
	 *
	 * The extension must be allowlisted, and no part of the name may look like
	 * a server-side script: `shell.php.png` is an image to the allowlist but
	 * runs as PHP on an Apache with a loose AddHandler.
	 *
	 * @param string   $path    Relative path.
	 * @param string[] $allowed Extensions.
	 * @return bool
	 */
	private function is_allowed( $path, array $allowed ) {
		$base = strtolower( basename( $path ) );

		if ( preg_match( '/\.(php\d*|phtml|phar|pht|phps|shtml|cgi|pl|py|asp|aspx|jsp|sh)(\.|$)/', $base ) ) {
			return false;
		}

		$ext = pathinfo( $base, PATHINFO_EXTENSION );

		return '' !== $ext && ( in_array( $ext, $allowed, true ) || preg_match( self::ALLOWED_PATTERN, $ext ) );
	}
}
