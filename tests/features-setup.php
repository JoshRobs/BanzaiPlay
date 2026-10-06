<?php
/**
 * Set up the test site for tests/features.mjs. Run in the cli container after
 * make-fixtures.php and e2e.sh:
 *
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/BanzaiPlay/tests/features-setup.php
 *
 * - adds two images to the media library, for the cover and the logo;
 * - adds a must-use plugin that logs bzpl/game_event to an option, so the
 *   test can see the PHP hook fire;
 * - creates the pages the test plays on.
 *
 * Prints the attachment IDs and page URLs as JSON. Safe to run again.
 *
 * @package BanzaiPlay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Run with wp eval-file.' );
}

/**
 * A PNG made with GD, imported into the media library once.
 *
 * @param string $name  File name.
 * @param int    $w     Width.
 * @param int    $h     Height.
 * @param int[]  $from  Gradient start colour.
 * @param int[]  $to    Gradient end colour.
 * @return int Attachment ID.
 */
function bzpl_test_image( $name, $w, $h, array $from, array $to ) {
	$found = get_posts(
		array(
			'post_type'   => 'attachment',
			'title'       => $name,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);

	if ( $found ) {
		return (int) $found[0];
	}

	$img = imagecreatetruecolor( $w, $h );

	for ( $y = 0; $y < $h; $y++ ) {
		$t = $y / max( 1, $h - 1 );
		imageline( $img, 0, $y, $w, $y, imagecolorallocate( $img, (int) ( $from[0] + ( $to[0] - $from[0] ) * $t ), (int) ( $from[1] + ( $to[1] - $from[1] ) * $t ), (int) ( $from[2] + ( $to[2] - $from[2] ) * $t ) ) );
	}

	imagefilledellipse( $img, (int) ( $w / 2 ), (int) ( $h / 2 ), (int) ( $h / 2 ), (int) ( $h / 2 ), imagecolorallocate( $img, 255, 255, 255 ) );

	$file = get_temp_dir() . $name . '.png';
	imagepng( $img, $file );
	imagedestroy( $img );

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$id = media_handle_sideload(
		array(
			'name'     => $name . '.png',
			'tmp_name' => $file,
		),
		0,
		$name
	);

	return is_wp_error( $id ) ? 0 : (int) $id;
}

$cover = bzpl_test_image( 'bzpl-test-cover', 960, 540, array( 120, 30, 160 ), array( 20, 30, 80 ) );
$logo  = bzpl_test_image( 'bzpl-test-logo', 240, 80, array( 255, 120, 0 ), array( 255, 200, 0 ) );

// The hook logger.
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	WPMU_PLUGIN_DIR . '/bzpl-test-events.php',
	"<?php\n// Written by BanzaiPlay's tests/features-setup.php: logs game events for tests/features.mjs.\nadd_action( 'bzpl/game_event', function ( \$name, \$data, \$context ) {\n\t\$log   = (array) get_option( 'bzpl_test_events', array() );\n\t\$log[] = array( 'name' => \$name, 'data' => \$data, 'game' => \$context['game']['slug'], 'user' => \$context['user_id'] );\n\tupdate_option( 'bzpl_test_events', array_slice( \$log, -50 ), false );\n}, 10, 3 );\n"
);

/**
 * A published page, created once.
 *
 * @param string $slug    Page slug.
 * @param string $content Content.
 * @return string URL.
 */
function bzpl_test_page( $slug, $content ) {
	$page = get_page_by_path( $slug );
	$data = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $slug,
		'post_title'   => ucwords( str_replace( '-', ' ', $slug ) ),
		'post_content' => $content,
	);

	if ( $page ) {
		$data['ID'] = $page->ID;
	}

	return get_permalink( wp_insert_post( $data ) );
}

$pages = array(
	'features' => bzpl_test_page( 'feature-tests',"[banzai-play game=\"generic-canvas\"]\n\n[banzai-play game=\"phaser-global\"]" ),
	'queue'    => bzpl_test_page( 'queue-tests', "[banzai-play game=\"unity-plain\" autoplay=\"true\"]\n\n[banzai-play game=\"godot\" autoplay=\"true\"]\n\n[banzai-play game=\"generic-canvas\" autoplay=\"true\"]" ),
	'gallery'  => bzpl_test_page( 'gallery-tests', '[banzai-play-gallery columns="3"]' ),
);

echo wp_json_encode(
	array(
		'cover' => $cover,
		'logo'  => $logo,
		'pages' => $pages,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
