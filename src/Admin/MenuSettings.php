<?php
/**
 * Optional administrator menu simplification setting.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Admin;

final class MenuSettings {
	public const OPTION = 'odm_simplify_admin_menu';
	public const GROUP  = 'odm_menu_settings_group';

	public function register(): void {
		add_option( self::OPTION, '0', '', false );
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	public function enabled(): bool {
		return '1' === get_option( self::OPTION, '0' );
	}

	/**
	 * @param mixed $value Submitted option value.
	 */
	public function sanitize( $value ): string {
		return is_string( $value ) && '1' === $value ? '1' : '0';
	}
}
