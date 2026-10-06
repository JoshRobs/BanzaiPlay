<?php
/**
 * Block.
 *
 * @package BanzaiPlay
 */

namespace BanzaiPlay;

defined( 'ABSPATH' ) || exit;

/**
 * The BanzaiPlay block: a dynamic block whose front end is the shortcode's
 * output, and whose editor view is a placeholder card with a game picker.
 *
 * There is no build step, so the editor script is plain JavaScript against
 * the wp.* globals and its handle is registered here rather than via a
 * `file:` path in block.json (which needs a generated .asset.php).
 */
final class Block {

	const NAME = 'banzaiplay/game';

	const EDITOR_HANDLE = 'banzaiplay-block-editor';

	/**
	 * @var Embed
	 */
	private $embed;

	/**
	 * @var Game_Manager
	 */
	private $games;

	/**
	 * Constructor.
	 *
	 * @param Embed        $embed Renderer.
	 * @param Game_Manager $games Game store.
	 */
	public function __construct( Embed $embed, Game_Manager $games ) {
		$this->embed = $embed;
		$this->games = $games;
	}

	/**
	 * Hook in.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	/**
	 * Register the editor assets and the block.
	 */
	public function add() {
		wp_register_script(
			self::EDITOR_HANDLE,
			BZPL_PLUGIN_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
			BZPL_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_HANDLE, 'banzaiplay' );

		wp_register_style(
			self::EDITOR_HANDLE,
			BZPL_PLUGIN_URL . 'assets/css/block-editor.css',
			array(),
			BZPL_VERSION
		);

		register_block_type(
			BZPL_PLUGIN_PATH . 'blocks/game',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Give the editor the list of games to pick from.
	 */
	public function editor_data() {
		$games = array();

		foreach ( $this->games->all() as $game ) {
			$status                 = Game_Manager::status( $game );
			list( $width, $height ) = Game_Manager::dimensions( $game );

			$games[] = array(
				'slug'         => $game['slug'],
				'name'         => $game['name'],
				'description'  => $game['description'],
				'engine'       => $game['engine'],
				'engineLabel'  => Game_Manager::engine_label( $game['engine'] ),
				'width'        => $width,
				'height'       => $height,
				'status'       => $status,
				'statusLabel'  => Game_Manager::status_label( $status ),
				'active'       => Game_Manager::is_active( $game ),
				'startsOnLoad' => Game_Manager::starts_on_load( $game ),
			);
		}

		$data = array(
			'games'    => $games,
			'adminUrl' => Admin::can_manage() ? Admin::list_url() : '',
		);

		wp_add_inline_script( self::EDITOR_HANDLE, 'window.banzaiPlayBlockData=' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Front-end output.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$start      = isset( $attributes['start'] ) ? (string) $attributes['start'] : '';
		$args       = array(
			'width'      => isset( $attributes['width'] ) ? absint( $attributes['width'] ) : 0,
			'height'     => isset( $attributes['height'] ) ? absint( $attributes['height'] ) : 0,
			'class'      => isset( $attributes['className'] ) ? $attributes['className'] : '',
			'fullscreen' => ! isset( $attributes['fullscreen'] ) || (bool) $attributes['fullscreen'],
		);

		// '' leaves it to the game's own Start setting.
		if ( in_array( $start, Game_Manager::STARTS, true ) ) {
			$args['autoplay'] = 'load' === $start;
		}

		return $this->embed->render( isset( $attributes['game'] ) ? $attributes['game'] : '', $args );
	}
}
