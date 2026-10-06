<?php
/**
 * Shortcode.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * [banzai-play game="my-game" width="" height="" class="" autoplay="" fullscreen="true"]
 *
 * `autoplay` left out (or empty) uses the game's own Start setting;
 * "true" or "false" overrides it on this page.
 */
final class Shortcode {

	const TAG = 'banzai-play';

	/**
	 * @var Embed
	 */
	private $embed;

	/**
	 * Constructor.
	 *
	 * @param Embed $embed Renderer.
	 */
	public function __construct( Embed $embed ) {
		$this->embed = $embed;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add' ) );
	}

	/**
	 * Register the shortcode.
	 */
	public function add() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render it.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'game'       => '',
				'width'      => '',
				'height'     => '',
				'class'      => '',
				'autoplay'   => '',
				'fullscreen' => 'true',
			),
			$atts,
			self::TAG
		);

		return $this->embed->render(
			$atts['game'],
			array(
				'width'      => absint( $atts['width'] ),
				'height'     => absint( $atts['height'] ),
				'class'      => $atts['class'],
				'autoplay'   => self::bool( $atts['autoplay'], null ),
				'fullscreen' => self::bool( $atts['fullscreen'], true ),
			)
		);
	}

	/**
	 * Read a yes/no attribute: true/false, 1/0, yes/no, on/off.
	 *
	 * @param string    $value    Attribute value.
	 * @param bool|null $fallback Value when it is none of those.
	 * @return bool|null
	 */
	private static function bool( $value, $fallback ) {
		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, array( 'true', '1', 'yes', 'on' ), true ) ) {
			return true;
		}

		if ( in_array( $value, array( 'false', '0', 'no', 'off' ), true ) ) {
			return false;
		}

		return $fallback;
	}
}
