<?php
/**
 * Brand bar and engine tabs, above every BanzaiPlay screen.
 *
 * @package BanzaiPlay
 *
 * @var string $tab    Highlighted tab: 'all', an engine, 'new' or ''.
 * @var int[]  $counts Games per engine, plus 'all'.
 */

use BanzaiPlay\Admin;
use BanzaiPlay\Game_Manager;

defined( 'ABSPATH' ) || exit;

// An engine gets a tab once there is a game made with it (or it is the one
// being looked at), so a site with three Unity games sees "All · Unity".
$tabs = array( 'all' => __( 'All Games', 'banzaiplay' ) );

foreach ( Game_Manager::ENGINES as $engine ) {
	if ( $counts[ $engine ] || $tab === $engine ) {
		$tabs[ $engine ] = Game_Manager::engine_label( $engine );
	}
}
?>
<div class="bzpl-header">
	<a class="bzpl-brand" href="<?php echo esc_url( Admin::list_url() ); ?>">
		<?php echo Admin::logo( 36 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
		<span class="bzpl-brand-name">Banzai<span>Play</span></span>
	</a>
	<div class="bzpl-header-actions">
		<?php
		/**
		 * Print controls at the right of the brand bar.
		 */
		do_action( 'bzpl/header_actions' );
		?>
		<span class="bzpl-version">v<?php echo esc_html( BZPL_VERSION ); ?></span>
	</div>
</div>

<nav class="bzpl-nav" aria-label="<?php esc_attr_e( 'BanzaiPlay games', 'banzaiplay' ); ?>">
	<div class="bzpl-nav-tabs">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a
				class="bzpl-nav-tab<?php echo $tab === $key ? ' is-current' : ''; ?>"
				href="<?php echo esc_url( Admin::list_url( 'all' === $key ? array() : array( 'engine' => $key ) ) ); ?>"
				<?php echo $tab === $key ? 'aria-current="page"' : ''; ?>
			>
				<?php echo esc_html( $label ); ?>
				<span class="bzpl-nav-count"><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
	<a
		class="button button-primary bzpl-nav-add"
		href="<?php echo esc_url( Admin::new_url() ); ?>"
		<?php echo 'new' === $tab ? 'aria-current="page"' : ''; ?>
	>
		<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
		<?php esc_html_e( 'Add New Game', 'banzaiplay' ); ?>
	</a>
</nav>
