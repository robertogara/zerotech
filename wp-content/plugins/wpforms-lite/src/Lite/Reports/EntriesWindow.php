<?php

namespace WPForms\Lite\Reports;

/**
 * Rolling window of daily form submission counts for Lite.
 *
 * Lite does not store entries, so there is no table to query for a period-scoped
 * submission count. Instead, every submission bumps a per-day counter kept in a
 * single non-autoloaded option, trimmed to the last MAX_DAYS days.
 *
 * @since 2.0.2.2
 */
class EntriesWindow {

	/**
	 * Option holding the rolling window.
	 *
	 * @since 2.0.2.2
	 */
	public const OPTION = 'wpforms_entries_count_daily';

	/**
	 * Number of days kept in the window.
	 *
	 * @since 2.0.2.2
	 */
	private const MAX_DAYS = 30;

	/**
	 * Record a single submission in today's UTC bucket and prune expired buckets.
	 *
	 * Must be called before the lifetime `wpforms_entries_count` post meta is incremented:
	 * a first-ever window is seeded in create() from the pre-submission lifetime count.
	 *
	 * @since 2.0.2.2
	 */
	public function record(): void {

		$data  = $this->get_window();
		$today = gmdate( 'Y-m-d' );

		$data['days'][ $today ] = ( $data['days'][ $today ] ?? 0 ) + 1;

		$oldest = gmdate( 'Y-m-d', strtotime( '-' . self::MAX_DAYS . ' days' ) );

		foreach ( array_keys( $data['days'] ) as $day ) {
			if ( $day < $oldest ) {
				unset( $data['days'][ $day ] );
			}
		}

		// Non-atomic read-modify-write: concurrent submissions may lose an increment, which is acceptable for telemetry.
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Total number of submissions recorded over the last given number of days.
	 *
	 * Returns null while the window is too young to cover the requested period, so that
	 * the caller can omit the metric instead of reporting a misleading partial count.
	 *
	 * Reads the window, which also creates it, so a site that has never received a
	 * submission becomes measurable starting from its first usage tracking check-in.
	 *
	 * @since 2.0.2.2
	 *
	 * @param int $days Number of days to sum.
	 *
	 * @return int|null Submissions count, or null when the window is not mature yet.
	 */
	public function get_total( int $days ): ?int {

		$window = $this->get_window();

		if ( ! $this->is_mature( $window, $days ) ) {
			return null;
		}

		$from  = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$total = 0;

		foreach ( $window['days'] as $day => $count ) {
			if ( $day >= $from ) {
				$total += $count;
			}
		}

		return $total;
	}

	/**
	 * Whether the window has been collecting data for at least the given number of days.
	 *
	 * @since 2.0.2.2
	 *
	 * @param array $window Window as returned by get_window().
	 * @param int   $days   Number of days the period spans.
	 *
	 * @return bool
	 */
	private function is_mature( array $window, int $days ): bool {

		return time() - $window['since'] >= $days * DAY_IN_SECONDS;
	}

	/**
	 * Get the stored window in a guaranteed shape, creating it when it is missing.
	 *
	 * @since 2.0.2.2
	 *
	 * @return array Window as `[ 'since' => <unix ts>, 'days' => [ 'YYYY-MM-DD' => <int> ] ]`.
	 */
	private function get_window(): array {

		$data = get_option( self::OPTION );

		// A missing or corrupted option starts a fresh window.
		if ( ! is_array( $data ) || empty( $data['since'] ) ) {
			$data = $this->create();

			update_option( self::OPTION, $data, false );

			return $data;
		}

		$stored = isset( $data['days'] ) && is_array( $data['days'] ) ? $data['days'] : [];

		$data['since'] = (int) $data['since'];
		$data['days']  = [];

		foreach ( $stored as $day => $count ) {
			if ( is_numeric( $count ) ) {
				$data['days'][ $day ] = (int) $count;
			}
		}

		return $data;
	}

	/**
	 * Build a new empty window.
	 *
	 * A site without lifetime entries has received nothing since activation, so the
	 * activation date is a truthful window start; otherwise the window starts now.
	 *
	 * @since 2.0.2.2
	 *
	 * @return array
	 */
	private function create(): array {

		$since = time();

		if ( self::get_lifetime_total() === 0 ) {
			$activated = (array) get_option( 'wpforms_activated', [] );
			$lite      = isset( $activated['lite'] ) ? (int) $activated['lite'] : 0;

			if ( $lite > 0 ) {
				$since = $lite;
			}
		}

		return [
			'since' => $since,
			'days'  => [],
		];
	}

	/**
	 * Lifetime number of submissions, summed from the per-form `wpforms_entries_count` post meta.
	 *
	 * This is the only count Lite keeps outside of this window, so it is shared with the
	 * usage tracking payload rather than queried again there.
	 *
	 * @since 2.0.2.2
	 *
	 * @return int
	 */
	public static function get_lifetime_total(): int {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sum = $wpdb->get_var(
			"SELECT SUM(meta_value)
				FROM $wpdb->postmeta
				WHERE meta_key = 'wpforms_entries_count';"
		);

		return (int) $sum;
	}
}
