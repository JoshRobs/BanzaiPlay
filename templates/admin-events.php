<?php
/**
 * Game events card on the edit screen.
 *
 * Rendered by Game_Events::render_card(), inside the edit form.
 *
 * @package BanzaiPlay
 *
 * @var array $game    Record.
 * @var bool  $results Whether "complete" shows the results screen.
 */

use BanzaiPlay\Analytics;

defined( 'ABSPATH' ) || exit;

$js = <<<'JS'
// From the game, inside its frame:
BanzaiPlay.emit('score', { score: 1500 });
BanzaiPlay.emit('complete', { score: 1500, time: 120 });
BanzaiPlay.emit('level', { number: 3 });   // any name of your own

// Unity: in a .jslib plugin
mergeInto(LibraryManager.library, {
  BanzaiComplete: function (score) {
    window.BanzaiPlay.emit('complete', { score: score });
  },
});

// Godot 4: JavaScriptBridge.eval("BanzaiPlay.emit('complete', {score: %d})" % score)
JS;

$page = <<<'JS'
// On the page around the game:
document.addEventListener('banzaiplay:event', (e) => {
  console.log(e.detail.game, e.detail.name, e.detail.data);
});
JS;

$php = <<<'PHP'
// In a plugin or functions.php:
add_action( 'bzpl/game_event/complete', function ( $data, $context ) {
	if ( $context['user_id'] ) {
		update_user_meta( $context['user_id'], 'finished_' . $context['game']['slug'], time() );
	}
}, 10, 2 );
PHP;
?>
<section class="bzpl-card bzpl-events-card">
	<header class="bzpl-card-header">
		<h2><span class="bzpl-card-icon dashicons dashicons-megaphone" aria-hidden="true"></span><?php esc_html_e( 'Game events', 'banzaiplay' ); ?></h2>
	</header>
	<div class="bzpl-card-body">
	<fieldset class="bzpl-fieldset">
		<input type="hidden" name="bzpl_events" value="1">
		<p class="description"><?php esc_html_e( 'Your game can tell WordPress what happens in it with BanzaiPlay.emit(). "complete" and "score" count towards Analytics; every event reaches your site\'s scripts and PHP hooks.', 'banzaiplay' ); ?>
			<a href="<?php echo esc_url( Analytics::url( $game['slug'] ) ); ?>"><?php esc_html_e( 'This game\'s plays', 'banzaiplay' ); ?></a>
		</p>

		<div class="bzpl-field">
			<label class="bzpl-checkbox">
				<input type="checkbox" name="events_results" value="1" <?php checked( $results ); ?>>
				<?php esc_html_e( 'Show a results screen when the game sends "complete"', 'banzaiplay' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'The score and time from the event, or the last "score" and the time played, over the game, with Play again. Leave it off if the game shows its own.', 'banzaiplay' ); ?></p>
		</div>

		<details class="bzpl-tip">
			<summary><?php esc_html_e( 'Sending events from your game', 'banzaiplay' ); ?></summary>
			<pre class="bzpl-code"><?php echo esc_html( $js ); ?></pre>
			<p class="description"><?php esc_html_e( 'BanzaiPlay.emit() is always defined, so a game that calls it never throws, even where nothing listens.', 'banzaiplay' ); ?></p>
		</details>

		<details class="bzpl-tip">
			<summary><?php esc_html_e( 'Reacting to events on your site', 'banzaiplay' ); ?></summary>
			<pre class="bzpl-code"><?php echo esc_html( $page ); ?></pre>
			<pre class="bzpl-code"><?php echo esc_html( $php ); ?></pre>
			<p class="description"><?php esc_html_e( 'bzpl/game_event fires for every event, with the name first; bzpl/game_event/{name} for one. Events come from the visitor\'s browser, so anyone can send one: don\'t give away anything of value on an event alone.', 'banzaiplay' ); ?></p>
		</details>
	</fieldset>
	</div>
</section>
