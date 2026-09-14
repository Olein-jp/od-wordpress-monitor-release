<?php
/**
 * Shared rows for the admin report and CSV export.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Report;

use Olein\WordPressMonitor\Admin\StatusLabel;
use Olein\WordPressMonitor\Event\EventType;

final class MonthlyReportFormatter {
	/**
	 * @param array<string,mixed> $report Monthly report summary.
	 * @return list<array{string,string,string,string}>
	 */
	public function rows( string $site_name, array $report ): array {
		$rows = array(
			array( __( 'Report', 'od-wordpress-monitor' ), __( 'Site', 'od-wordpress-monitor' ), $site_name, '' ),
			array( __( 'Report', 'od-wordpress-monitor' ), __( 'Month', 'od-wordpress-monitor' ), $report['month'], '' ),
			array( __( 'Report', 'od-wordpress-monitor' ), __( 'From (site time)', 'od-wordpress-monitor' ), $report['from']->format( 'Y-m-d H:i T' ), '' ),
			array( __( 'Report', 'od-wordpress-monitor' ), __( 'Until (exclusive, site time)', 'od-wordpress-monitor' ), $report['to']->format( 'Y-m-d H:i T' ), '' ),
			array( __( 'Report', 'od-wordpress-monitor' ), __( 'Data coverage', 'od-wordpress-monitor' ), $this->coverage_label( $report['coverage'] ), '' ),
			array( __( 'Report', 'od-wordpress-monitor' ), __( 'Interpretation', 'od-wordpress-monitor' ), __( 'Counts reflect stored checks only. Unchecked periods are unknown, not healthy; no uptime or SLA is calculated.', 'od-wordpress-monitor' ), '' ),
			array( __( 'Checks', 'od-wordpress-monitor' ), __( 'Total recorded checks', 'od-wordpress-monitor' ), '', (string) $report['check_count'] ),
		);
		foreach ( $report['checks'] as $check ) {
			$rows[] = array( __( 'Checks', 'od-wordpress-monitor' ), $this->check_label( $check['type'] ), StatusLabel::for_status( $check['status'] ), (string) $check['count'] );
		}
		$event_count = array_sum( array_column( $report['events'], 'count' ) );
		$rows[]      = array( __( 'Events', 'od-wordpress-monitor' ), __( 'Total recorded events', 'od-wordpress-monitor' ), '', (string) $event_count );
		foreach ( $report['events'] as $event ) {
			$rows[] = array( __( 'Events', 'od-wordpress-monitor' ), $this->event_label( $event['type'] ), StatusLabel::for_status( $event['previous'] ) . ' → ' . StatusLabel::for_status( $event['current'] ), (string) $event['count'] );
		}
		return $rows;
	}

	public function coverage_label( string $coverage ): string {
		return 'recorded' === $coverage
			? __( 'Records span the full month (not uptime or SLA)', 'od-wordpress-monitor' )
			: __( 'Insufficient data / partial month', 'od-wordpress-monitor' );
	}

	/**
	 * @param resource $stream Open CSV output stream.
	 * @param list<array{string,string,string,string}> $rows Report rows.
	 */
	public function write_csv( $stream, array $rows ): void {
		fputcsv( $stream, array( 'section', 'item', 'status', 'count' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $stream, array_map( array( $this, 'safe_csv_cell' ), $row ), ',', '"', '' );
		}
	}

	public function safe_csv_cell( string $cell ): string {
		$cell = str_replace( "\0", '', $cell );
		return 1 === preg_match( '/^[\p{Z}\p{C}]*[=+\-@]/u', $cell ) ? "'" . $cell : $cell;
	}

	private function check_label( string $type ): string {
		return match ( $type ) {
			'http'         => __( 'HTTP', 'od-wordpress-monitor' ),
			'agent_ping'   => __( 'Agent ping', 'od-wordpress-monitor' ),
			'agent_status' => __( 'Agent status', 'od-wordpress-monitor' ),
			'updates'      => __( 'Updates', 'od-wordpress-monitor' ),
			'site_health'  => __( 'Site Health', 'od-wordpress-monitor' ),
			'ssl'          => __( 'SSL', 'od-wordpress-monitor' ),
			default        => __( 'Unknown check', 'od-wordpress-monitor' ),
		};
	}

	private function event_label( string $type ): string {
		return match ( $type ) {
			EventType::SITE_DOWN             => __( 'Site down', 'od-wordpress-monitor' ),
			EventType::RECOVERED             => __( 'Recovered', 'od-wordpress-monitor' ),
			EventType::AGENT                 => __( 'Agent', 'od-wordpress-monitor' ),
			EventType::UPDATES               => __( 'Updates', 'od-wordpress-monitor' ),
			EventType::SITE_HEALTH_CRITICAL  => __( 'Site Health problem', 'od-wordpress-monitor' ),
			EventType::SITE_HEALTH_PARTIALLY_RECOVERED => __( 'Site Health partially recovered', 'od-wordpress-monitor' ),
			EventType::SITE_HEALTH_RECOVERED => __( 'Site Health recovered', 'od-wordpress-monitor' ),
			EventType::SSL                   => __( 'SSL', 'od-wordpress-monitor' ),
			default                          => __( 'Unknown event', 'od-wordpress-monitor' ),
		};
	}
}
