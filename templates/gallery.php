<?php
/**
 * [banzai-play-gallery] markup.
 *
 * Rendered by Gallery::render(). Each card's title is the control that opens
 * the game — a button for the lightbox, a link for its page — and the picture
 * repeats it for the mouse only, so keyboards and screen readers meet one
 * control per game.
 *
 * @package BanzaiPlay
 *
 * @var \BanzaiPlay\Gallery    $this    The gallery.
 * @var array[]                $games   Games to show, in order.
 * @var string                 $id      Element ID.
 * @var string                 $open    'lightbox' or 'page'.
 * @var string                 $style   Inline CSS custom properties: columns, colour.
 * @var string                 $filter  'tags', 'engine' or 'none'.
 * @var array<string,string>   $chips   Filter buttons: token => label.
 * @var string[]               $classes Container classes.
 */

use BanzaiPlay\Branding;
use BanzaiPlay\Gallery;
use BanzaiPlay\Game_Manager;

defined( 'ABSPATH' ) || exit;

$play_icon = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z" fill="currentColor"/></svg>';
?>
<div id="<?php echo esc_attr( $id ); ?>" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-filter-kind="<?php echo esc_attr( $filter ); ?>"<?php echo '' !== $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>
	<?php if ( $chips ) : ?>
		<div class="banzaiplay-gallery-filters" role="group" aria-label="<?php esc_attr_e( 'Show games', 'banzaiplay' ); ?>">
			<button type="button" class="banzaiplay-gallery-filter" data-filter="" aria-pressed="true"><?php esc_html_e( 'All', 'banzaiplay' ); ?></button>
			<?php foreach ( $chips as $token => $label ) : ?>
				<button type="button" class="banzaiplay-gallery-filter" data-filter="<?php echo esc_attr( $token ); ?>" aria-pressed="false"><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>
		<p class="banzaiplay-gallery-status" aria-live="polite"></p>
	<?php endif; ?>

	<ul class="banzaiplay-gallery-grid" role="list">
		<?php
		foreach ( $games as $game ) :
			$fields  = Gallery::fields( $game );
			$cover   = Branding::cover( $game );
			$to_page = 'page' === $open && '' !== $fields['page'];
			$name_id = $id . '-' . $game['slug'];
			?>
			<li class="banzaiplay-gallery-item" data-game="<?php echo esc_attr( $game['slug'] ); ?>" data-engine="<?php echo esc_attr( $game['engine'] ); ?>" data-tags="<?php echo esc_attr( implode( ' ', Gallery::tokens( $game ) ) ); ?>">
				<article class="banzaiplay-gallery-card" aria-labelledby="<?php echo esc_attr( $name_id ); ?>">
					<?php if ( $to_page ) : ?>
						<a class="banzaiplay-gallery-thumb" href="<?php echo esc_url( $fields['page'] ); ?>" tabindex="-1" aria-hidden="true">
					<?php else : ?>
						<button type="button" class="banzaiplay-gallery-thumb" data-banzaiplay-open tabindex="-1" aria-hidden="true">
					<?php endif; ?>
						<?php if ( $cover ) : ?>
							<?php
							echo wp_get_attachment_image(
								$cover,
								'medium_large',
								false,
								array(
									'class'    => 'banzaiplay-gallery-image',
									'alt'      => '',
									'loading'  => 'lazy',
									'decoding' => 'async',
									'sizes'    => '(max-width: 600px) 100vw, 400px',
								)
							);
							?>
						<?php else : ?>
							<span class="banzaiplay-gallery-placeholder banzaiplay-engine-<?php echo esc_attr( $game['engine'] ); ?>"><?php echo esc_html( function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $game['name'], 0, 1 ) ) : strtoupper( substr( $game['name'], 0, 1 ) ) ); ?></span>
						<?php endif; ?>
						<span class="banzaiplay-gallery-play"><?php echo $play_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
					<?php echo $to_page ? '</a>' : '</button>'; ?>

					<div class="banzaiplay-gallery-body">
						<h3 class="banzaiplay-gallery-name" id="<?php echo esc_attr( $name_id ); ?>">
							<?php if ( $to_page ) : ?>
								<a href="<?php echo esc_url( $fields['page'] ); ?>"><?php echo esc_html( $game['name'] ); ?></a>
							<?php else : ?>
								<?php /* translators: %s: game name. */ ?>
								<button type="button" data-banzaiplay-open aria-haspopup="dialog" aria-label="<?php echo esc_attr( sprintf( __( 'Play %s', 'banzaiplay' ), $game['name'] ) ); ?>"><?php echo esc_html( $game['name'] ); ?></button>
							<?php endif; ?>
						</h3>
						<?php if ( '' !== $game['description'] ) : ?>
							<p class="banzaiplay-gallery-description"><?php echo esc_html( $game['description'] ); ?></p>
						<?php endif; ?>
						<p class="banzaiplay-gallery-meta">
							<span class="banzaiplay-gallery-engine"><?php echo esc_html( Game_Manager::engine_label( $game['engine'] ) ); ?></span>
							<?php foreach ( $fields['tags'] as $tag ) : ?>
								<span class="banzaiplay-gallery-tag"><?php echo esc_html( $tag ); ?></span>
							<?php endforeach; ?>
						</p>
					</div>
				</article>
				<?php if ( ! $to_page ) : ?>
					<template class="banzaiplay-gallery-player"><?php echo $this->player( $game ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></template>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="banzaiplay-gallery-none" hidden><?php esc_html_e( 'No games here yet.', 'banzaiplay' ); ?></p>

	<dialog class="banzaiplay-lightbox" aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
		<div class="banzaiplay-lightbox-head">
			<h2 class="banzaiplay-lightbox-title" id="<?php echo esc_attr( $id ); ?>-title"></h2>
			<button type="button" class="banzaiplay-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'banzaiplay' ); ?>" title="<?php esc_attr_e( 'Close', 'banzaiplay' ); ?>">
				<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
			</button>
		</div>
		<div class="banzaiplay-lightbox-body"></div>
	</dialog>
</div>
