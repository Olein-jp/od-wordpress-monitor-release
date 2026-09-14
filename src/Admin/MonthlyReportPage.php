<?php
/**
 * Administrator-only monthly report and CSV export.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use InvalidArgumentException;
use Olein\WordPressMonitor\Report\MonthlyReportFormatter;
use Olein\WordPressMonitor\Report\MonthlyReportService;
use Olein\WordPressMonitor\Site\Site;
use Olein\WordPressMonitor\Site\SiteRepository;

final class MonthlyReportPage {
	public const SLUG = 'od-wordpress-monitor-monthly-report';

	public function __construct(
		private readonly SiteRepository $sites,
		private readonly MonthlyReportService $reports,
		private readonly MonthlyReportFormatter $formatter
	) {
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view monthly reports.', 'od-wordpress-monitor' ), '', array( 'response' => 403 ) );
		}
		$sites           = $this->sites->all();
		$requested_site  = isset( $_GET['site_id'] ) && is_string( $_GET['site_id'] ) ? wp_unslash( $_GET['site_id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection.
		$requested_month = isset( $_GET['month'] ) && is_string( $_GET['month'] ) ? wp_unslash( $_GET['month'] ) : wp_date( 'Y-m' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection.
		$site_id         = $this->parse_site_id( $requested_site );
		$site            = null !== $site_id ? $this->sites->find( $site_id ) : null;
		if ( '' === $requested_site && array() !== $sites ) {
			$site    = $sites[0];
			$site_id = $site->id();
		}
		$valid_month = 1 === preg_match( '/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/', $requested_month );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Monthly Monitoring Report', 'od-wordpress-monitor' ); ?></h1>
			<p><?php echo esc_html__( 'Reports summarize stored history only. Missing checks are unknown, never counted as uptime. Check history is retained for 90 days.', 'od-wordpress-monitor' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="odm-report-site"><?php echo esc_html__( 'Site', 'od-wordpress-monitor' ); ?></label>
				<select id="odm-report-site" name="site_id" required>
					<?php foreach ( $sites as $item ) : ?>
						<option value="<?php echo esc_attr( (string) $item->id() ); ?>" <?php selected( $site_id, $item->id() ); ?>><?php echo esc_html( $item->name() ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="odm-report-month"><?php echo esc_html__( 'Month', 'od-wordpress-monitor' ); ?></label>
				<input id="odm-report-month" type="month" name="month" value="<?php echo esc_attr( $requested_month ); ?>" required>
				<?php submit_button( __( 'Show Report', 'od-wordpress-monitor' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( null === $site || ! $valid_month ) : ?>
				<p><?php echo esc_html__( 'Select a valid site and month.', 'od-wordpress-monitor' ); ?></p>
			<?php else : ?>
				<?php $this->render_report( $site, $requested_month ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_report( Site $site, string $month ): void {
		try {
			$report = $this->reports->build( (int) $site->id(), $month );
		} catch ( InvalidArgumentException $exception ) {
			unset( $exception );
			echo '<p>' . esc_html__( 'Select a valid site and month.', 'od-wordpress-monitor' ) . '</p>';
			return;
		}
		$rows = $this->formatter->rows( $site->name(), $report );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="odm_export_monthly_report">
			<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site->id() ); ?>">
			<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
			<?php wp_nonce_field( 'odm_export_monthly_report_' . $site->id() . '_' . $month ); ?>
			<?php submit_button( __( 'Download CSV', 'od-wordpress-monitor' ), 'secondary', 'submit', false ); ?>
		</form>
		<table class="widefat striped">
			<thead><tr>
				<th><?php echo esc_html__( 'Section', 'od-wordpress-monitor' ); ?></th>
				<th><?php echo esc_html__( 'Item', 'od-wordpress-monitor' ); ?></th>
				<th><?php echo esc_html__( 'Status / Detail', 'od-wordpress-monitor' ); ?></th>
				<th><?php echo esc_html__( 'Count', 'od-wordpress-monitor' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
					<?php
					foreach ( $row as $cell ) :
						?>
						<td><?php echo esc_html( $cell ); ?></td><?php endforeach; ?></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export monthly reports.', 'od-wordpress-monitor' ), '', array( 'response' => 403 ) );
		}
		$raw_site = isset( $_POST['site_id'] ) && is_string( $_POST['site_id'] ) ? wp_unslash( $_POST['site_id'] ) : '';
		$month    = isset( $_POST['month'] ) && is_string( $_POST['month'] ) ? wp_unslash( $_POST['month'] ) : '';
		$site_id  = $this->parse_site_id( $raw_site );
		if ( null === $site_id || 1 !== preg_match( '/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/', $month ) ) {
			wp_die( esc_html__( 'Select a valid site and month.', 'od-wordpress-monitor' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'odm_export_monthly_report_' . $site_id . '_' . $month );
		$site = $this->sites->find( $site_id );
		if ( null === $site ) {
			wp_die( esc_html__( 'Select a valid site and month.', 'od-wordpress-monitor' ), '', array( 'response' => 400 ) );
		}
		try {
			$report = $this->reports->build( $site_id, $month );
		} catch ( InvalidArgumentException $exception ) {
			unset( $exception );
			wp_die( esc_html__( 'Select a valid site and month.', 'od-wordpress-monitor' ), '', array( 'response' => 400 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="monitor-report-' . $site_id . '-' . $month . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$stream = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streamed CSV download, not filesystem storage.
		if ( false !== $stream ) {
			$this->formatter->write_csv( $stream, $this->formatter->rows( $site->name(), $report ) );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close output stream.
		}
		exit;
	}

	private function parse_site_id( string $value ): ?int {
		return 1 === preg_match( '/^[1-9][0-9]*$/', $value ) && filter_var( $value, FILTER_VALIDATE_INT ) ? (int) $value : null;
	}
}
