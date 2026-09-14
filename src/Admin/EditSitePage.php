<?php
/**
 * Edit and pause a monitored site.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use Olein\WordPressMonitor\Credential\Credential;
use Olein\WordPressMonitor\Site\SiteRepository;
use Olein\WordPressMonitor\Site\SiteService;

final class EditSitePage {
	public const SLUG = 'od-wordpress-monitor-edit-site';

	public function __construct(
		private readonly SiteRepository $sites,
		private readonly SiteService $service
	) {
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage monitored sites.', 'od-wordpress-monitor' ) );
		}

		$site_id = isset( $_GET['site_id'] ) && is_scalar( $_GET['site_id'] ) ? absint( wp_unslash( $_GET['site_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only site selection.
		$site    = $this->sites->find( $site_id );
		if ( null === $site ) {
			wp_die( esc_html__( 'The monitored site was not found.', 'od-wordpress-monitor' ) );
		}

		$notice = get_transient( 'odm_admin_notice_' . get_current_user_id() );
		delete_transient( 'odm_admin_notice_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Edit Site', 'od-wordpress-monitor' ); ?></h1>
			<?php if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) : ?>
				<div class="notice <?php echo esc_attr( 'success' === $notice['type'] ? 'notice-success' : 'notice-error' ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . SiteDetailPage::SLUG . '&site_id=' . $site_id ) ); ?>">&larr; <?php echo esc_html__( 'Back to Site Details', 'od-wordpress-monitor' ); ?></a></p>
			<p><?php echo esc_html( $site->enabled() ? __( 'Monitoring is active.', 'od-wordpress-monitor' ) : __( 'Monitoring is paused. Existing history is retained.', 'od-wordpress-monitor' ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="odm_edit_site">
				<input type="hidden" name="operation" value="save">
				<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site_id ); ?>">
				<?php wp_nonce_field( 'odm_edit_site_' . $site_id ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="odm-edit-name"><?php echo esc_html__( 'Site Name', 'od-wordpress-monitor' ); ?></label></th>
						<td><input class="regular-text" id="odm-edit-name" name="site_name" type="text" value="<?php echo esc_attr( $site->name() ); ?>" required></td></tr>
					<tr><th scope="row"><label for="odm-edit-url"><?php echo esc_html__( 'Site URL', 'od-wordpress-monitor' ); ?></label></th>
						<td><input class="regular-text" id="odm-edit-url" name="site_url" type="url" value="<?php echo esc_attr( $site->site_url() ); ?>" required></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Agent Credential', 'od-wordpress-monitor' ); ?></th><td>
						<label><input type="checkbox" name="replace_credential" value="1"> <?php echo esc_html__( 'Replace the stored Application Password', 'od-wordpress-monitor' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Leave both fields blank to keep the existing credential. To replace it, check the box and enter both fields.', 'od-wordpress-monitor' ); ?></p>
						<p><label for="odm-edit-username"><?php echo esc_html__( 'Agent Username', 'od-wordpress-monitor' ); ?></label><br><input class="regular-text" id="odm-edit-username" name="agent_username" type="text" autocomplete="username"></p>
						<p><label for="odm-edit-password"><?php echo esc_html__( 'Application Password', 'od-wordpress-monitor' ); ?></label><br><input class="regular-text" id="odm-edit-password" name="application_password" type="password" autocomplete="new-password"></p>
					</td></tr>
				</table>
				<?php submit_button( __( 'Save Site', 'od-wordpress-monitor' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="odm_edit_site">
				<input type="hidden" name="operation" value="<?php echo esc_attr( $site->enabled() ? 'pause' : 'resume' ); ?>">
				<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site_id ); ?>">
				<?php wp_nonce_field( 'odm_edit_site_' . $site_id ); ?>
				<?php submit_button( $site->enabled() ? __( 'Pause Monitoring', 'od-wordpress-monitor' ) : __( 'Resume Monitoring', 'od-wordpress-monitor' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage monitored sites.', 'od-wordpress-monitor' ), '', array( 'response' => 403 ) );
		}

		$site_id = isset( $_POST['site_id'] ) && is_scalar( $_POST['site_id'] ) ? absint( wp_unslash( $_POST['site_id'] ) ) : 0;
		check_admin_referer( 'odm_edit_site_' . $site_id );
		$operation = isset( $_POST['operation'] ) && is_scalar( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';

		if ( 'pause' === $operation || 'resume' === $operation ) {
			$result = $this->service->set_enabled( $site_id, 'resume' === $operation );
		} elseif ( 'save' === $operation ) {
			$name     = isset( $_POST['site_name'] ) && is_scalar( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : '';
			$site_url = isset( $_POST['site_url'] ) && is_scalar( $_POST['site_url'] ) ? (string) wp_unslash( $_POST['site_url'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by UrlValidator in SiteService.
			$replace  = isset( $_POST['replace_credential'] ) && is_scalar( $_POST['replace_credential'] ) && '1' === (string) wp_unslash( $_POST['replace_credential'] );
			$username = isset( $_POST['agent_username'] ) && is_scalar( $_POST['agent_username'] ) ? sanitize_text_field( wp_unslash( $_POST['agent_username'] ) ) : '';
			$password = isset( $_POST['application_password'] ) && is_scalar( $_POST['application_password'] ) ? (string) wp_unslash( $_POST['application_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Secret is encrypted before storage.
			if ( ! $replace && ( '' !== $username || '' !== $password ) ) {
				$result = new \WP_Error( 'INVALID_CREDENTIAL_INPUT' );
			} elseif ( $replace && ( '' === $username || '' === $password ) ) {
				$result = new \WP_Error( 'INVALID_CREDENTIAL_INPUT' );
			} else {
				$result = $this->service->update( $site_id, $name, $site_url, $replace ? new Credential( $username, $password ) : null );
			}
			$password = '';
		} else {
			$result = new \WP_Error( 'INVALID_OPERATION' );
		}

		$message = is_wp_error( $result )
			? ( 'INVALID_CREDENTIAL_INPUT' === $result->get_error_code()
				? __( 'Check the replacement box and enter both credential fields, or leave both fields blank.', 'od-wordpress-monitor' )
				: ErrorMessages::for_code( $result->get_error_code() ) )
			: ( 'pause' === $operation
				? __( 'Monitoring paused.', 'od-wordpress-monitor' )
				: ( 'resume' === $operation ? __( 'Monitoring resumed. Current status will update after the next check.', 'od-wordpress-monitor' ) : __( 'Site saved.', 'od-wordpress-monitor' ) ) );

		set_transient(
			'odm_admin_notice_' . get_current_user_id(),
			array(
				'type'    => is_wp_error( $result ) ? 'error' : 'success',
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&site_id=' . $site_id ) );
		exit;
	}
}
