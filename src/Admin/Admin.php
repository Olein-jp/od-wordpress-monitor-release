<?php
/**
 * Monitor administration hooks.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

final class Admin {
	public function __construct(
		private readonly DashboardPage $dashboard_page,
		private readonly SitesPage $sites_page,
		private readonly SiteDetailPage $site_detail_page,
		private readonly AddSitePage $add_site_page,
		private readonly EditSitePage $edit_site_page,
		private readonly NotificationSettingsPage $notification_settings_page,
		private readonly ?SiteNotificationSettingsPage $site_notification_settings_page = null,
		private readonly ?MonthlyReportPage $monthly_report_page = null,
		private readonly ?MenuSettingsPage $menu_settings_page = null,
		private readonly ?AdminMenuSimplifier $menu_simplifier = null
	) {
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this->notification_settings_page, 'register_settings' ) );
		if ( null !== $this->menu_settings_page ) {
			add_action( 'admin_init', array( $this->menu_settings_page, 'register_settings' ) );
		}
		if ( null !== $this->menu_simplifier ) {
			add_action( 'admin_menu', array( $this->menu_simplifier, 'apply' ), PHP_INT_MAX );
		}
		add_action( 'admin_post_odm_add_site', array( $this->add_site_page, 'handle_post' ) );
		add_action( 'admin_post_odm_edit_site', array( $this->edit_site_page, 'handle_post' ) );
		add_action( 'admin_post_odm_test_connection', array( $this->sites_page, 'handle_test' ) );
		add_action( 'admin_post_odm_test_notification', array( $this->notification_settings_page, 'handle_test' ) );
		if ( null !== $this->site_notification_settings_page ) {
			add_action( 'admin_post_odm_save_site_notifications', array( $this->site_notification_settings_page, 'handle_post' ) );
		}
		if ( null !== $this->monthly_report_page ) {
			add_action( 'admin_post_odm_export_monthly_report', array( $this->monthly_report_page, 'handle_export' ) );
		}
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'WordPress Monitor', 'od-wordpress-monitor' ),
			__( 'WordPress Monitor', 'od-wordpress-monitor' ),
			'manage_options',
			'od-wordpress-monitor',
			array( $this->dashboard_page, 'render' ),
			'dashicons-visibility'
		);

		add_submenu_page(
			'od-wordpress-monitor',
			__( 'Dashboard', 'od-wordpress-monitor' ),
			__( 'Dashboard', 'od-wordpress-monitor' ),
			'manage_options',
			'od-wordpress-monitor',
			array( $this->dashboard_page, 'render' )
		);

		add_submenu_page(
			'od-wordpress-monitor',
			__( 'Sites', 'od-wordpress-monitor' ),
			__( 'Sites', 'od-wordpress-monitor' ),
			'manage_options',
			SitesPage::SLUG,
			array( $this->sites_page, 'render' )
		);

		add_submenu_page(
			null,
			__( 'Site Details', 'od-wordpress-monitor' ),
			__( 'Site Details', 'od-wordpress-monitor' ),
			'manage_options',
			SiteDetailPage::SLUG,
			array( $this->site_detail_page, 'render' )
		);

		add_submenu_page(
			'od-wordpress-monitor',
			__( 'Add Site', 'od-wordpress-monitor' ),
			__( 'Add Site', 'od-wordpress-monitor' ),
			'manage_options',
			'od-wordpress-monitor-add',
			array( $this->add_site_page, 'render' )
		);

		add_submenu_page(
			'od-wordpress-monitor',
			__( 'Notification Settings', 'od-wordpress-monitor' ),
			__( 'Notifications', 'od-wordpress-monitor' ),
			'manage_options',
			NotificationSettingsPage::SLUG,
			array( $this->notification_settings_page, 'render' )
		);

		add_submenu_page(
			null,
			__( 'Edit Site', 'od-wordpress-monitor' ),
			__( 'Edit Site', 'od-wordpress-monitor' ),
			'manage_options',
			EditSitePage::SLUG,
			array( $this->edit_site_page, 'render' )
		);
		if ( null !== $this->site_notification_settings_page ) {
			add_submenu_page(
				null,
				__( 'Site Notification Settings', 'od-wordpress-monitor' ),
				__( 'Site Notification Settings', 'od-wordpress-monitor' ),
				'manage_options',
				SiteNotificationSettingsPage::SLUG,
				array( $this->site_notification_settings_page, 'render' )
			);
		}
		if ( null !== $this->menu_settings_page ) {
			add_submenu_page(
				'od-wordpress-monitor',
				__( 'Admin Menu Settings', 'od-wordpress-monitor' ),
				__( 'Menu Settings', 'od-wordpress-monitor' ),
				'manage_options',
				MenuSettingsPage::SLUG,
				array( $this->menu_settings_page, 'render' )
			);
		}
		if ( null !== $this->monthly_report_page ) {
			add_submenu_page(
				'od-wordpress-monitor',
				__( 'Monthly Monitoring Report', 'od-wordpress-monitor' ),
				__( 'Reports', 'od-wordpress-monitor' ),
				'manage_options',
				MonthlyReportPage::SLUG,
				array( $this->monthly_report_page, 'render' )
			);
		}
	}
}
