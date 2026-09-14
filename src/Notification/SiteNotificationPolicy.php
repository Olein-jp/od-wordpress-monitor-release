<?php
/**
 * Per-site notification overrides. Missing records inherit global behavior.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

final class SiteNotificationPolicy {
	public const OPTION = 'odm_site_notification_policies';

	public const TYPES    = array( NotificationRule::OUTAGE, NotificationRule::RECOVERY, NotificationRule::SSL_WARNING, NotificationRule::DAILY_DIGEST );
	public const CHANNELS = array( 'email', 'slack', 'discord', 'chatwork' );

	/**
	 * @return array{mode:string,types:array<string,bool>,channels:array<string,bool>}
	 */
	public function get( int $site_id ): array {
		$records  = get_option( self::OPTION, array() );
		$record   = is_array( $records ) ? ( $records[ (string) $site_id ] ?? null ) : null;
		$types    = array_fill_keys( self::TYPES, true );
		$channels = array_fill_keys( self::CHANNELS, true );

		if ( ! is_array( $record ) || 'custom' !== ( $record['mode'] ?? '' ) ) {
			return array(
				'mode'     => 'inherit',
				'types'    => $types,
				'channels' => $channels,
			);
		}

		foreach ( self::TYPES as $type ) {
			$types[ $type ] = ! empty( $record['types'][ $type ] );
		}
		foreach ( self::CHANNELS as $channel ) {
			$channels[ $channel ] = ! empty( $record['channels'][ $channel ] );
		}
		return array(
			'mode'     => 'custom',
			'types'    => $types,
			'channels' => $channels,
		);
	}

	public function allows_type( int $site_id, string $type ): bool {
		$settings = $this->get( $site_id );
		return $settings['types'][ $type ] ?? false;
	}

	public function allows_channel( int $site_id, string $channel ): bool {
		$settings = $this->get( $site_id );
		return $settings['channels'][ $channel ] ?? false;
	}

	/**
	 * @param array<string,mixed> $types Selected notification types.
	 * @param array<string,mixed> $channels Selected channels.
	 */
	public function save( int $site_id, string $mode, array $types, array $channels ): void {
		if ( $site_id < 1 ) {
			return;
		}
		$records = get_option( self::OPTION, array() );
		$records = is_array( $records ) ? $records : array();
		$key     = (string) $site_id;
		if ( 'custom' !== $mode ) {
			unset( $records[ $key ] );
		} else {
			$records[ $key ] = array(
				'mode'     => 'custom',
				'types'    => array_fill_keys( array_intersect( self::TYPES, array_keys( $types ) ), true ),
				'channels' => array_fill_keys( array_intersect( self::CHANNELS, array_keys( $channels ) ), true ),
			);
		}
		update_option( self::OPTION, $records, false );
	}
}
