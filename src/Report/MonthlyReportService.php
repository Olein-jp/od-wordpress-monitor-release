<?php
/**
 * Read-only monthly summary of persisted monitoring history.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Report;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Olein\WordPressMonitor\Scheduler\CheckRetention;
use wpdb;

final class MonthlyReportService {
	private const HTTP_GAP_SECONDS = 15 * MINUTE_IN_SECONDS;
	private const PAGE_SIZE        = 500;

	public function __construct( private readonly wpdb $database ) {
	}

	/**
	 * @return array{month:string,from:DateTimeImmutable,to:DateTimeImmutable,checks:list<array{type:string,status:string,count:int}>,events:list<array{type:string,previous:string,current:string,count:int}>,coverage:string,check_count:int}
	 */
	public function build( int $site_id, string $month, ?DateTimeImmutable $now = null ): array {
		if ( $site_id < 1 || 1 !== preg_match( '/^(?:[1-9][0-9]{3})-(?:0[1-9]|1[0-2])$/', $month ) ) {
			throw new InvalidArgumentException( 'A valid site ID and month are required.' );
		}

		$timezone = wp_timezone();
		$from     = DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', $timezone );
		if ( false === $from || $from->format( 'Y-m' ) !== $month ) {
			throw new InvalidArgumentException( 'The report month is invalid.' );
		}
		$to           = $from->modify( 'first day of next month' );
		$from_utc     = $from->setTimezone( new DateTimeZone( 'UTC' ) );
		$to_utc       = $to->setTimezone( new DateTimeZone( 'UTC' ) );
		$start        = $from_utc->format( 'Y-m-d H:i:s' );
		$end          = $to_utc->format( 'Y-m-d H:i:s' );
		$checks_table = $this->database->prefix . 'odm_checks';
		$events_table = $this->database->prefix . 'odm_events';

		$checks_sql  = $this->database->prepare(
			"SELECT check_type, status, COUNT(*) AS total FROM {$checks_table} WHERE site_id = %d AND checked_at >= %s AND checked_at < %s GROUP BY check_type, status ORDER BY check_type, status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table name.
			$site_id,
			$start,
			$end
		);
		$events_sql  = $this->database->prepare(
			"SELECT event_type, previous_status, current_status, COUNT(*) AS total FROM {$events_table} WHERE site_id = %d AND occurred_at >= %s AND occurred_at < %s GROUP BY event_type, previous_status, current_status ORDER BY event_type, previous_status, current_status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table name.
			$site_id,
			$start,
			$end
		);
		$check_rows  = $this->database->get_results( $checks_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Report is read-only and scoped.
		$event_rows  = $this->database->get_results( $events_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Report is read-only and scoped.
		$checks      = array();
		$events      = array();
		$check_count = 0;
		foreach ( $check_rows as $row ) {
			$count        = (int) $row['total'];
			$checks[]     = array(
				'type'   => (string) $row['check_type'],
				'status' => (string) $row['status'],
				'count'  => $count,
			);
			$check_count += $count;
		}
		foreach ( $event_rows as $row ) {
			$events[] = array(
				'type'     => (string) $row['event_type'],
				'previous' => (string) $row['previous_status'],
				'current'  => (string) $row['current_status'],
				'count'    => (int) $row['total'],
			);
		}

		$now_utc            = ( $now ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		$retention_boundary = $now_utc->modify( '-' . CheckRetention::RETENTION_DAYS . ' days' );
		$complete           = $now_utc >= $to_utc && $from_utc >= $retention_boundary && ! $this->has_http_gap( $site_id, $start, $end, $from_utc, $to_utc );

		return array(
			'month'       => $month,
			'from'        => $from,
			'to'          => $to,
			'checks'      => $checks,
			'events'      => $events,
			'coverage'    => $complete ? 'recorded' : 'partial',
			'check_count' => $check_count,
		);
	}

	/**
	 * Stream HTTP timestamps in bounded keyset pages to detect gaps without loading a month of rows.
	 */
	private function has_http_gap( int $site_id, string $start, string $end, DateTimeImmutable $from, DateTimeImmutable $to ): bool {
		$table       = $this->database->prefix . 'odm_checks';
		$cursor_time = $start;
		$cursor_id   = 0;
		$previous    = null;
		$first       = true;
		do {
			$sql       = $this->database->prepare(
				"SELECT id, checked_at FROM {$table} WHERE site_id = %d AND check_type = %s AND checked_at >= %s AND checked_at < %s AND (checked_at > %s OR (checked_at = %s AND id > %d)) ORDER BY checked_at, id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table name.
				$site_id,
				'http',
				$start,
				$end,
				$cursor_time,
				$cursor_time,
				$cursor_id,
				self::PAGE_SIZE
			);
			$rows      = $this->database->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded read-only history.
			$row_count = count( $rows );
			foreach ( $rows as $row ) {
				$checked = new DateTimeImmutable( (string) $row['checked_at'], new DateTimeZone( 'UTC' ) );
				if ( ( $first && $checked->getTimestamp() - $from->getTimestamp() > self::HTTP_GAP_SECONDS ) || ( null !== $previous && $checked->getTimestamp() - $previous->getTimestamp() > self::HTTP_GAP_SECONDS ) ) {
					return true;
				}
				$first       = false;
				$previous    = $checked;
				$cursor_time = (string) $row['checked_at'];
				$cursor_id   = (int) $row['id'];
			}
		} while ( self::PAGE_SIZE === $row_count );

		return null === $previous || $to->getTimestamp() - $previous->getTimestamp() > self::HTTP_GAP_SECONDS;
	}
}
