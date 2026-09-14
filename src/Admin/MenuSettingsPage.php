<?php
/**
 * Menu display settings inside WordPress Monitor.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

final class MenuSettingsPage {
	public const SLUG = 'od-wordpress-monitor-menu-settings';

	public function __construct( private readonly MenuSettings $settings ) {
	}

	public function register_settings(): void {
		$this->settings->register();
		add_settings_section( 'odm_menu_settings_section', __( 'Admin menu display', 'od-wordpress-monitor' ), array( $this, 'render_section' ), self::SLUG );
		add_settings_field( 'odm_simplify_admin_menu', __( 'Simplify admin menu', 'od-wordpress-monitor' ), array( $this, 'render_field' ), self::SLUG, 'odm_menu_settings_section' );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menu settings.', 'od-wordpress-monitor' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Admin Menu Settings', 'od-wordpress-monitor' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( MenuSettings::GROUP ); ?>
				<?php do_settings_sections( self::SLUG ); ?>
				<?php submit_button(); ?>
			</form>
			<p><?php echo esc_html__( 'Hidden menu items remain accessible by direct URL if your account has permission. Turn this setting off to restore the full menu.', 'od-wordpress-monitor' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php echo esc_html__( 'General Settings', 'od-wordpress-monitor' ); ?></a>
				|
				<a href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php echo esc_html__( 'Site Health', 'od-wordpress-monitor' ); ?></a>
			</p>
		</div>
		<?php
	}

	public function render_section(): void {
		echo '<p>' . esc_html__( 'The default keeps every WordPress menu visible. Simplification affects only the menu display for administrators on this site.', 'od-wordpress-monitor' ) . '</p>';
	}

	public function render_field(): void {
		?>
		<input type="hidden" name="<?php echo esc_attr( MenuSettings::OPTION ); ?>" value="0">
		<label for="odm-simplify-admin-menu">
			<input id="odm-simplify-admin-menu" type="checkbox" name="<?php echo esc_attr( MenuSettings::OPTION ); ?>" value="1" <?php checked( $this->settings->enabled() ); ?>>
			<?php echo esc_html__( 'Show only Dashboard, Plugins, and WordPress Monitor menus', 'od-wordpress-monitor' ); ?>
		</label>
		<?php
	}
}
