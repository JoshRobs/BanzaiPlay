<?php
/**
 * BanzaiPlay → Analytics.
 *
 * @package BanzaiPlay
 *
 * @var int               $days      Period in days; 0 is all time.
 * @var string            $slug      One game, or ''.
 * @var array[]           $games     Every game, by slug.
 * @var array             $totals    Sums over the games shown.
 * @var array[]           $table     slug => { game, stats|null }, most played first.
 * @var array<string,int> $daily     Y-m-d => plays, for the chart.
 * @var bool              $recording Whether plays are recorded.
 */

use BanzaiPlay\Admin;
use BanzaiPlay\Analytics;
use BanzaiPlay\Settings;

defined( 'ABSPATH' ) || exit;

$periods = array(
	7   => __( '7 days', 'banzaiplay' ),
	30  => __( '30 days', 'banzaiplay' ),
	90  => __( '90 days', 'banzaiplay' ),
	365 => __( '12 months', 'banzaiplay' ),
	0   => __( 'All time', 'banzaiplay' ),
);

$touch = $totals['tablet'] + $totals['mobile'];
$known = $totals['desktop'] + $touch;
$peak  = max( 1, max( $daily ) );
$bars  = count( $daily );

// One label per week or so along the bottom of the chart.
$every = max( 1, (int) ceil( $bars / 7 ) );
?>
<div class="bzpl-page-head">
	<div>
		<p class="bzpl-breadcrumb">
			<a href="<?php echo esc_url( Admin::list_url() ); ?>"><?php esc_html_e( 'All Games', 'banzaiplay' ); ?></a>
			<span aria-hidden="true">/</span>
			<span><?php esc_html_e( 'Analytics', 'banzaiplay' ); ?></span>
		</p>
		<h1><?php echo '' !== $slug ? esc_html( $games[ $slug ]['name'] ) : esc_html__( 'Analytics', 'banzaiplay' ); ?></h1>
	</div>
	<form method="get" class="bzpl-analytics-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( Analytics::PAGE ); ?>">
		<input type="hidden" name="days" value="<?php echo esc_attr( $days ); ?>">
		<label class="screen-reader-text" for="bzpl-analytics-game"><?php esc_html_e( 'Game', 'banzaiplay' ); ?></label>
		<select id="bzpl-analytics-game" name="game" onchange="this.form.submit()">
			<option value=""><?php esc_html_e( 'Every game', 'banzaiplay' ); ?></option>
			<?php foreach ( $games as $key => $game ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $slug, $key ); ?>><?php echo esc_html( $game['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'banzaiplay' ); ?></button></noscript>
	</form>
</div>
<hr class="wp-header-end">

<?php if ( ! $recording ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e( 'Plays are not being recorded. These are the statistics from before it was switched off.', 'banzaiplay' ); ?>
			<a href="<?php echo esc_url( Settings::url() ); ?>"><?php esc_html_e( 'Settings', 'banzaiplay' ); ?></a>
		</p>
	</div>
<?php endif; ?>

<nav class="bzpl-periods" aria-label="<?php esc_attr_e( 'Period', 'banzaiplay' ); ?>">
	<?php foreach ( $periods as $period => $label ) : ?>
		<a href="<?php echo esc_url( Analytics::url( $slug, $period ) ); ?>" class="bzpl-period<?php echo $period === $days ? ' is-current' : ''; ?>"<?php echo $period === $days ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
	<?php endforeach; ?>
</nav>

<div class="bzpl-stats">
	<div class="bzpl-stat">
		<span class="bzpl-stat-label"><?php esc_html_e( 'Plays', 'banzaiplay' ); ?></span>
		<span class="bzpl-stat-value"><?php echo esc_html( number_format_i18n( $totals['plays'] ) ); ?></span>
		<span class="bzpl-stat-note"><?php esc_html_e( 'Each time a game was started', 'banzaiplay' ); ?></span>
	</div>
	<div class="bzpl-stat">
		<span class="bzpl-stat-label"><?php esc_html_e( 'Average time played', 'banzaiplay' ); ?></span>
		<span class="bzpl-stat-value"><?php echo $totals['timed'] ? esc_html( Analytics::duration( $totals['seconds'] / $totals['timed'] ) ) : '—'; ?></span>
		<?php /* translators: %s: total time, such as 3:12:05. */ ?>
		<span class="bzpl-stat-note"><?php echo esc_html( sprintf( __( '%s in all, while the page was in view', 'banzaiplay' ), Analytics::duration( $totals['seconds'] ) ) ); ?></span>
	</div>
	<div class="bzpl-stat">
		<span class="bzpl-stat-label"><?php esc_html_e( 'Completion rate', 'banzaiplay' ); ?></span>
		<span class="bzpl-stat-value"><?php echo esc_html( Analytics::percent( $totals['completed'], $totals['finishable'] ) ); ?></span>
		<span class="bzpl-stat-note">
			<?php
			echo $totals['finishable']
				/* translators: %s: number of completed plays. */
				? esc_html( sprintf( _n( '%s play completed', '%s plays completed', $totals['completed'], 'banzaiplay' ), number_format_i18n( $totals['completed'] ) ) )
				: esc_html__( 'Games that send "complete" get one', 'banzaiplay' );
			?>
		</span>
	</div>
	<div class="bzpl-stat">
		<span class="bzpl-stat-label"><?php esc_html_e( 'On phones and tablets', 'banzaiplay' ); ?></span>
		<span class="bzpl-stat-value"><?php echo esc_html( Analytics::percent( $touch, $known ) ); ?></span>
		<span class="bzpl-stat-note">
			<?php
			/* translators: 1: desktop plays, 2: tablet plays, 3: phone plays. */
			echo esc_html( sprintf( __( '%1$s desktop · %2$s tablet · %3$s phone', 'banzaiplay' ), number_format_i18n( $totals['desktop'] ), number_format_i18n( $totals['tablet'] ), number_format_i18n( $totals['mobile'] ) ) );
			?>
		</span>
	</div>
</div>

<section class="bzpl-card bzpl-chart-card">
	<header class="bzpl-card-header">
		<h2><span class="bzpl-card-icon dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( 'Plays per day', 'banzaiplay' ); ?></h2>
		<?php if ( 0 === $days ) : ?>
			<span class="bzpl-method"><?php esc_html_e( 'Last 90 days', 'banzaiplay' ); ?></span>
		<?php endif; ?>
	</header>
	<div class="bzpl-card-body">
		<?php if ( array_sum( $daily ) ) : ?>
			<?php /* translators: %s: highest number of plays in a day. */ ?>
			<svg class="bzpl-chart" viewBox="0 0 <?php echo (int) ( $bars * 10 ); ?> 100" preserveAspectRatio="none" role="img" aria-label="<?php echo esc_attr( sprintf( __( 'Plays per day; the busiest day had %s', 'banzaiplay' ), number_format_i18n( $peak ) ) ); ?>">
				<?php
				$i = 0;
				foreach ( $daily as $day => $count ) :
					$height = $count ? max( 1.5, 96 * $count / $peak ) : 0;
					?>
					<rect x="<?php echo esc_attr( $i * 10 + 1.5 ); ?>" y="<?php echo esc_attr( 100 - $height ); ?>" width="7" height="<?php echo esc_attr( $height ); ?>" rx="1.5"><title><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $day . ' 12:00:00 UTC' ) ) . ': ' . number_format_i18n( $count ) ); ?></title></rect>
					<?php
					++$i;
				endforeach;
				?>
			</svg>
			<div class="bzpl-chart-axis" aria-hidden="true">
				<?php
				$i = 0;
				foreach ( array_keys( $daily ) as $day ) :
					if ( 0 === $i % $every ) :
						?>
						<span style="left:<?php echo esc_attr( round( 100 * ( $i + 0.5 ) / $bars, 2 ) ); ?>%"><?php echo esc_html( wp_date( 'M j', strtotime( $day . ' 12:00:00 UTC' ) ) ); ?></span>
						<?php
					endif;
					++$i;
				endforeach;
				?>
			</div>
			<?php /* translators: %s: number of plays. */ ?>
			<p class="bzpl-chart-peak"><?php echo esc_html( sprintf( __( 'Busiest day: %s plays', 'banzaiplay' ), number_format_i18n( $peak ) ) ); ?></p>
		<?php else : ?>
			<p class="bzpl-chart-empty"><?php esc_html_e( 'No plays in this period yet. Plays are counted when a visitor starts a game on your site — not in the preview on a game\'s settings screen.', 'banzaiplay' ); ?></p>
		<?php endif; ?>
	</div>
</section>

<table class="widefat bzpl-games bzpl-analytics-table">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Game', 'banzaiplay' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Plays', 'banzaiplay' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Average time', 'banzaiplay' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Completed', 'banzaiplay' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Best score', 'banzaiplay' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Devices', 'banzaiplay' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach ( $table as $key => $entry ) :
			$game  = $entry['game'];
			$stats = $entry['stats'];
			?>
			<tr>
				<td>
					<a class="row-title" href="<?php echo esc_url( Analytics::url( $key, $days ) ); ?>"><?php echo esc_html( $game['name'] ); ?></a>
					<div class="bzpl-subtle"><?php echo Admin::engine_badge( $game['engine'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?> · <a href="<?php echo esc_url( Admin::edit_url( $key ) ); ?>"><?php esc_html_e( 'Edit', 'banzaiplay' ); ?></a></div>
				</td>
				<?php if ( ! $stats ) : ?>
					<td class="num">0</td>
					<td class="num">—</td>
					<td class="num">—</td>
					<td class="num">—</td>
					<td>—</td>
				<?php else : ?>
					<td class="num"><?php echo esc_html( number_format_i18n( $stats['plays'] ) ); ?></td>
					<td class="num"><?php echo $stats['timed'] ? esc_html( Analytics::duration( $stats['seconds'] / $stats['timed'] ) ) : '—'; ?></td>
					<td class="num"><?php echo $stats['completed'] ? esc_html( Analytics::percent( $stats['completed'], $stats['plays'] ) ) : '—'; ?></td>
					<td class="num"><?php echo null === $stats['best'] ? '—' : esc_html( number_format_i18n( $stats['best'], floor( $stats['best'] ) === $stats['best'] ? 0 : 2 ) ); ?></td>
					<td>
						<?php
						$device_total = $stats['desktop'] + $stats['tablet'] + $stats['mobile'];
						if ( $device_total ) :
							?>
							<?php /* translators: 1: share of desktop plays, 2: share of phone and tablet plays. */ ?>
							<span class="bzpl-split" title="<?php echo esc_attr( sprintf( __( 'Desktop %1$s · phones and tablets %2$s', 'banzaiplay' ), Analytics::percent( $stats['desktop'], $device_total ), Analytics::percent( $stats['tablet'] + $stats['mobile'], $device_total ) ) ); ?>">
								<span class="bzpl-split-bar"><span style="width:<?php echo esc_attr( round( 100 * $stats['desktop'] / $device_total, 1 ) ); ?>%"></span></span>
								<?php echo esc_html( Analytics::percent( $stats['desktop'], $device_total ) ); ?> <?php esc_html_e( 'desktop', 'banzaiplay' ); ?>
							</span>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $table ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No games yet.', 'banzaiplay' ); ?></td></tr>
		<?php endif; ?>
	</tbody>
</table>
<p class="description bzpl-analytics-privacy"><?php esc_html_e( 'Recorded on your own site, anonymously: no IP addresses, cookies or user IDs — the game, the page, the time, the kind of device, how long it was played and how it ended.', 'banzaiplay' ); ?></p>
