<?php
/**
 * Gallery card on the edit screen (Pro). Not in the free build.
 *
 * Rendered by Gallery::render_card(), inside the edit form.
 *
 * @package BanzaiPlay
 *
 * @var array    $game     Record.
 * @var bool     $licensed Whether the fields may be changed.
 * @var array    $fields   Gallery::fields().
 * @var int      $cover    Cover image ID, or 0.
 * @var string[] $all_tags Every tag in use, for suggestions.
 */

use BanzaiPlay\Gallery;

defined( 'ABSPATH' ) || exit;
?>
<section class="bzpl-card bzpl-card-pro bzpl-gallery-card">
	<header class="bzpl-card-header">
		<h2><span class="bzpl-card-icon dashicons dashicons-grid-view" aria-hidden="true"></span><?php esc_html_e( 'Gallery', 'banzaiplay' ); ?></h2>
		<span class="bzpl-pro-badge"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><?php esc_html_e( 'Pro', 'banzaiplay' ); ?></span>
	</header>
	<div class="bzpl-card-body">
	<?php if ( ! $licensed ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'Galleries keep showing this game. Changing its tags or page needs an active BanzaiPlay Pro licence.', 'banzaiplay' ); ?>
				<a href="<?php echo esc_url( banzaiplay_fs()->get_upgrade_url() ); ?>"><?php esc_html_e( 'View plans', 'banzaiplay' ); ?></a>
			</p>
		</div>
	<?php endif; ?>
	<fieldset class="bzpl-fieldset" <?php disabled( ! $licensed ); ?>>
		<input type="hidden" name="bzpl_gallery" value="1">
		<p class="description">
			<?php esc_html_e( 'Show your games in a grid that plays each one in a lightbox:', 'banzaiplay' ); ?>
			<code>[<?php echo esc_html( Gallery::TAG ); ?>]</code>
			<?php if ( ! $cover ) : ?>
				<?php esc_html_e( 'Add a cover image above to give this game a picture there.', 'banzaiplay' ); ?>
			<?php endif; ?>
		</p>

		<div class="bzpl-field">
			<label for="bzpl-gallery-tags"><?php esc_html_e( 'Tags', 'banzaiplay' ); ?> <span class="bzpl-optional"><?php esc_html_e( 'Optional', 'banzaiplay' ); ?></span></label>
			<input type="text" id="bzpl-gallery-tags" name="gallery_tags" class="widefat" list="bzpl-gallery-tag-list" value="<?php echo esc_attr( implode( ', ', $fields['tags'] ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Puzzle, Game jam, 2025', 'banzaiplay' ); ?>">
			<?php if ( $all_tags ) : ?>
				<datalist id="bzpl-gallery-tag-list">
					<?php foreach ( $all_tags as $tag ) : ?>
						<option value="<?php echo esc_attr( $tag ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Separated by commas. Galleries offer them as filters, and tags="…" shows only games with them.', 'banzaiplay' ); ?>
				<?php if ( $all_tags ) : ?>
					<?php /* translators: %s: comma-separated tags. */ ?>
					<?php echo esc_html( sprintf( __( 'In use: %s.', 'banzaiplay' ), implode( ', ', $all_tags ) ) ); ?>
				<?php endif; ?>
			</p>
		</div>

		<div class="bzpl-field">
			<label for="bzpl-gallery-page"><?php esc_html_e( 'Game page', 'banzaiplay' ); ?> <span class="bzpl-optional"><?php esc_html_e( 'Optional', 'banzaiplay' ); ?></span></label>
			<input type="url" id="bzpl-gallery-page" name="gallery_page" class="widefat code" value="<?php echo esc_attr( $fields['page'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/games/' . $game['slug'] . '/' ) ); ?>">
			<p class="description"><?php esc_html_e( 'A page about the game. Galleries with open="page" link here instead of opening the lightbox.', 'banzaiplay' ); ?></p>
		</div>

		<details class="bzpl-tip">
			<summary><?php esc_html_e( 'Gallery shortcode options', 'banzaiplay' ); ?></summary>
			<dl class="bzpl-attrs">
				<dt><code>tags="puzzle, jam"</code></dt>
				<dd><?php esc_html_e( 'Only games with any of these tags.', 'banzaiplay' ); ?></dd>
				<dt><code>games="<?php echo esc_html( $game['slug'] ); ?>, other-game"</code></dt>
				<dd><?php esc_html_e( 'Only these games, in this order.', 'banzaiplay' ); ?></dd>
				<dt><code>engine="unity"</code></dt>
				<dd><?php esc_html_e( 'Only games made with one engine.', 'banzaiplay' ); ?></dd>
				<dt><code>filter="tags"</code>, <code>"engine"</code>, <code>"none"</code></dt>
				<dd><?php esc_html_e( 'The filter buttons above the grid. Tags by default, when games have any.', 'banzaiplay' ); ?></dd>
				<dt><code>open="page"</code></dt>
				<dd><?php esc_html_e( 'Link to each game\'s page instead of the lightbox (games without one still open in the lightbox).', 'banzaiplay' ); ?></dd>
				<dt><code>columns="3"</code>, <code>orderby="date"</code>, <code>limit="6"</code></dt>
				<dd><?php esc_html_e( 'Layout and order: as many columns as fit, by name, unless set.', 'banzaiplay' ); ?></dd>
			</dl>
		</details>
	</fieldset>
	</div>
</section>
