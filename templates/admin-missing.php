<?php
/**
 * Edit screen for a game that does not exist.
 *
 * @package BanzaiPlay
 */

use BanzaiPlay\Admin;

defined( 'ABSPATH' ) || exit;
?>
<hr class="wp-header-end">
<div class="bzpl-empty">
	<h1><?php esc_html_e( 'Game not found', 'banzaiplay' ); ?></h1>
	<p><?php esc_html_e( 'It may have been deleted.', 'banzaiplay' ); ?></p>
	<a class="button" href="<?php echo esc_url( Admin::list_url() ); ?>"><?php esc_html_e( 'Back to all games', 'banzaiplay' ); ?></a>
</div>
