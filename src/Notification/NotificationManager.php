<?php
/**
 * Selects and dispatches configured notifications.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Notification;

use Olein\WordPressMonitor\Event\MonitoringEvent;
use InvalidArgumentException;
use Throwable;

final class NotificationManager {
	/** @var array<string,NotificationSenderInterface> */
	private readonly array $senders;

	/**
	 * @param list<NotificationSenderInterface> $senders Available delivery channels.
	 */
	public function __construct(
		private readonly NotificationRule $rule,
		private readonly NotificationMessageFactoryInterface $messages,
		array $senders,
		private readonly ?SiteNotificationPolicy $site_policy = null
	) {
		$indexed = array();

		foreach ( $senders as $sender ) {
			if ( ! $sender instanceof NotificationSenderInterface ) {
				throw new InvalidArgumentException( 'Notification senders must implement the sender interface.' );
			}

			$channel_id = $sender->channel_id();

			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $channel_id ) || isset( $indexed[ $channel_id ] ) ) {
				throw new InvalidArgumentException( 'Notification sender channel IDs must be valid and unique.' );
			}

			$indexed[ $channel_id ] = $sender;
		}

		$this->senders = $indexed;
	}

	/**
	 * Dispatch an allowed transition to every enabled channel.
	 *
	 * @return NotificationDeliveryResult|null Results for attempted channels, or null when suppressed.
	 */
	public function notify( MonitoringEvent $event ): ?NotificationDeliveryResult {
		$notification_type = $this->rule->classify( $event );

		if ( null === $notification_type ) {
			return null;
		}
		if ( null !== $this->site_policy && ! $this->site_policy->allows_type( $event->site_id(), $notification_type ) ) {
			return null;
		}

		$message = $this->messages->create( $event, $notification_type );

		if ( null === $message ) {
			return null;
		}

		return $this->dispatch_for_site( $message, $event->site_id() );
	}

	/**
	 * Dispatch a message only to channels permitted by the site's current policy.
	 */
	public function dispatch_for_site( NotificationMessage $message, int $site_id ): ?NotificationDeliveryResult {
		$results = array();
		foreach ( $this->senders as $channel_id => $sender ) {
			if ( null !== $this->site_policy && ! $this->site_policy->allows_channel( $site_id, $channel_id ) ) {
				continue;
			}
			$result = $this->send_to_channel( $sender, $channel_id, $message );
			if ( null !== $result ) {
				$results[] = $result;
			}
		}
		return array() === $results ? null : new NotificationDeliveryResult( $results );
	}

	/**
	 * Available channel IDs for assembling channel-specific digests.
	 *
	 * @return list<string>
	 */
	public function channel_ids(): array {
		return array_keys( $this->senders );
	}

	public function dispatch_channel( NotificationMessage $message, string $channel_id ): ?NotificationChannelResult {
		$sender = $this->senders[ $channel_id ] ?? null;
		return null === $sender ? null : $this->send_to_channel( $sender, $channel_id, $message );
	}

	/**
	 * Dispatch an already sanitized message to every enabled channel.
	 */
	public function dispatch( NotificationMessage $message ): ?NotificationDeliveryResult {
		$results = array();

		foreach ( $this->senders as $channel_id => $sender ) {
			$result = $this->send_to_channel( $sender, $channel_id, $message );
			if ( null !== $result ) {
				$results[] = $result;
			}
		}

		return array() === $results ? null : new NotificationDeliveryResult( $results );
	}

	/**
	 * Rebuild a single event message and use the channel's current settings.
	 */
	public function retry_channel( MonitoringEvent $event, string $channel_id ): ?NotificationChannelResult {
		$sender = $this->senders[ $channel_id ] ?? null;
		if ( null === $sender ) {
			return null;
		}

		$notification_type = $this->rule->classify( $event );
		if ( null === $notification_type ) {
			return null;
		}
		if ( null !== $this->site_policy && ( ! $this->site_policy->allows_type( $event->site_id(), $notification_type ) || ! $this->site_policy->allows_channel( $event->site_id(), $channel_id ) ) ) {
			return null;
		}

		$message = $this->messages->create( $event, $notification_type );
		return null === $message ? null : $this->send_to_channel( $sender, $channel_id, $message );
	}

	private function send_to_channel( NotificationSenderInterface $sender, string $channel_id, NotificationMessage $message ): ?NotificationChannelResult {
		try {
			if ( ! $sender->enabled() ) {
				return null;
			}

			$result = $sender->send( $message );
			return $channel_id === $result->channel_id()
				? $result
				: NotificationChannelResult::failed( $channel_id, 'CHANNEL_ID_MISMATCH' );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return NotificationChannelResult::failed( $channel_id, 'DELIVERY_EXCEPTION' );
		}
	}
}
