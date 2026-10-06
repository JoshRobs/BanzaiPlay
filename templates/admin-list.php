<?php
/**
 * All Games screen.
 *
 * @package BanzaiPlay
 *
 * @var array[] $games  Records to show, filtered and sorted.
 * @var array   $query  { engine, status, s, orderby, order } from the URL.
 * @var int[]   $counts { all, active, inactive } in the current tab.
 * @var int     $total  Games in every tab.
 */

use BanzaiPlay\Admin;
use BanzaiPlay\Game_Manager;

defined( 'ABSPATH' ) || exit;

// Every link keeps the current tab. Choosing a view clears the search;
// searching keeps the view.
$tab_args = array_filter( array( 'engine' => $query['engine'] ) );
$views    = array(
	'all'      => __( 'All', 'banzaiplay' ),
	'active'   => __( 'Active', 'banzaiplay' ),
	'inactive' => __( 'Inactive', 'banzaiplay' ),
);
$filtered = '' !== $query['status'] || '' !== $query['s'];

/**
 * A sortable column heading.
 *
 * @param string $column  orderby value.
 * @param string $label   Heading text.
 * @param string $first   Order on the first click.
 */
$bzpl_sort_link = static function ( $column, $label, $first ) use ( $query, $tab_args ) {
	$current = $column === $query['orderby'];
	$order   = $current ? ( 'asc' === $query['order'] ? 'desc' : 'asc' ) : $first;
	$args    = $tab_args + array_filter(
		array(
			'status'  => $query['status'],
			's'       => $query['s'],
			'orderby' => $column,
			'order'   => $order,
		)
	);

	printf(
		'<a class="bzpl-sort%s" href="%s"><span>%s</span><span class="bzpl-sort-arrow dashicons dashicons-arrow-%s" aria-hidden="true"></span></a>',
		$current ? ' is-sorted' : '',
		esc_url( Admin::list_url( $args ) ),
		esc_html( $label ),
		$current && 'desc' === $query['order'] ? 'down' : 'up'
	);
};
?>
<h1 class="screen-reader-text"><?php esc_html_e( 'All Games', 'banzaiplay' ); ?></h1>
<hr class="wp-header-end">

<?php if ( ! $total ) : ?>

	<div class="bzpl-empty">
		<?php echo Admin::logo( 64 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
		<h2><?php esc_html_e( 'Embed your first game', 'banzaiplay' ); ?></h2>
		<p><?php esc_html_e( 'Unity, Godot, Phaser, Construct 3, PlayCanvas, PixiJS or any HTML5 game — with a loading screen, fullscreen and a keyboard that only belongs to the game while you play.', 'banzaiplay' ); ?></p>
		<ol class="bzpl-steps">
			<li><strong><?php esc_html_e( 'Export', 'banzaiplay' ); ?></strong> <?php esc_html_e( 'your game for the web, as you would for itch.io.', 'banzaiplay' ); ?></li>
			<li><strong><?php esc_html_e( 'Zip', 'banzaiplay' ); ?></strong> <?php esc_html_e( 'the exported folder.', 'banzaiplay' ); ?></li>
			<li><strong><?php esc_html_e( 'Upload', 'banzaiplay' ); ?></strong> <?php esc_html_e( 'it, then drop the shortcode or block on any page.', 'banzaiplay' ); ?></li>
		</ol>
		<a class="button button-primary button-hero" href="<?php echo esc_url( Admin::new_url() ); ?>"><?php esc_html_e( 'Add your first game', 'banzaiplay' ); ?></a>
	</div>

<?php else : ?>

	<div class="bzpl-toolbar">
		<ul class="bzpl-views">
			<?php foreach ( $views as $key => $label ) : ?>
				<?php
				$is_current = ( 'all' === $key ? '' : $key ) === $query['status'];
				$args       = $tab_args + array_filter( array( 'status' => 'all' === $key ? '' : $key ) );
				?>
				<li>
					<a href="<?php echo esc_url( Admin::list_url( $args ) ); ?>" class="<?php echo $is_current ? 'is-current' : ''; ?>" <?php echo $is_current ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
						<span class="bzpl-view-count" data-bzpl-count="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<form class="bzpl-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE ); ?>">
			<?php foreach ( array( 'engine', 'status' ) as $key ) : ?>
				<?php if ( '' !== $query[ $key ] ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $query[ $key ] ); ?>">
				<?php endif; ?>
			<?php endforeach; ?>
			<label class="screen-reader-text" for="bzpl-search-input"><?php esc_html_e( 'Search games', 'banzaiplay' ); ?></label>
			<span class="bzpl-search-field">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<input type="search" id="bzpl-search-input" name="s" value="<?php echo esc_attr( $query['s'] ); ?>" placeholder="<?php esc_attr_e( 'Search by name or slug', 'banzaiplay' ); ?>">
			</span>
			<button type="submit" class="button"><?php esc_html_e( 'Search Games', 'banzaiplay' ); ?></button>
		</form>
	</div>

	<table class="wp-list-table widefat fixed bzpl-games">
		<thead>
			<tr>
				<th scope="col" class="column-primary bzpl-col-name"><?php $bzpl_sort_link( 'name', __( 'Game', 'banzaiplay' ), 'asc' ); ?></th>
				<th scope="col" class="bzpl-col-engine"><?php esc_html_e( 'Engine', 'banzaiplay' ); ?></th>
				<th scope="col" class="bzpl-col-shortcode"><?php esc_html_e( 'Shortcode', 'banzaiplay' ); ?></th>
				<th scope="col" class="bzpl-col-uploaded"><?php $bzpl_sort_link( 'uploaded', __( 'Uploaded', 'banzaiplay' ), 'desc' ); ?></th>
				<th scope="col" class="bzpl-col-size"><?php $bzpl_sort_link( 'size', __( 'Build size', 'banzaiplay' ), 'desc' ); ?></th>
				<th scope="col" class="bzpl-col-status"><?php esc_html_e( 'Status', 'banzaiplay' ); ?></th>
				<th scope="col" class="bzpl-col-active"><?php esc_html_e( 'Active', 'banzaiplay' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $games ) : ?>
				<tr class="no-items">
					<td colspan="7" class="bzpl-no-results">
						<?php esc_html_e( 'No games match.', 'banzaiplay' ); ?>
						<?php if ( $filtered ) : ?>
							<a href="<?php echo esc_url( Admin::list_url( $tab_args ) ); ?>"><?php esc_html_e( 'Clear filters', 'banzaiplay' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $games as $game ) : ?>
				<?php
				$status                 = Game_Manager::status( $game );
				$active                 = Game_Manager::is_active( $game );
				$shortcode              = Game_Manager::shortcode( $game );
				list( $width, $height ) = Game_Manager::dimensions( $game );
				$warnings               = count( $game['warnings'] ) + ( $game['needs_rebuild'] ? 1 : 0 );
				?>
				<tr class="<?php echo $active ? '' : 'is-inactive'; ?>">
					<td class="column-primary bzpl-col-name">
						<strong><a class="row-title" href="<?php echo esc_url( Admin::edit_url( $game['slug'] ) ); ?>"><?php echo esc_html( $game['name'] ); ?></a></strong>
						<span class="bzpl-subtle"><?php echo esc_html( sprintf( '%d × %d', $width, $height ) . ( Game_Manager::starts_on_load( $game ) ? ' · ' . __( 'starts with the page', 'banzaiplay' ) : '' ) ); ?></span>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url( Admin::edit_url( $game['slug'] ) ); ?>"><?php esc_html_e( 'Edit', 'banzaiplay' ); ?></a> | </span>
							<span class="copy"><button type="button" class="button-link bzpl-copy" data-copy="<?php echo esc_attr( $shortcode ); ?>"><?php esc_html_e( 'Copy Shortcode', 'banzaiplay' ); ?></button> | </span>
							<span class="trash"><a class="submitdelete bzpl-delete" href="<?php echo esc_url( Admin::delete_url( $game['slug'] ) ); ?>" data-name="<?php echo esc_attr( $game['name'] ); ?>"><?php esc_html_e( 'Delete', 'banzaiplay' ); ?></a></span>
						</div>
						<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'banzaiplay' ); ?></span></button>
					</td>
					<td class="bzpl-col-engine" data-colname="<?php esc_attr_e( 'Engine', 'banzaiplay' ); ?>">
						<?php if ( '' !== $game['build'] ) : ?>
							<?php echo Admin::engine_badge( $game['engine'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td class="bzpl-col-shortcode" data-colname="<?php esc_attr_e( 'Shortcode', 'banzaiplay' ); ?>">
						<span class="bzpl-chip">
							<code class="bzpl-shortcode"><?php echo esc_html( $shortcode ); ?></code>
							<button type="button" class="bzpl-copy bzpl-icon-button" data-copy="<?php echo esc_attr( $shortcode ); ?>" aria-label="<?php esc_attr_e( 'Copy shortcode', 'banzaiplay' ); ?>" title="<?php esc_attr_e( 'Copy shortcode', 'banzaiplay' ); ?>">
								<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
							</button>
						</span>
					</td>
					<td class="bzpl-col-uploaded" data-colname="<?php esc_attr_e( 'Uploaded', 'banzaiplay' ); ?>">
						<?php if ( $game['uploaded'] ) : ?>
							<span class="bzpl-date"><?php echo esc_html( wp_date( get_option( 'date_format' ), $game['uploaded'] ) ); ?></span>
							<span class="bzpl-time"><?php echo esc_html( wp_date( get_option( 'time_format' ), $game['uploaded'] ) ); ?></span>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td class="bzpl-col-size" data-colname="<?php esc_attr_e( 'Build size', 'banzaiplay' ); ?>">
						<?php if ( $game['size'] ) : ?>
							<span class="bzpl-date"><?php echo esc_html( size_format( $game['size'], 1 ) ); ?></span>
							<?php /* translators: %s: number of files. */ ?>
							<span class="bzpl-time"><?php echo esc_html( sprintf( _n( '%s file', '%s files', $game['files'], 'banzaiplay' ), number_format_i18n( $game['files'] ) ) ); ?></span>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td class="bzpl-col-status" data-colname="<?php esc_attr_e( 'Status', 'banzaiplay' ); ?>">
						<span class="bzpl-status bzpl-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( Game_Manager::status_label( $status ) ); ?></span>
						<?php if ( $warnings ) : ?>
							<a class="bzpl-warning-count" href="<?php echo esc_url( Admin::edit_url( $game['slug'] ) ); ?>">
								<span class="dashicons dashicons-warning" aria-hidden="true"></span>
								<?php
								/* translators: %s: number of warnings. */
								echo esc_html( sprintf( _n( '%s note', '%s notes', $warnings, 'banzaiplay' ), number_format_i18n( $warnings ) ) );
								?>
							</a>
						<?php endif; ?>
					</td>
					<td class="bzpl-col-active" data-colname="<?php esc_attr_e( 'Active', 'banzaiplay' ); ?>">
						<form class="bzpl-toggle-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Admin::TOGGLE_ACTION ); ?>">
							<input type="hidden" name="game" value="<?php echo esc_attr( $game['slug'] ); ?>">
							<input type="hidden" name="active" value="<?php echo $active ? '0' : '1'; ?>">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( Admin::TOGGLE_ACTION . '_' . $game['slug'] ) ); ?>">
							<?php wp_referer_field(); ?>
							<button
								type="submit"
								class="bzpl-switch"
								role="switch"
								aria-checked="<?php echo $active ? 'true' : 'false'; ?>"
								<?php /* translators: %s: game name. */ ?>
								aria-label="<?php echo esc_attr( sprintf( __( '%s is active', 'banzaiplay' ), $game['name'] ) ); ?>"
								title="<?php echo $active ? esc_attr__( 'Deactivate', 'banzaiplay' ) : esc_attr__( 'Activate', 'banzaiplay' ); ?>"
							></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

<?php endif; ?>
