<?php
/**
 * BanzaiPlay → Settings (Pro). Not in the free build.
 *
 * @package BanzaiPlay
 *
 * @var array $settings Settings::all().
 * @var bool  $licensed Whether the settings may be changed.
 */

use BanzaiPlay\Admin;
use BanzaiPlay\Analytics;
use BanzaiPlay\Branding;
use BanzaiPlay\Plays;
use BanzaiPlay\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="bzpl-page-head">
	<div>
		<p class="bzpl-breadcrumb">
			<a href="<?php echo esc_url( Admin::list_url() ); ?>"><?php esc_html_e( 'All Games', 'banzaiplay' ); ?></a>
			<span aria-hidden="true">/</span>
			<span><?php esc_html_e( 'Settings', 'banzaiplay' ); ?></span>
		</p>
		<h1><?php esc_html_e( 'Settings', 'banzaiplay' ); ?></h1>
	</div>
</div>
<hr class="wp-header-end">

<?php if ( ! $licensed ) : ?>
	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'These settings stay in effect. Changing them needs an active BanzaiPlay Pro licence.', 'banzaiplay' ); ?>
			<a href="<?php echo esc_url( banzaiplay_fs()->get_upgrade_url() ); ?>"><?php esc_html_e( 'View plans', 'banzaiplay' ); ?></a>
		</p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bzpl-form bzpl-settings">
	<input type="hidden" name="action" value="<?php echo esc_attr( Settings::SAVE_ACTION ); ?>">
	<?php wp_nonce_field( Settings::SAVE_ACTION ); ?>

	<fieldset class="bzpl-fieldset" <?php disabled( ! $licensed ); ?>>
	<div class="bzpl-settings-grid">
		<section class="bzpl-card bzpl-card-pro">
			<header class="bzpl-card-header">
				<h2><span class="bzpl-card-icon dashicons dashicons-art" aria-hidden="true"></span><?php esc_html_e( 'Loading screen', 'banzaiplay' ); ?></h2>
				<span class="bzpl-pro-badge"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><?php esc_html_e( 'Pro', 'banzaiplay' ); ?></span>
			</header>
			<div class="bzpl-card-body">
				<p class="description"><?php esc_html_e( 'For every game that doesn\'t set its own on its Loading screen card.', 'banzaiplay' ); ?></p>
				<div class="bzpl-field">
					<span class="bzpl-label"><?php esc_html_e( 'Logo', 'banzaiplay' ); ?></span>
					<?php Branding::media_field( 'logo', (int) $settings['logo'], __( 'Logo', 'banzaiplay' ), ! $licensed ); ?>
					<p class="description"><?php esc_html_e( 'Shown above each game\'s title on the Play and loading screens — your studio\'s mark, say.', 'banzaiplay' ); ?></p>
				</div>
				<div class="bzpl-field">
					<label for="bzpl-settings-color"><?php esc_html_e( 'Colour', 'banzaiplay' ); ?></label>
					<input type="text" id="bzpl-settings-color" name="color" class="bzpl-color" maxlength="7" placeholder="#ffe200" value="<?php echo esc_attr( $settings['color'] ); ?>" data-default-color="">
					<p class="description"><?php esc_html_e( 'The Play button, progress bar and focus glow. Empty for BanzaiPlay\'s yellow and orange.', 'banzaiplay' ); ?></p>
				</div>
			</div>
		</section>

		<section class="bzpl-card bzpl-card-pro">
			<header class="bzpl-card-header">
				<h2><span class="bzpl-card-icon dashicons dashicons-images-alt2" aria-hidden="true"></span><?php esc_html_e( 'Pages with several games', 'banzaiplay' ); ?></h2>
				<span class="bzpl-pro-badge"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><?php esc_html_e( 'Pro', 'banzaiplay' ); ?></span>
			</header>
			<div class="bzpl-card-body">
				<p class="description"><?php esc_html_e( 'Games never download until they start. Games that start with the page load one after another, so the first is playable sooner.', 'banzaiplay' ); ?></p>
				<div class="bzpl-field">
					<label class="bzpl-checkbox">
						<input type="checkbox" name="one_at_a_time" value="1" <?php checked( $settings['one_at_a_time'] ); ?>>
						<?php esc_html_e( 'Play one game at a time', 'banzaiplay' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Starting a game closes any other on the page, freeing its memory — for portfolio pages of heavy Unity or Godot games. Only the first game set to start with the page does.', 'banzaiplay' ); ?></p>
				</div>
				<div class="bzpl-field">
					<label class="bzpl-checkbox">
						<input type="checkbox" name="unload_hidden" value="1" <?php checked( $settings['unload_hidden'] ); ?>>
						<?php esc_html_e( 'Close a game that has been scrolled out of view', 'banzaiplay' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'After ten seconds out of view, the game closes and shows its Play button again. Progress the game hasn\'t saved is lost, so this suits short or self-saving games.', 'banzaiplay' ); ?></p>
				</div>
			</div>
		</section>

		<section class="bzpl-card bzpl-card-pro">
			<header class="bzpl-card-header">
				<h2><span class="bzpl-card-icon dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( 'Play statistics', 'banzaiplay' ); ?></h2>
				<span class="bzpl-pro-badge"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><?php esc_html_e( 'Pro', 'banzaiplay' ); ?></span>
			</header>
			<div class="bzpl-card-body">
				<div class="bzpl-field">
					<label class="bzpl-checkbox">
						<input type="checkbox" name="record" value="1" <?php checked( $settings['record'] ); ?>>
						<?php esc_html_e( 'Record plays for Analytics', 'banzaiplay' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Anonymous and kept on your site: no IP addresses, cookies or user IDs. Game events still reach your hooks when this is off.', 'banzaiplay' ); ?> <a href="<?php echo esc_url( Analytics::url() ); ?>"><?php esc_html_e( 'View Analytics', 'banzaiplay' ); ?></a></p>
				</div>
				<div class="bzpl-field">
					<label for="bzpl-keep-days"><?php esc_html_e( 'Keep plays for', 'banzaiplay' ); ?></label>
					<select id="bzpl-keep-days" name="keep_days">
						<?php
						$keep = array(
							30   => __( '30 days', 'banzaiplay' ),
							90   => __( '90 days', 'banzaiplay' ),
							365  => __( '1 year', 'banzaiplay' ),
							730  => __( '2 years', 'banzaiplay' ),
							0    => __( 'Forever', 'banzaiplay' ),
						);

						if ( ! isset( $keep[ $settings['keep_days'] ] ) ) {
							/* translators: %d: number of days. */
							$keep[ $settings['keep_days'] ] = sprintf( __( '%d days', 'banzaiplay' ), $settings['keep_days'] );
						}

						foreach ( $keep as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (int) $settings['keep_days'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Older plays are deleted once a day.', 'banzaiplay' ); ?></p>
				</div>
			</div>
		</section>
	</div>
	</fieldset>

	<p class="bzpl-settings-submit">
		<button type="submit" class="button button-primary button-large" <?php disabled( ! $licensed ); ?>><?php esc_html_e( 'Save Settings', 'banzaiplay' ); ?></button>
	</p>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bzpl-purge" data-confirm="<?php esc_attr_e( 'Delete every recorded play? Analytics starts again from nothing. This cannot be undone.', 'banzaiplay' ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( Plays::PURGE_ACTION ); ?>">
	<?php wp_nonce_field( Plays::PURGE_ACTION ); ?>
	<button type="submit" class="button-link bzpl-delete-link"><?php esc_html_e( 'Delete all play statistics', 'banzaiplay' ); ?></button>
</form>
