<?php
/**
 * Loading screen card on the edit screen (Pro). Not in the free build.
 *
 * Rendered by Branding::render_card(), inside the edit form.
 *
 * @package BanzaiPlay
 *
 * @var array  $game       Record.
 * @var bool   $licensed   Whether the look may be changed.
 * @var array  $look       Branding::look().
 * @var int    $site_logo  Logo from the Settings screen, or 0.
 * @var string $site_color Colour from the Settings screen, or ''.
 */

use BanzaiPlay\Branding;
use BanzaiPlay\Settings;

defined( 'ABSPATH' ) || exit;
?>
<section class="bzpl-card bzpl-card-pro bzpl-look-card">
	<header class="bzpl-card-header">
		<h2><span class="bzpl-card-icon dashicons dashicons-art" aria-hidden="true"></span><?php esc_html_e( 'Loading screen', 'banzaiplay' ); ?></h2>
		<span class="bzpl-pro-badge"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><?php esc_html_e( 'Pro', 'banzaiplay' ); ?></span>
	</header>
	<div class="bzpl-card-body">
	<?php if ( ! $licensed ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'Your game keeps this look. Changing it needs an active BanzaiPlay Pro licence.', 'banzaiplay' ); ?>
				<a href="<?php echo esc_url( banzaiplay_fs()->get_upgrade_url() ); ?>"><?php esc_html_e( 'View plans', 'banzaiplay' ); ?></a>
			</p>
		</div>
	<?php endif; ?>
	<?php // Disabled, nothing here is posted — not even the marker — so the saved look stays. ?>
	<fieldset class="bzpl-fieldset" <?php disabled( ! $licensed ); ?>>
		<input type="hidden" name="bzpl_look" value="1">

		<div class="bzpl-field-row">
			<div class="bzpl-field">
				<span class="bzpl-label"><?php esc_html_e( 'Cover image', 'banzaiplay' ); ?></span>
				<?php Branding::media_field( 'look_cover', (int) $look['cover'], __( 'Cover image', 'banzaiplay' ), ! $licensed ); ?>
				<label class="bzpl-checkbox">
					<input type="checkbox" name="look_backdrop" value="1" <?php checked( $look['backdrop'] ); ?>>
					<?php esc_html_e( 'Show it behind the Play and loading screens', 'banzaiplay' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Key art or a screenshot, landscape, at least the player\'s size. Also the game\'s picture in galleries.', 'banzaiplay' ); ?></p>
			</div>

			<div class="bzpl-field">
				<span class="bzpl-label"><?php esc_html_e( 'Logo', 'banzaiplay' ); ?></span>
				<?php Branding::media_field( 'look_logo', (int) $look['logo'], __( 'Logo', 'banzaiplay' ), ! $licensed ); ?>
				<p class="description">
					<?php
					echo $site_logo
						? esc_html__( 'Shown above the game\'s title. Leave empty to use the logo from Settings.', 'banzaiplay' )
						: esc_html__( 'Shown above the game\'s title. A transparent PNG or SVG works best.', 'banzaiplay' );
					?>
				</p>
			</div>
		</div>

		<div class="bzpl-field">
			<label for="bzpl-look-color"><?php esc_html_e( 'Colour', 'banzaiplay' ); ?></label>
			<input type="text" id="bzpl-look-color" name="look_color" class="bzpl-color" maxlength="7" placeholder="#ffe200" value="<?php echo esc_attr( $look['color'] ); ?>" data-default-color="<?php echo esc_attr( $site_color ); ?>">
			<p class="description">
				<?php
				echo '' !== $site_color
					/* translators: %s: colour such as #ff6600. */
					? esc_html( sprintf( __( 'The Play button, progress bar and focus glow. Leave empty to use %s, from Settings.', 'banzaiplay' ), $site_color ) )
					: esc_html__( 'The Play button, progress bar and focus glow. Leave empty for BanzaiPlay\'s yellow and orange.', 'banzaiplay' );
				?>
				<a href="<?php echo esc_url( Settings::url() ); ?>"><?php esc_html_e( 'Defaults for every game', 'banzaiplay' ); ?></a>
			</p>
		</div>

		<p class="description bzpl-pro-note">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<?php esc_html_e( '"Powered by BanzaiPlay" is not shown while your Pro licence is active.', 'banzaiplay' ); ?>
		</p>
	</fieldset>
	</div>
</section>
