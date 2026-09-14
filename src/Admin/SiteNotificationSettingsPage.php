<?php
/**
 * Site-specific notification policy administration.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

use Olein\WordPressMonitor\Notification\SiteNotificationPolicy;
use Olein\WordPressMonitor\Site\SiteRepository;

final class SiteNotificationSettingsPage {
	public const SLUG = 'od-wordpress-monitor-site-notifications';

	public function __construct(
		private readonly SiteRepository $sites,
		private readonly SiteNotificationPolicy $policy
	) {
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage notification settings.', 'od-wordpress-monitor' ) );
		}
		$sites   = $this->sites->all();
		$site_id = isset( $_GET['site_id'] ) && is_scalar( $_GET['site_id'] ) ? absint( $_GET['site_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only site selection.
		$site    = $site_id > 0 ? $this->sites->find( $site_id ) : null;
		if ( null === $site && array() !== $sites ) {
			$site    = $sites[0];
			$site_id = (int) $site->id();
		}
		$settings    = $this->policy->get( $site_id );
		$type_labels = array(
			'outage'       => __( 'Outage', 'od-wordpress-monitor' ),
			'recovery'     => __( 'Recovery', 'od-wordpress-monitor' ),
			'ssl_warning'  => __( 'SSL expiration warning', 'od-wordpress-monitor' ),
			'daily_digest' => __( 'Daily digest', 'od-wordpress-monitor' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Site Notification Settings', 'od-wordpress-monitor' ); ?></h1>
			<p><?php echo esc_html__( 'Site settings can restrict global notification rules and configured channels, but cannot enable a globally disabled rule or channel.', 'od-wordpress-monitor' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . NotificationSettingsPage::SLUG ) ); ?>"><?php echo esc_html__( 'Back to Notification Settings', 'od-wordpress-monitor' ); ?></a></p>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only redirect flag. ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Site notification settings saved.', 'od-wordpress-monitor' ); ?></p></div>
			<?php endif; ?>
			<?php if ( null === $site ) : ?>
				<p><?php echo esc_html__( 'Add a site before configuring site notifications.', 'od-wordpress-monitor' ); ?></p>
			<?php else : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<label for="odm-site-id"><?php echo esc_html__( 'Site', 'od-wordpress-monitor' ); ?></label>
					<select id="odm-site-id" name="site_id">
						<?php foreach ( $sites as $item ) : ?>
							<option value="<?php echo esc_attr( (string) $item->id() ); ?>" <?php selected( $site_id, $item->id() ); ?>><?php echo esc_html( $item->name() ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Select Site', 'od-wordpress-monitor' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="odm_save_site_notifications">
					<input type="hidden" name="site_id" value="<?php echo esc_attr( (string) $site_id ); ?>">
					<?php wp_nonce_field( 'odm_save_site_notifications_' . $site_id ); ?>
					<p><label><input type="radio" name="mode" value="inherit" <?php checked( $settings['mode'], 'inherit' ); ?>> <?php echo esc_html__( 'Inherit global settings', 'od-wordpress-monitor' ); ?></label></p>
					<p><label><input type="radio" name="mode" value="custom" <?php checked( $settings['mode'], 'custom' ); ?>> <?php echo esc_html__( 'Use site-specific restrictions', 'od-wordpress-monitor' ); ?></label></p>
					<h2><?php echo esc_html__( 'Notification types', 'od-wordpress-monitor' ); ?></h2>
					<?php foreach ( $type_labels as $type => $label ) : ?>
						<p><label><input type="checkbox" name="types[<?php echo esc_attr( $type ); ?>]" value="1" <?php checked( $settings['types'][ $type ] ); ?>> <?php echo esc_html( $label ); ?></label></p>
					<?php endforeach; ?>
					<h2><?php echo esc_html__( 'Delivery channels', 'od-wordpress-monitor' ); ?></h2>
					<?php foreach ( SiteNotificationPolicy::CHANNELS as $channel ) : ?>
						<p><label><input type="checkbox" name="channels[<?php echo esc_attr( $channel ); ?>]" value="1" <?php checked( $settings['channels'][ $channel ] ); ?>> <?php echo esc_html( ucfirst( $channel ) ); ?></label></p>
					<?php endforeach; ?>
					<?php submit_button(); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage notification settings.', 'od-wordpress-monitor' ), '', array( 'response' => 403 ) );
		}
		$site_id = isset( $_POST['site_id'] ) && is_scalar( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		check_admin_referer( 'odm_save_site_notifications_' . $site_id );
		if ( $site_id < 1 || null === $this->sites->find( $site_id ) ) {
			wp_die( esc_html__( 'The selected site is invalid.', 'od-wordpress-monitor' ), '', array( 'response' => 400 ) );
		}
		$mode     = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'inherit';
		$types    = isset( $_POST['types'] ) && is_array( $_POST['types'] ) ? wp_unslash( $_POST['types'] ) : array();
		$channels = isset( $_POST['channels'] ) && is_array( $_POST['channels'] ) ? wp_unslash( $_POST['channels'] ) : array();
		$this->policy->save( $site_id, $mode, $types, $channels );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&site_id=' . $site_id . '&updated=1' ) );
		exit;
	}
}
