<?php
/**
 * Add New / Edit Game screen.
 *
 * @package BanzaiPlay
 *
 * @var array|null          $game   Record, or null when adding.
 * @var bool                $is_new Whether this is the Add New screen.
 * @var array               $limits Admin::upload_limits().
 * @var \BanzaiPlay\Embed   $embed  Player renderer, for the preview.
 */

use BanzaiPlay\Admin;
use BanzaiPlay\Game_Manager;
use BanzaiPlay\Path_Rewriter;

defined( 'ABSPATH' ) || exit;

$record    = $is_new ? Game_Manager::defaults() : $game;
$has_build = ! $is_new && '' !== $record['build'];
$status    = Game_Manager::status( $record );
$active    = Game_Manager::is_active( $record );
$mode      = Game_Manager::mode( $record );
$datetime  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$warnings  = $record['warnings'];

if ( $record['needs_rebuild'] ) {
	$warnings[] = Path_Rewriter::rebuild_warning( $record['base'] );
}

list( $width, $height ) = Game_Manager::dimensions( $record );
$detected_w             = $record['detected_width'] ? $record['detected_width'] : Game_Manager::DEFAULT_WIDTH;
$detected_h             = $record['detected_height'] ? $record['detected_height'] : Game_Manager::DEFAULT_HEIGHT;
$entry_labels           = array(
	'unity' => __( 'Loader', 'banzaiplay' ),
	'godot' => __( 'Engine script', 'banzaiplay' ),
	'html'  => __( 'Page', 'banzaiplay' ),
);
$small_limit = $limits['max'] < 64 * MB_IN_BYTES;
?>
<div class="bzpl-page-head">
	<div>
		<p class="bzpl-breadcrumb">
			<a href="<?php echo esc_url( Admin::list_url() ); ?>"><?php esc_html_e( 'All Games', 'banzaiplay' ); ?></a>
			<span aria-hidden="true">/</span>
			<span><?php echo $is_new ? esc_html__( 'Add New', 'banzaiplay' ) : esc_html__( 'Edit', 'banzaiplay' ); ?></span>
		</p>
		<h1><?php echo $is_new ? esc_html__( 'Add New Game', 'banzaiplay' ) : esc_html( $record['name'] ); ?></h1>
	</div>
	<?php if ( ! $is_new ) : ?>
		<div class="bzpl-page-badges">
			<?php if ( $has_build ) : ?>
				<?php echo Admin::engine_badge( $record['engine'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			<?php endif; ?>
			<span class="bzpl-status bzpl-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( Game_Manager::status_label( $status ) ); ?></span>
			<?php if ( ! $active ) : ?>
				<span class="bzpl-status bzpl-status-inactive"><?php esc_html_e( 'Inactive', 'banzaiplay' ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
<hr class="wp-header-end">

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="bzpl-form">
	<input type="hidden" name="action" value="<?php echo esc_attr( Admin::SAVE_ACTION ); ?>">
	<input type="hidden" name="original_slug" value="<?php echo esc_attr( $is_new ? '' : $record['slug'] ); ?>">
	<?php wp_nonce_field( Admin::SAVE_ACTION ); ?>

	<div class="bzpl-columns">
		<div class="bzpl-main">

			<section class="bzpl-card">
				<header class="bzpl-card-header">
					<h2><span class="bzpl-card-icon dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e( 'Game details', 'banzaiplay' ); ?></h2>
				</header>
				<div class="bzpl-card-body">
					<div class="bzpl-field">
						<label for="bzpl-name"><?php esc_html_e( 'Game name', 'banzaiplay' ); ?></label>
						<input type="text" id="bzpl-name" name="name" class="widefat" required value="<?php echo esc_attr( $record['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Space Courier', 'banzaiplay' ); ?>">
					</div>

					<div class="bzpl-field">
						<label for="bzpl-slug"><?php esc_html_e( 'Slug', 'banzaiplay' ); ?></label>
						<?php if ( $is_new ) : ?>
							<input type="text" id="bzpl-slug" name="slug" class="widefat code" pattern="[a-z0-9-]*" maxlength="60" value="" placeholder="my-game">
							<p class="description">
								<?php esc_html_e( 'Generated from the name: lowercase letters, numbers and hyphens. It cannot be changed later.', 'banzaiplay' ); ?>
								<?php esc_html_e( 'Shortcode:', 'banzaiplay' ); ?>
								<code id="bzpl-slug-preview">[banzai-play game="my-game"]</code>
							</p>
						<?php else : ?>
							<p class="bzpl-static"><code><?php echo esc_html( $record['slug'] ); ?></code></p>
							<p class="description"><?php esc_html_e( 'Slugs cannot be changed, because shortcodes and blocks already on your pages refer to it.', 'banzaiplay' ); ?></p>
						<?php endif; ?>
					</div>

					<div class="bzpl-field">
						<label for="bzpl-description"><?php esc_html_e( 'Description', 'banzaiplay' ); ?> <span class="bzpl-optional"><?php esc_html_e( 'Optional', 'banzaiplay' ); ?></span></label>
						<textarea id="bzpl-description" name="description" class="large-text" rows="2" maxlength="500"><?php echo esc_textarea( $record['description'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'A line or two shown under the title on the Play screen, and in the block editor.', 'banzaiplay' ); ?></p>
					</div>
				</div>
			</section>

			<?php if ( 'ready' === $status ) : ?>
				<section class="bzpl-card bzpl-preview-card">
					<header class="bzpl-card-header">
						<h2><span class="bzpl-card-icon dashicons dashicons-controls-play" aria-hidden="true"></span><?php esc_html_e( 'Preview', 'banzaiplay' ); ?></h2>
						<?php if ( ! $active ) : ?>
							<span class="bzpl-method"><?php esc_html_e( 'Only you can see it while the game is inactive', 'banzaiplay' ); ?></span>
						<?php endif; ?>
					</header>
					<div class="bzpl-card-body">
						<?php echo $embed->render( $record['slug'], array( 'preview' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
						<p class="description"><?php esc_html_e( 'This is the player your visitors see. Settings changed below show here once saved. Here it always waits for Play, even when the game starts with the page.', 'banzaiplay' ); ?></p>
					</div>
				</section>
			<?php endif; ?>

			<section class="bzpl-card">
				<header class="bzpl-card-header">
					<h2><span class="bzpl-card-icon dashicons dashicons-upload" aria-hidden="true"></span><?php echo $has_build ? esc_html__( 'Replace build', 'banzaiplay' ) : esc_html__( 'Upload build', 'banzaiplay' ); ?></h2>
				</header>
				<div class="bzpl-card-body">
					<?php if ( ! $limits['zip'] ) : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'PHP\'s Zip extension is not installed, so builds are unpacked with a slower fallback that holds each file in memory. Large Unity builds may fail; ask your host to enable the zip extension.', 'banzaiplay' ); ?></p></div>
					<?php endif; ?>

					<label class="bzpl-dropzone" for="bzpl-build" data-bzpl-max="<?php echo esc_attr( $limits['max'] ); ?>">
						<input type="file" id="bzpl-build" name="build" accept=".zip,application/zip">
						<span class="bzpl-dropzone-icon dashicons dashicons-upload" aria-hidden="true"></span>
						<span class="bzpl-dropzone-text"><?php esc_html_e( 'Drop your game build .zip here', 'banzaiplay' ); ?></span>
						<span class="bzpl-dropzone-sub">
							<?php
							/* translators: %s: maximum upload size. */
							echo esc_html( sprintf( __( 'or click to choose one · up to %s on this server', 'banzaiplay' ), size_format( $limits['max'] ) ) );
							?>
						</span>
						<span class="bzpl-dropzone-file"></span>
						<span class="bzpl-upload-progress" hidden><span class="bzpl-upload-progress-fill"></span></span>
					</label>
					<?php if ( $has_build ) : ?>
						<p class="description"><?php esc_html_e( 'Uploading replaces the current build and detects its engine again. Players keep their saved progress: the game stays at the same address.', 'banzaiplay' ); ?></p>
					<?php endif; ?>

					<?php if ( $small_limit ) : ?>
						<div class="notice notice-info inline">
							<p>
								<?php
								/* translators: %s: maximum upload size. */
								echo esc_html( sprintf( __( 'This server accepts uploads up to %s. Unity and Godot builds are often 20–100 MB — see "Uploading large builds" below if yours is bigger.', 'banzaiplay' ), size_format( $limits['max'] ) ) );
								?>
							</p>
						</div>
					<?php endif; ?>

					<details class="bzpl-tip">
						<summary><?php esc_html_e( 'Uploading large builds', 'banzaiplay' ); ?></summary>
						<p>
							<?php
							printf(
								/* translators: 1: upload_max_filesize value, 2: post_max_size value. */
								esc_html__( 'The limit is the smaller of PHP\'s upload_max_filesize (now %1$s) and post_max_size (now %2$s). To raise both to 256 MB:', 'banzaiplay' ),
								'<code>' . esc_html( $limits['upload_max_filesize'] ) . '</code>',
								'<code>' . esc_html( $limits['post_max_size'] ) . '</code>'
							);
							?>
						</p>
						<ul>
							<li><?php esc_html_e( 'In php.ini, or a .user.ini file in your WordPress folder (most hosts using PHP-FPM):', 'banzaiplay' ); ?>
								<pre class="bzpl-code">upload_max_filesize = 256M
post_max_size = 256M
max_execution_time = 300</pre>
							</li>
							<li><?php esc_html_e( 'In .htaccess, on Apache with mod_php:', 'banzaiplay' ); ?>
								<pre class="bzpl-code">php_value upload_max_filesize 256M
php_value post_max_size 256M</pre>
							</li>
							<li><?php esc_html_e( 'On nginx, the server has its own limit too:', 'banzaiplay' ); ?> <code>client_max_body_size 256m;</code></li>
							<li><?php esc_html_e( 'Managed hosts often set these in their control panel ("PHP settings"), or will raise them on request.', 'banzaiplay' ); ?></li>
						</ul>
					</details>

					<details class="bzpl-tip">
						<summary><?php esc_html_e( 'Exporting from your engine', 'banzaiplay' ); ?></summary>
						<ul>
							<li><strong><?php esc_html_e( 'Unity', 'banzaiplay' ); ?></strong> — <?php esc_html_e( 'Build for WebGL and zip the folder with index.html, Build/ and TemplateData/ in it. Fastest loading: Compression Format "Disabled", or gzip/Brotli with "Decompression Fallback" on. Multithreading must be off.', 'banzaiplay' ); ?></li>
							<li><strong><?php esc_html_e( 'Godot', 'banzaiplay' ); ?></strong> — <?php esc_html_e( 'Export with the Web preset and zip the export folder (index.html, .js, .wasm, .pck). With Godot 4.3 or later, turn Thread Support off.', 'banzaiplay' ); ?></li>
							<li><strong><?php esc_html_e( 'Construct 3', 'banzaiplay' ); ?></strong> — <?php esc_html_e( 'Export as Web (HTML5) and upload the zip it creates.', 'banzaiplay' ); ?></li>
							<li><strong><?php esc_html_e( 'PlayCanvas', 'banzaiplay' ); ?></strong> — <?php esc_html_e( 'Download the project as a zip from the Publish screen and upload it as it is.', 'banzaiplay' ); ?></li>
							<li><strong><?php esc_html_e( 'Phaser, PixiJS and others', 'banzaiplay' ); ?></strong> — <?php esc_html_e( 'Build for production and zip the output folder (dist/ or build/). With Vite, set base: \'./\'.', 'banzaiplay' ); ?></li>
						</ul>
					</details>
				</div>
			</section>

			<?php if ( $has_build ) : ?>
				<section class="bzpl-card">
					<header class="bzpl-card-header">
						<h2><span class="bzpl-card-icon dashicons dashicons-admin-generic" aria-hidden="true"></span><?php esc_html_e( 'Engine', 'banzaiplay' ); ?></h2>
						<span class="bzpl-method">
							<?php
							if ( '' !== $record['detected_by'] ) {
								/* translators: 1: engine name, 2: file name. */
								echo esc_html( sprintf( __( 'Detected %1$s from %2$s', 'banzaiplay' ), Game_Manager::engine_label( $record['detected_engine'] ), $record['detected_by'] ) );
							} else {
								esc_html_e( 'No engine markers found — a generic HTML5 game', 'banzaiplay' );
							}
							?>
						</span>
					</header>
					<div class="bzpl-card-body">
						<?php foreach ( $warnings as $warning ) : ?>
							<div class="notice notice-warning inline"><p><?php echo esc_html( $warning ); ?></p></div>
						<?php endforeach; ?>

						<div class="bzpl-field-row">
							<div class="bzpl-field">
								<label for="bzpl-engine"><?php esc_html_e( 'Play as', 'banzaiplay' ); ?></label>
								<select id="bzpl-engine" name="engine_override">
									<option value="" <?php selected( $record['engine_override'], '' ); ?>>
										<?php
										/* translators: %s: detected engine name. */
										echo esc_html( sprintf( __( 'Detected: %s', 'banzaiplay' ), Game_Manager::engine_label( '' !== $record['detected_engine'] ? $record['detected_engine'] : 'generic' ) ) );
										?>
									</option>
									<?php foreach ( Game_Manager::ENGINES as $engine ) : ?>
										<option value="<?php echo esc_attr( $engine ); ?>" <?php selected( $record['engine_override'], $engine ); ?>><?php echo esc_html( Game_Manager::engine_label( $engine ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Change this only if detection got it wrong. Unity and Godot are started by BanzaiPlay with a real progress bar; other engines play from their own page.', 'banzaiplay' ); ?></p>
							</div>

							<?php if ( $record['entries'] ) : ?>
								<div class="bzpl-field">
									<label for="bzpl-entry"><?php echo esc_html( isset( $entry_labels[ $mode ] ) ? $entry_labels[ $mode ] : __( 'Entry', 'banzaiplay' ) ); ?></label>
									<select id="bzpl-entry" name="entry_override">
										<option value="" <?php selected( $record['entry_override'], '' ); ?>>
											<?php
											/* translators: %s: file path. */
											echo esc_html( sprintf( __( 'Automatic (%s)', 'banzaiplay' ), '' !== $record['entry'] && '' === $record['entry_override'] ? $record['entry'] : $record['entries'][0] ) );
											?>
										</option>
										<?php foreach ( $record['entries'] as $candidate ) : ?>
											<option value="<?php echo esc_attr( $candidate ); ?>" <?php selected( $record['entry_override'], $candidate ); ?>><?php echo esc_html( $candidate ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php endif; ?>
						</div>

						<?php if ( 'unity' === $mode && empty( $record['engine_data']['legacy'] ) ) : ?>
							<dl class="bzpl-facts bzpl-facts-inline">
								<?php
								foreach (
									array(
										'framework' => __( 'Framework', 'banzaiplay' ),
										'data'      => __( 'Data', 'banzaiplay' ),
										'code'      => __( 'Code', 'banzaiplay' ),
									) as $part => $label
								) :
									?>
									<dt><?php echo esc_html( $label ); ?></dt>
									<dd><code><?php echo esc_html( $record['engine_data']['files'][ $part ] ); ?></code></dd>
								<?php endforeach; ?>
							</dl>
						<?php elseif ( 'godot' === $mode ) : ?>
							<dl class="bzpl-facts bzpl-facts-inline">
								<dt><?php esc_html_e( 'Pack', 'banzaiplay' ); ?></dt>
								<dd><code><?php echo esc_html( $record['engine_data']['dir'] . $record['engine_data']['pack'] ); ?></code> · <?php echo esc_html( size_format( $record['engine_data']['sizes']['pck'], 1 ) ); ?></dd>
								<dt><?php esc_html_e( 'Engine', 'banzaiplay' ); ?></dt>
								<dd><code><?php echo esc_html( $record['engine_data']['dir'] . $record['engine_data']['executable'] . '.wasm' ); ?></code> · <?php echo esc_html( size_format( $record['engine_data']['sizes']['wasm'], 1 ) ); ?></dd>
							</dl>
						<?php elseif ( 'html' === $mode && '' !== $record['base'] ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: 1: base path, 2: number of references. */
									esc_html__( 'Built for the path %1$s; %2$s references were relocated on upload. Files it asks for at that path while running are redirected to the game\'s folder.', 'banzaiplay' ),
									'<code>' . esc_html( $record['base'] ) . '</code>',
									esc_html( number_format_i18n( $record['relocated'] ) )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>

			<section class="bzpl-card">
				<header class="bzpl-card-header">
					<h2><span class="bzpl-card-icon dashicons dashicons-desktop" aria-hidden="true"></span><?php esc_html_e( 'Display & input', 'banzaiplay' ); ?></h2>
				</header>
				<div class="bzpl-card-body">
					<fieldset class="bzpl-field">
						<legend><?php esc_html_e( 'Start', 'banzaiplay' ); ?></legend>
						<div class="bzpl-choice-grid">
							<label class="bzpl-choice">
								<input type="radio" name="start" value="click" <?php checked( ! Game_Manager::starts_on_load( $record ) ); ?>>
								<span class="bzpl-choice-tile">
									<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
									<span class="bzpl-choice-name"><?php esc_html_e( 'When the visitor presses Play', 'banzaiplay' ); ?></span>
									<span class="bzpl-choice-hint"><?php esc_html_e( 'Recommended. The page shows the game\'s title and a Play button; nothing downloads until it is pressed, and the click lets the game start with sound.', 'banzaiplay' ); ?></span>
								</span>
							</label>
							<label class="bzpl-choice">
								<input type="radio" name="start" value="load" <?php checked( Game_Manager::starts_on_load( $record ) ); ?>>
								<span class="bzpl-choice-tile">
									<span class="dashicons dashicons-update" aria-hidden="true"></span>
									<span class="bzpl-choice-name"><?php esc_html_e( 'As soon as the page loads', 'banzaiplay' ); ?></span>
									<span class="bzpl-choice-hint"><?php esc_html_e( 'The game loads with the page, for every visitor. Browsers keep its sound off until the visitor clicks the game, and it only gets the keyboard once clicked.', 'banzaiplay' ); ?></span>
								</span>
							</label>
						</div>
						<p class="description"><?php esc_html_e( 'A page can choose differently for itself: the shortcode\'s autoplay="true" or "false", or the block\'s Start setting.', 'banzaiplay' ); ?></p>
					</fieldset>

					<div class="bzpl-field">
						<span class="bzpl-label" id="bzpl-size-label"><?php esc_html_e( 'Native resolution', 'banzaiplay' ); ?> <span class="bzpl-optional"><?php esc_html_e( 'Optional', 'banzaiplay' ); ?></span></span>
						<div class="bzpl-size" role="group" aria-labelledby="bzpl-size-label">
							<input type="number" name="width" class="small-text" min="100" max="8192" step="1" value="<?php echo $record['width'] ? esc_attr( $record['width'] ) : ''; ?>" placeholder="<?php echo esc_attr( $detected_w ); ?>" aria-label="<?php esc_attr_e( 'Width in pixels', 'banzaiplay' ); ?>">
							<span aria-hidden="true">×</span>
							<input type="number" name="height" class="small-text" min="100" max="8192" step="1" value="<?php echo $record['height'] ? esc_attr( $record['height'] ) : ''; ?>" placeholder="<?php echo esc_attr( $detected_h ); ?>" aria-label="<?php esc_attr_e( 'Height in pixels', 'banzaiplay' ); ?>">
							<span><?php esc_html_e( 'px', 'banzaiplay' ); ?></span>
						</div>
						<p class="description">
							<?php
							if ( $record['detected_width'] ) {
								/* translators: 1: width, 2: height. */
								echo esc_html( sprintf( __( 'Leave empty to use %1$d × %2$d, found in the build.', 'banzaiplay' ), $record['detected_width'], $record['detected_height'] ) );
							} else {
								/* translators: 1: width, 2: height. */
								echo esc_html( sprintf( __( 'Leave empty to use %1$d × %2$d.', 'banzaiplay' ), Game_Manager::DEFAULT_WIDTH, Game_Manager::DEFAULT_HEIGHT ) );
							}
							?>
							<?php esc_html_e( 'The player keeps this aspect ratio, shrinks to fit smaller screens, and never grows wider than this — except in fullscreen.', 'banzaiplay' ); ?>
						</p>
					</div>

					<fieldset class="bzpl-field">
						<legend><?php esc_html_e( 'Fitting the game to the player', 'banzaiplay' ); ?></legend>
						<div class="bzpl-choice-grid">
							<label class="bzpl-choice">
								<input type="radio" name="fit" value="resize" <?php checked( 'scale' !== $record['fit'] ); ?>>
								<span class="bzpl-choice-tile">
									<span class="dashicons dashicons-image-flip-horizontal" aria-hidden="true"></span>
									<span class="bzpl-choice-name"><?php esc_html_e( 'Resize the game', 'banzaiplay' ); ?></span>
									<span class="bzpl-choice-hint"><?php esc_html_e( 'The game gets the player\'s size and adapts, as Unity, Godot and responsive Phaser games do.', 'banzaiplay' ); ?></span>
								</span>
							</label>
							<label class="bzpl-choice">
								<input type="radio" name="fit" value="scale" <?php checked( 'scale' === $record['fit'] ); ?>>
								<span class="bzpl-choice-tile">
									<span class="dashicons dashicons-editor-expand" aria-hidden="true"></span>
									<span class="bzpl-choice-name"><?php esc_html_e( 'Scale a fixed-size game', 'banzaiplay' ); ?></span>
									<span class="bzpl-choice-hint"><?php esc_html_e( 'The game always runs at its native resolution and is scaled to fit. For games with a fixed-size canvas that get cut off.', 'banzaiplay' ); ?></span>
								</span>
							</label>
						</div>
					</fieldset>

					<div class="bzpl-field">
						<label for="bzpl-release"><?php esc_html_e( 'Giving the keyboard back to the page', 'banzaiplay' ); ?></label>
						<select id="bzpl-release" name="release_key">
							<option value="escape" <?php selected( Game_Manager::release_key( $record ), 'escape' ); ?>><?php esc_html_e( 'Esc, or clicking outside the game', 'banzaiplay' ); ?></option>
							<option value="shift_escape" <?php selected( Game_Manager::release_key( $record ), 'shift_escape' ); ?>><?php esc_html_e( 'Shift+Esc, or clicking outside (the game uses Esc)', 'banzaiplay' ); ?></option>
							<option value="none" <?php selected( Game_Manager::release_key( $record ), 'none' ); ?>><?php esc_html_e( 'Only by clicking outside the game', 'banzaiplay' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'While a visitor plays, every key — arrows, Space, Tab — goes to the game and none to the page. A hint under the game says how to get out; keyboard-only visitors need a key for it.', 'banzaiplay' ); ?></p>
					</div>

					<div class="bzpl-field">
						<label class="bzpl-checkbox">
							<input type="checkbox" name="desktop_only" value="1" <?php checked( ! empty( $record['desktop_only'] ) ); ?>>
							<?php esc_html_e( 'Recommend a desktop computer on phones and tablets', 'banzaiplay' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Touch-screen visitors see "best experienced on a desktop computer" and can still choose to play — for games that need a keyboard or are too heavy for mobile.', 'banzaiplay' ); ?></p>
					</div>
				</div>
			</section>

			<?php if ( ! $is_new ) : ?>
				<?php
				/**
				 * Print cards below Display & input: Loading screen, Gallery,
				 * Data Bridge and Game events. Each reads its own fields in
				 * `bzpl/save_game`.
				 *
				 * @param array $record The game being edited.
				 */
				do_action( 'bzpl/edit_cards', $record );
				?>
			<?php endif; ?>
		</div>

		<div class="bzpl-side">
			<section class="bzpl-card bzpl-publish">
				<header class="bzpl-card-header">
					<h2><span class="bzpl-card-icon dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e( 'Status', 'banzaiplay' ); ?></h2>
				</header>
				<div class="bzpl-card-body">
					<label class="bzpl-switch-field">
						<input type="checkbox" name="active" value="1" class="bzpl-switch-input" <?php checked( $active ); ?>>
						<span class="bzpl-switch" aria-hidden="true"></span>
						<span class="bzpl-switch-text"><?php esc_html_e( 'Active', 'banzaiplay' ); ?></span>
					</label>
					<p class="description"><?php esc_html_e( 'Inactive games show nothing to visitors. Editors see a notice where they are embedded.', 'banzaiplay' ); ?></p>

					<?php if ( ! $is_new ) : ?>
						<dl class="bzpl-facts">
							<dt><?php esc_html_e( 'Build', 'banzaiplay' ); ?></dt>
							<dd><span class="bzpl-status bzpl-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( Game_Manager::status_label( $status ) ); ?></span></dd>
							<?php if ( $has_build ) : ?>
								<dt><?php esc_html_e( 'Size', 'banzaiplay' ); ?></dt>
								<?php /* translators: 1: size, 2: number of files. */ ?>
								<dd><?php echo esc_html( sprintf( __( '%1$s in %2$s files', 'banzaiplay' ), size_format( $record['size'], 1 ), number_format_i18n( $record['files'] ) ) ); ?></dd>
								<dt><?php esc_html_e( 'Uploaded', 'banzaiplay' ); ?></dt>
								<dd><?php echo esc_html( wp_date( $datetime, $record['uploaded'] ) ); ?></dd>
								<dt><?php esc_html_e( 'Player', 'banzaiplay' ); ?></dt>
								<dd><?php echo esc_html( sprintf( '%d × %d', $width, $height ) ); ?></dd>
							<?php endif; ?>
							<?php if ( Game_Manager::modified( $record ) ) : ?>
								<dt><?php esc_html_e( 'Last saved', 'banzaiplay' ); ?></dt>
								<dd><?php echo esc_html( wp_date( $datetime, Game_Manager::modified( $record ) ) ); ?></dd>
							<?php endif; ?>
						</dl>
					<?php endif; ?>
				</div>
				<footer class="bzpl-card-footer">
					<?php if ( ! $is_new ) : ?>
						<a class="submitdelete bzpl-delete" href="<?php echo esc_url( Admin::delete_url( $record['slug'] ) ); ?>" data-name="<?php echo esc_attr( $record['name'] ); ?>"><?php esc_html_e( 'Delete game', 'banzaiplay' ); ?></a>
					<?php endif; ?>
					<button type="submit" class="button button-primary button-large"><?php echo $is_new ? esc_html__( 'Create Game', 'banzaiplay' ) : esc_html__( 'Save Game', 'banzaiplay' ); ?></button>
				</footer>
			</section>

			<?php if ( ! $is_new ) : ?>
				<section class="bzpl-card">
					<header class="bzpl-card-header">
						<h2><span class="bzpl-card-icon dashicons dashicons-shortcode" aria-hidden="true"></span><?php esc_html_e( 'Embed', 'banzaiplay' ); ?></h2>
					</header>
					<div class="bzpl-card-body">
						<p class="bzpl-label"><?php esc_html_e( 'Shortcode', 'banzaiplay' ); ?></p>
						<span class="bzpl-chip bzpl-chip-wide">
							<code class="bzpl-shortcode"><?php echo esc_html( Game_Manager::shortcode( $record ) ); ?></code>
							<button type="button" class="bzpl-copy bzpl-icon-button" data-copy="<?php echo esc_attr( Game_Manager::shortcode( $record ) ); ?>" aria-label="<?php esc_attr_e( 'Copy shortcode', 'banzaiplay' ); ?>" title="<?php esc_attr_e( 'Copy shortcode', 'banzaiplay' ); ?>">
								<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
							</button>
						</span>
						<p class="description"><?php esc_html_e( 'Or add the "BanzaiPlay Game" block in the block editor.', 'banzaiplay' ); ?></p>
						<details class="bzpl-tip">
							<summary><?php esc_html_e( 'Shortcode options', 'banzaiplay' ); ?></summary>
							<dl class="bzpl-attrs">
								<dt><code>width</code>, <code>height</code></dt>
								<dd><?php esc_html_e( 'Size of this player in pixels. Give one and the other follows the game\'s aspect ratio.', 'banzaiplay' ); ?></dd>
								<dt><code>autoplay="true"</code>, <code>autoplay="false"</code></dt>
								<dd><?php esc_html_e( 'Start with the page, or wait for Play, on this page only. Leave it out to use the game\'s Start setting.', 'banzaiplay' ); ?></dd>
								<dt><code>fullscreen="false"</code></dt>
								<dd><?php esc_html_e( 'Hide the fullscreen button.', 'banzaiplay' ); ?></dd>
								<dt><code>class</code></dt>
								<dd><?php esc_html_e( 'Extra CSS classes for the player.', 'banzaiplay' ); ?></dd>
							</dl>
						</details>
					</div>
				</section>
			<?php endif; ?>
		</div>
	</div>
</form>
