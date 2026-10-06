<?php
/**
 * Custom loading screens (Pro).
 *
 * This file is not in the free build: Freemius leaves out files whose names
 * contain __premium_only, and Plugin::run() loads it inside an
 * is__premium_only() block, which Freemius strips too.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * The Play and loading screens in the site's own colours: a cover image
 * behind them, a logo above the title, and a colour for the Play button,
 * progress bar and focus glow — per game, falling back to the defaults on
 * the Settings screen. "Powered by BanzaiPlay" goes with a valid licence
 * (Embed checks bzpl_has_valid_license() itself).
 *
 * Stored on the game as `look`: { cover, backdrop, logo, color }. The cover
 * is also the game's picture in galleries.
 */
final class Branding {

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Site settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		add_filter( 'bzpl/player_style', array( $this, 'style' ), 10, 2 );
		add_filter( 'bzpl/player_classes', array( $this, 'classes' ), 10, 2 );
		add_filter( 'bzpl/player_logo', array( $this, 'logo' ), 10, 2 );
		add_action( 'bzpl/edit_cards', array( $this, 'render_card' ), 10 );
		add_filter( 'bzpl/save_game', array( $this, 'save' ) );
	}

	/**
	 * A game's look, with every key present.
	 *
	 * @param array $game Record.
	 * @return array{cover:int, backdrop:bool, logo:int, color:string}
	 */
	public static function look( array $game ) {
		$look = isset( $game['look'] ) && is_array( $game['look'] ) ? $game['look'] : array();

		return array_merge(
			array(
				'cover'    => 0,
				'backdrop' => true,
				'logo'     => 0,
				'color'    => '',
			),
			$look
		);
	}

	/**
	 * The game's cover image ID, if it still exists.
	 *
	 * @param array $game Record.
	 * @return int
	 */
	public static function cover( array $game ) {
		$cover = (int) self::look( $game )['cover'];

		return $cover && wp_attachment_is_image( $cover ) ? $cover : 0;
	}

	/**
	 * The colour in effect: the game's, else the site's, else ''.
	 *
	 * @param array $game Record.
	 * @return string
	 */
	private function color( array $game ) {
		$look = self::look( $game );

		return '' !== $look['color'] ? $look['color'] : (string) $this->settings->get( 'color' );
	}

	/**
	 * The logo in effect: the game's, else the site's, else 0.
	 *
	 * @param array $game Record.
	 * @return int
	 */
	private function logo_id( array $game ) {
		$look = self::look( $game );
		$logo = $look['logo'] ? (int) $look['logo'] : (int) $this->settings->get( 'logo' );

		return $logo && wp_attachment_is_image( $logo ) ? $logo : 0;
	}

	/**
	 * CSS custom properties: the accent, readable text on it, its glow, and
	 * the cover as a backdrop.
	 *
	 * @param array $vars Properties.
	 * @param array $game Record.
	 * @return array
	 */
	public function style( $vars, $game ) {
		$vars = array_merge( $vars, self::accent_vars( $this->color( $game ) ) );

		$cover = self::look( $game )['backdrop'] ? self::cover( $game ) : 0;
		$url   = $cover ? wp_get_attachment_image_url( $cover, 'large' ) : '';

		if ( $url ) {
			$vars['--banzaiplay-backdrop'] = 'url("' . esc_url_raw( $url ) . '")';
		}

		return $vars;
	}

	/**
	 * `has-backdrop` and `has-logo`, for the stylesheet.
	 *
	 * @param string[] $classes Classes.
	 * @param array    $game    Record.
	 * @return string[]
	 */
	public function classes( $classes, $game ) {
		if ( self::look( $game )['backdrop'] && self::cover( $game ) ) {
			$classes[] = 'has-backdrop';
		}

		if ( $this->logo_id( $game ) ) {
			$classes[] = 'has-logo';
		}

		return $classes;
	}

	/**
	 * The logo above the title.
	 *
	 * @param string $html Markup so far.
	 * @param array  $game Record.
	 * @return string
	 */
	public function logo( $html, $game ) {
		$logo = $this->logo_id( $game );

		if ( ! $logo ) {
			return $html;
		}

		return wp_get_attachment_image(
			$logo,
			'medium',
			false,
			array(
				'class'    => 'banzaiplay-logo',
				'alt'      => '',
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		);
	}

	/**
	 * The CSS custom properties for an accent colour: the colour, readable
	 * text on it, and its glow. None for ''.
	 *
	 * @param string $color #rrggbb, or ''.
	 * @return array<string,string>
	 */
	public static function accent_vars( $color ) {
		if ( '' === $color ) {
			return array();
		}

		list( $r, $g, $b ) = self::rgb( $color );

		return array(
			'--banzaiplay-accent'    => $color,
			'--banzaiplay-accent-2'  => $color,
			// Whichever contrasts more: black wins above a luminance of ~0.18,
			// where (L + 0.05) / 0.05 overtakes 1.05 / (L + 0.05).
			'--banzaiplay-on-accent' => self::luminance( $r, $g, $b ) > 0.179 ? '#111111' : '#ffffff',
			'--banzaiplay-glow'      => sprintf( 'rgba(%d, %d, %d, 0.45)', $r, $g, $b ),
		);
	}

	/**
	 * #rrggbb as [ r, g, b ].
	 *
	 * @param string $hex Colour.
	 * @return int[]
	 */
	private static function rgb( $hex ) {
		return array_map( 'hexdec', str_split( substr( $hex, 1 ), 2 ) );
	}

	/**
	 * Relative luminance, 0 (black) – 1 (white).
	 *
	 * @param int $r Red.
	 * @param int $g Green.
	 * @param int $b Blue.
	 * @return float
	 */
	private static function luminance( $r, $g, $b ) {
		$channel = static function ( $c ) {
			$c /= 255;

			return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		};

		return 0.2126 * $channel( $r ) + 0.7152 * $channel( $g ) + 0.0722 * $channel( $b );
	}

	/**
	 * An image picker: a hidden ID, a preview, and Choose / Remove buttons
	 * (admin-pro__premium_only.js opens the media library). Without
	 * JavaScript it shows the current image only.
	 *
	 * @param string $name     Input name.
	 * @param int    $id       Attachment ID, or 0.
	 * @param string $label    Visible label.
	 * @param bool   $disabled Whether the field is locked.
	 */
	public static function media_field( $name, $id, $label, $disabled = false ) {
		$id  = $id && wp_attachment_is_image( $id ) ? (int) $id : 0;
		$src = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		?>
		<div class="bzpl-media<?php echo $id ? ' has-image' : ''; ?>" data-bzpl-media data-title="<?php echo esc_attr( $label ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $id ? $id : '' ); ?>" class="bzpl-media-id">
			<span class="bzpl-media-preview">
				<img src="<?php echo esc_url( $src ? $src : '' ); ?>" alt=""<?php echo $src ? '' : ' hidden'; ?>>
				<span class="bzpl-media-empty dashicons dashicons-format-image" aria-hidden="true"<?php echo $src ? ' hidden' : ''; ?>></span>
			</span>
			<span class="bzpl-media-actions">
				<button type="button" class="button bzpl-media-choose" <?php disabled( $disabled ); ?>><?php echo $id ? esc_html__( 'Replace', 'banzaiplay' ) : esc_html__( 'Choose image', 'banzaiplay' ); ?></button>
				<button type="button" class="button-link bzpl-media-remove" <?php disabled( $disabled ); ?><?php echo $id ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'banzaiplay' ); ?></button>
			</span>
		</div>
		<?php
	}

	/**
	 * The Loading screen card.
	 *
	 * @param array $game Record.
	 */
	public function render_card( $game ) {
		$licensed  = bzpl_has_valid_license();
		$look      = self::look( $game );
		$site_logo = (int) $this->settings->get( 'logo' );
		$site_color = (string) $this->settings->get( 'color' );

		include BZPL_PLUGIN_PATH . 'templates/admin-branding__premium_only.php';
	}

	/**
	 * Read the card into the record. Runs inside Admin::handle_save(), after
	 * its capability and nonce checks.
	 *
	 * @param array $game Record.
	 * @return array
	 */
	public function save( $game ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in Admin::handle_save().
		// Not on the form (locked, or a new game), or no licence: keep what was saved.
		if ( ! isset( $_POST['bzpl_look'] ) || ! bzpl_has_valid_license() ) {
			return $game;
		}

		$game['look'] = array(
			'cover'    => Settings::image( isset( $_POST['look_cover'] ) ? wp_unslash( $_POST['look_cover'] ) : 0 ),
			'backdrop' => ! empty( $_POST['look_backdrop'] ),
			'logo'     => Settings::image( isset( $_POST['look_logo'] ) ? wp_unslash( $_POST['look_logo'] ) : 0 ),
			'color'    => Settings::color( isset( $_POST['look_color'] ) ? wp_unslash( $_POST['look_color'] ) : '' ),
		);
		// phpcs:enable

		return $game;
	}
}
