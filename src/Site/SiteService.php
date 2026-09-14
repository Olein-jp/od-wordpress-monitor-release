<?php
/**
 * Monitored site workflows.
 *
 * @package OD_WordPress_Monitor
 */

namespace Olein\WordPressMonitor\Site;

use Olein\WordPressMonitor\Credential\Credential;
use Olein\WordPressMonitor\Credential\CredentialService;
use Olein\WordPressMonitor\Http\AgentClient;
use Olein\WordPressMonitor\Http\UrlValidator;
use Olein\WordPressMonitor\Support\ErrorCode;
use Olein\WordPressMonitor\Support\UUID;
use Olein\WordPressMonitor\Status\SiteStatusRepository;
use Olein\WordPressMonitor\Status\SiteStatus;
use WP_Error;

final class SiteService {
	private readonly UrlValidator $url_validator;
	private readonly SiteStatusRepository $statuses;

	public function __construct(
		private readonly SiteRepository $sites,
		private readonly CredentialService $credentials,
		private readonly AgentClient $agent,
		private readonly UUID $uuid,
		?UrlValidator $url_validator = null,
		?SiteStatusRepository $statuses = null
	) {
		$this->url_validator = $url_validator ?? new UrlValidator();
		$this->statuses      = $statuses ?? new SiteStatusRepository( $GLOBALS['wpdb'] );
	}

	/**
	 * Update a registered site after validating changed connection details.
	 *
	 * @return true|WP_Error
	 */
	public function update( int $site_id, string $name, string $site_url, ?Credential $replacement = null ) {
		$site = $this->sites->find( $site_id );
		if ( null === $site ) {
			return new WP_Error( 'SITE_NOT_FOUND', __( 'The monitored site was not found.', 'od-wordpress-monitor' ) );
		}

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'SITE_NAME_REQUIRED', __( 'A site name is required.', 'od-wordpress-monitor' ) );
		}

		$normalized_url = $site->site_url() === trim( $site_url ) ? $site->site_url() : $this->normalize_url( $site_url );
		if ( is_wp_error( $normalized_url ) ) {
			return $normalized_url;
		}

		$connection_changed = $normalized_url !== $site->site_url() || null !== $replacement;
		$updated            = new Site(
			$site_id,
			$site->uuid(),
			$name,
			$normalized_url,
			trailingslashit( $normalized_url ) . 'wp-json/od-monitor-agent/v1',
			$site->enabled()
		);

		if ( $connection_changed ) {
			$credential = $replacement ?? $this->credentials->for_site( $site_id );
			if ( is_wp_error( $credential ) ) {
				return $credential;
			}
			if ( '' === $credential->username() || '' === $credential->password() ) {
				return new WP_Error( 'CREDENTIAL_INPUT_REQUIRED', __( 'Both credential fields are required.', 'od-wordpress-monitor' ) );
			}

			$ping = $this->agent->ping( $updated, $credential );
			if ( is_wp_error( $ping ) ) {
				return $ping;
			}
			$status = $this->agent->status( $updated, $credential );
			if ( is_wp_error( $status ) ) {
				return $status;
			}
		}

		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->database_error();
		}

		if ( ! $this->sites->update( $updated ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->database_error();
		}
		if ( null !== $replacement && true !== $this->credentials->replace( $site_id, $replacement ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->database_error();
		}
		if ( $connection_changed && ! $this->statuses->delete_for_site( $site_id ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->database_error();
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->database_error();
		}

		if ( $connection_changed ) {
			delete_transient( 'odm_status_' . $site->uuid() );
		}

		return true;
	}

	/**
	 * Pause or resume scheduled monitoring without deleting history.
	 *
	 * @return true|WP_Error
	 */
	public function set_enabled( int $site_id, bool $enabled ) {
		$site = $this->sites->find( $site_id );
		if ( null === $site ) {
			return new WP_Error( 'SITE_NOT_FOUND', __( 'The monitored site was not found.', 'od-wordpress-monitor' ) );
		}
		if ( $enabled === $site->enabled() ) {
			return true;
		}

		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->database_error();
		}

		$updated = new Site( $site_id, $site->uuid(), $site->name(), $site->site_url(), $site->agent_url(), $enabled );
		$reset   = $enabled
			? $this->statuses->upsert(
				new SiteStatus(
					$site_id,
					metadata: array(
						'resume_pending' => array_fill_keys( array( 'http', 'agent_ping', 'agent_status', 'updates', 'site_health', 'ssl' ), true ),
					)
				)
			)
			: $this->statuses->delete_for_site( $site_id );
		if ( ! $this->sites->update( $updated ) || true !== $reset ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->database_error();
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $this->database_error();
		}

		delete_transient( 'odm_status_' . $site->uuid() );
		return true;
	}

	private function database_error(): WP_Error {
		return new WP_Error( 'DATABASE_ERROR', __( 'The site could not be saved.', 'od-wordpress-monitor' ) );
	}

	/**
	 * Validate, connect, and atomically persist a new site as far as WordPress APIs allow.
	 *
	 * @return array{site:Site,status:array<string,mixed>}|WP_Error
	 */
	public function register( string $name, string $site_url, string $username, string $password ) {
		$name     = sanitize_text_field( $name );
		$username = sanitize_text_field( $username );

		if ( '' === $name || '' === $username || '' === $password ) {
			return new WP_Error( ErrorCode::INVALID_RESPONSE, __( 'All fields are required.', 'od-wordpress-monitor' ) );
		}

		$normalized_url = $this->normalize_url( $site_url );

		if ( is_wp_error( $normalized_url ) ) {
			return $normalized_url;
		}

		$site       = new Site(
			null,
			$this->uuid->generate(),
			$name,
			$normalized_url,
			trailingslashit( $normalized_url ) . 'wp-json/od-monitor-agent/v1'
		);
		$credential = new Credential( $username, $password );

		$ping = $this->agent->ping( $site, $credential );
		if ( is_wp_error( $ping ) ) {
			return $ping;
		}

		$status = $this->agent->status( $site, $credential );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$site_id = $this->sites->create( $site );
		if ( is_wp_error( $site_id ) ) {
			return $site_id;
		}

		$credential_id = $this->credentials->store( $site_id, $credential );
		if ( is_wp_error( $credential_id ) ) {
			$this->sites->delete( $site_id );
			return $credential_id;
		}

		$saved_site = new Site( $site_id, $site->uuid(), $site->name(), $site->site_url(), $site->agent_url(), true );
		$this->cache_status( $saved_site, $status );

		return array(
			'site'   => $saved_site,
			'status' => $status,
		);
	}

	/**
	 * Test a stored site's connection.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function test_connection( int $site_id ) {
		$site = $this->sites->find( $site_id );

		if ( null === $site ) {
			return new WP_Error( 'SITE_NOT_FOUND', __( 'The monitored site was not found.', 'od-wordpress-monitor' ) );
		}

		$credential = $this->credentials->for_site( $site_id );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}

		$ping = $this->agent->ping( $site, $credential );
		if ( is_wp_error( $ping ) ) {
			return $ping;
		}

		$status = $this->agent->status( $site, $credential );
		if ( ! is_wp_error( $status ) ) {
			$this->cache_status( $site, $status );
		}

		return $status;
	}

	/**
	 * Return the most recently confirmed status without making a request.
	 *
	 * @return array<string,mixed>|false
	 */
	public function cached_status( Site $site ) {
		return get_transient( 'odm_status_' . $site->uuid() );
	}

	/**
	 * Normalize and validate a site URL.
	 *
	 * @return string|WP_Error
	 */
	private function normalize_url( string $url ) {
		$validated = $this->url_validator->validate( $url );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return untrailingslashit( $validated );
	}

	/**
	 * Cache only public status data, never credentials.
	 *
	 * @param array<string,mixed> $status Agent status.
	 */
	private function cache_status( Site $site, array $status ): void {
		set_transient( 'odm_status_' . $site->uuid(), $status, DAY_IN_SECONDS );
	}
}
