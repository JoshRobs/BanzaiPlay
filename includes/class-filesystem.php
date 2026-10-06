<?php
/**
 * File access.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's only route to the disk.
 *
 * Always the direct filesystem. Everything this plugin writes lives under
 * uploads/, which PHP can already write to on any site where media uploads
 * work; going through WP_Filesystem's FTP/SSH methods would put a credentials
 * prompt in the middle of a form post on hosts that are configured for them.
 */
final class Filesystem {

	/**
	 * @var \WP_Filesystem_Direct|null
	 */
	private static $fs = null;

	/**
	 * The direct filesystem instance.
	 *
	 * @return \WP_Filesystem_Direct
	 */
	public static function fs() {
		if ( null === self::$fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

			self::$fs = new \WP_Filesystem_Direct( null );

			// WP_Filesystem() defines these; constructing the class directly
			// skips it. Same derivation as core.
			if ( ! defined( 'FS_CHMOD_DIR' ) ) {
				define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) );
			}

			if ( ! defined( 'FS_CHMOD_FILE' ) ) {
				define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
			}
		}

		return self::$fs;
	}

	/**
	 * Write a file, creating parent directories.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents Bytes.
	 * @return bool
	 */
	public static function write( $path, $contents ) {
		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			return false;
		}

		return self::fs()->put_contents( $path, $contents, FS_CHMOD_FILE );
	}

	/**
	 * Copy a stream into a new file, refusing to write more than $max bytes.
	 *
	 * Game builds hold single files of hundreds of megabytes (a Unity .data
	 * file), which would not fit in PHP's memory as one string.
	 *
	 * @param string   $path Absolute path.
	 * @param resource $in   Readable stream.
	 * @param int      $max  Largest size the file may have.
	 * @return bool
	 */
	public static function write_stream( $path, $in, $max ) {
		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			return false;
		}

		self::fs();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming; WP_Filesystem has no stream API.
		$out = fopen( $path, 'wb' );

		if ( ! $out ) {
			return false;
		}

		$copied = stream_copy_to_stream( $in, $out, $max + 1 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// More than the archive declared means the declaration was a lie —
		// the shape of a zip bomb.
		if ( false === $copied || $copied > $max ) {
			wp_delete_file( $path );

			return false;
		}

		self::fs()->chmod( $path, FS_CHMOD_FILE );

		return true;
	}

	/**
	 * Read a file, or return '' if it cannot be read or is larger than $max.
	 *
	 * @param string $path Absolute path.
	 * @param int    $max  Largest size worth reading, in bytes.
	 * @return string
	 */
	public static function read( $path, $max = 16777216 ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > $max ) {
			return '';
		}

		$contents = self::fs()->get_contents( $path );

		return false === $contents ? '' : $contents;
	}

	/**
	 * Move a file or directory. Both must be on the same filesystem, which
	 * they always are here: everything lives under uploads/banzaiplay.
	 *
	 * @param string $from Absolute path.
	 * @param string $to   Absolute path that does not exist yet.
	 * @return bool
	 */
	public static function move( $from, $to ) {
		return self::fs()->move( $from, $to, false );
	}

	/**
	 * Delete a file or a directory tree. Missing paths are fine.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function delete( $path ) {
		if ( ! file_exists( $path ) ) {
			return true;
		}

		return self::fs()->delete( $path, true );
	}

	/**
	 * Every file under a directory, as forward-slashed paths relative to it.
	 *
	 * @param string $dir Absolute path.
	 * @return string[]
	 */
	public static function list_files( $dir ) {
		$out = array();

		if ( ! is_dir( $dir ) ) {
			return $out;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);
		$prefix   = strlen( wp_normalize_path( trailingslashit( $dir ) ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$out[] = substr( wp_normalize_path( $file->getPathname() ), $prefix );
			}
		}

		return $out;
	}

	/**
	 * Every directory directly inside $dir, by name.
	 *
	 * @param string $dir Absolute path.
	 * @return string[]
	 */
	public static function list_dirs( $dir ) {
		$out = array();

		if ( ! is_dir( $dir ) ) {
			return $out;
		}

		foreach ( new \DirectoryIterator( $dir ) as $entry ) {
			if ( $entry->isDir() && ! $entry->isDot() ) {
				$out[] = $entry->getFilename();
			}
		}

		return $out;
	}
}
