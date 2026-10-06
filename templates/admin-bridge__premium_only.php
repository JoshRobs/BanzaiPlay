<?php
/**
 * Data Bridge card on the edit screen (Pro). Not in the free build.
 *
 * Rendered by Data_Bridge::render_card(), inside the edit form. Rows are
 * plain inputs plus one blank row, so the card works without JavaScript;
 * admin-pro__premium_only.js adds and removes rows by cloning the <template>.
 *
 * @package BanzaiPlay
 *
 * @var array                 $game     Record.
 * @var bool                  $licensed Whether the config may be changed.
 * @var array                 $config   Data_Bridge::config().
 * @var array<string,string>  $sources  Data_Bridge::sources().
 * @var array<string,string>  $fields   Data_Bridge::user_fields().
 * @var string                $usage    Example code.
 */

use BanzaiPlay\Data_Bridge;

defined( 'ABSPATH' ) || exit;

$value_row = static function ( $i, array $row ) use ( $sources ) {
	$takes = Data_Bridge::takes_value( $row['source'] );
	?>
	<tr class="bzpl-bridge-row">
		<td><input type="text" name="bridge_values[<?php echo esc_attr( $i ); ?>][key]" class="code" pattern="[A-Za-z_$][A-Za-z0-9_$]*" maxlength="64" placeholder="levelSet" aria-label="<?php esc_attr_e( 'Key', 'banzaiplay' ); ?>" value="<?php echo esc_attr( $row['key'] ); ?>"></td>
		<td>
			<select name="bridge_values[<?php echo esc_attr( $i ); ?>][source]" class="bzpl-bridge-source" aria-label="<?php esc_attr_e( 'Source', 'banzaiplay' ); ?>">
				<?php foreach ( $sources as $source => $label ) : ?>
					<option value="<?php echo esc_attr( $source ); ?>" <?php selected( $row['source'], $source ); ?>
						<?php if ( Data_Bridge::takes_value( $source ) ) : ?>
							data-placeholder="<?php echo esc_attr( 'post.meta' === $source ? __( 'Custom field key', 'banzaiplay' ) : __( 'Value', 'banzaiplay' ) ); ?>"
						<?php endif; ?>
					><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td><input type="text" name="bridge_values[<?php echo esc_attr( $i ); ?>][value]" class="bzpl-bridge-value" aria-label="<?php esc_attr_e( 'Value', 'banzaiplay' ); ?>" placeholder="<?php echo esc_attr( 'post.meta' === $row['source'] ? __( 'Custom field key', 'banzaiplay' ) : __( 'Value', 'banzaiplay' ) ); ?>" value="<?php echo esc_attr( $row['value'] ); ?>"<?php echo $takes ? '' : ' hidden'; ?>></td>
		<td><button type="button" class="button-link bzpl-bridge-remove" aria-label="<?php esc_attr_e( 'Remove', 'banzaiplay' ); ?>">&times;</button></td>
	</tr>
	<?php
};

$blank_value = array(
	'key'    => '',
	'source' => 'static',
	'value'  => '',
);
?>
<section class="bzpl-card bzpl-card-pro bzpl-bridge">
	<header class="bzpl-card-header">
		<h2><span class="bzpl-card-icon dashicons dashicons-database-export" aria-hidden="true"></span><?php esc_html_e( 'Data Bridge', 'banzaiplay' ); ?></h2>
		<span class="bzpl-pro-badge"><span class="dashicons dashicons-star-filled" aria-hidden="true"></span><?php esc_html_e( 'Pro', 'banzaiplay' ); ?></span>
	</header>
	<div class="bzpl-card-body">
	<?php if ( ! $licensed ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'Your game keeps receiving this data. Changing it needs an active BanzaiPlay Pro licence.', 'banzaiplay' ); ?>
				<a href="<?php echo esc_url( banzaiplay_fs()->get_upgrade_url() ); ?>"><?php esc_html_e( 'View plans', 'banzaiplay' ); ?></a>
			</p>
		</div>
	<?php endif; ?>
	<?php // Disabled, nothing here is posted — not even the marker — so the saved config stays. ?>
	<fieldset class="bzpl-fieldset" <?php disabled( ! $licensed ); ?>>
	<input type="hidden" name="bzpl_bridge" value="1">
	<p class="description"><?php esc_html_e( 'WordPress data your game reads from window.BanzaiPlay inside its frame — from a Unity .jslib, Godot\'s JavaScriptBridge or plain JavaScript.', 'banzaiplay' ); ?></p>

	<h3 class="bzpl-section-title"><?php esc_html_e( 'Values', 'banzaiplay' ); ?> <code>BanzaiPlay.data</code></h3>
	<p class="description"><?php esc_html_e( 'Written into the page, so the same for every visitor and visible in the page source. Post values are for the post being viewed; elsewhere they are null.', 'banzaiplay' ); ?></p>
	<table class="widefat bzpl-bridge-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Key', 'banzaiplay' ); ?></th>
				<th><?php esc_html_e( 'Source', 'banzaiplay' ); ?></th>
				<th><?php esc_html_e( 'Value', 'banzaiplay' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'banzaiplay' ); ?></span></th>
			</tr>
		</thead>
		<tbody class="bzpl-bridge-rows">
			<?php
			foreach ( $config['values'] as $i => $row ) {
				$value_row( $i, $row );
			}
			$value_row( count( $config['values'] ), $blank_value );
			?>
		</tbody>
	</table>
	<template class="bzpl-bridge-template"><?php $value_row( '__i__', $blank_value ); ?></template>
	<p><button type="button" class="button bzpl-bridge-add"><?php esc_html_e( 'Add value', 'banzaiplay' ); ?></button></p>

	<h3 class="bzpl-section-title"><?php esc_html_e( 'The player', 'banzaiplay' ); ?> <code>BanzaiPlay.user()</code></h3>
	<p class="description"><?php esc_html_e( 'Fetched by your game when it calls BanzaiPlay.user(), never written into the page, so page caches cannot show one visitor\'s details to another. Each visitor only ever receives their own. loggedIn is always included.', 'banzaiplay' ); ?></p>
	<fieldset class="bzpl-bridge-user">
		<legend class="screen-reader-text"><?php esc_html_e( 'User fields', 'banzaiplay' ); ?></legend>
		<?php foreach ( $fields as $field => $label ) : ?>
			<label><input type="checkbox" name="bridge_user[]" value="<?php echo esc_attr( $field ); ?>" <?php checked( in_array( $field, $config['user'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
		<?php endforeach; ?>
	</fieldset>
	<p class="description"><?php esc_html_e( 'Email and roles are personal data: mention them in your privacy policy. The REST API nonce lets the game call the REST API as the logged-in player — to save progress or a high score — sent as the X-WP-Nonce header.', 'banzaiplay' ); ?></p>

	<details class="bzpl-tip">
		<summary><?php esc_html_e( 'Usage in your game', 'banzaiplay' ); ?></summary>
		<pre class="bzpl-code"><?php echo esc_html( $usage ); ?></pre>
		<p class="description">
			<?php
			/* translators: %s: game slug. */
			echo esc_html( sprintf( __( 'Reflects the saved settings. Save to update it. The page around the game has the same values at window.BanzaiPlay.games[\'%s\'].', 'banzaiplay' ), $game['slug'] ) );
			?>
		</p>
	</details>
	</fieldset>
	</div>
</section>
