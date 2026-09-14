<?php
/**
 * Hide optional admin menu entries without changing page capabilities.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

final class AdminMenuSimplifier {
	private const TOP_LEVEL = array( 'index.php', 'plugins.php', 'od-wordpress-monitor' );
	private const DASHBOARD = array( 'index.php', 'update-core.php' );
	private const PLUGINS   = array( 'plugins.php', 'plugin-install.php' );
	private const MONITOR   = array(
		'od-wordpress-monitor',
		SitesPage::SLUG,
		'od-wordpress-monitor-add',
		NotificationSettingsPage::SLUG,
		MonthlyReportPage::SLUG,
		MenuSettingsPage::SLUG,
	);

	public function __construct( private readonly MenuSettings $settings ) {
	}

	public function apply(): void {
		if ( ! $this->settings->enabled() || ! current_user_can( 'manage_options' ) || is_network_admin() || is_user_admin() ) {
			return;
		}

		global $menu, $submenu;
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && ! in_array( $item[2], self::TOP_LEVEL, true ) ) {
				remove_menu_page( $item[2] );
			}
		}
		$allowed = array(
			'index.php'            => self::DASHBOARD,
			'plugins.php'          => self::PLUGINS,
			'od-wordpress-monitor' => self::MONITOR,
		);
		foreach ( $allowed as $parent => $slugs ) {
			foreach ( $submenu[ $parent ] ?? array() as $item ) {
				if ( isset( $item[2] ) && ! in_array( $item[2], $slugs, true ) ) {
					remove_submenu_page( $parent, $item[2] );
				}
			}
		}
	}
}
