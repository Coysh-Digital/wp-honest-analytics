<?php
/**
 * Loopback checks.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Scheduling;

use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\KeyValueStoreInterface;
use HonestAnalytics\Store\StoreFactory;
use HonestAnalytics\Support\Log;
use HonestAnalytics\Support\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two things the site can only learn by asking itself.
 *
 * Whether the collection endpoint answers, and whether the write spool is
 * readable over the web. Both are configuration facts that no amount of PHP
 * introspection settles: a security plugin can disable the REST API without
 * telling anybody, and the `.htaccess` written at activation does nothing at
 * all on nginx.
 *
 * Both requests go to this site and nowhere else, and neither leaves the
 * server it runs on. Nothing in the measuring path makes an outbound request
 * at all; the only calls that reach a third party are the ones an
 * administrator starts - a Google Analytics import, or a geo database fetched
 * from an address they typed.
 */
final class Loopback {

	/** Where the verdict is cached. */
	private const KEY = 'loopback';

	/** How long a verdict stands. Configuration does not change hourly. */
	private const TTL = DAY_IN_SECONDS;

	/** The spool is protected. */
	public const SPOOL_PROTECTED = 'protected';

	/** The spool is readable by anybody who knows the filename. */
	public const SPOOL_PUBLIC = 'public';

	/** The check could not be completed, so nothing is claimed either way. */
	public const SPOOL_UNKNOWN = 'unknown';

	private KeyValueStoreInterface $store;

	/**
	 * @param Settings                    $settings Settings.
	 * @param KeyValueStoreInterface|null $store    Store.
	 */
	public function __construct(
		private Settings $settings,
		?KeyValueStoreInterface $store = null
	) {
		$this->store = $store ?? StoreFactory::keyValue();
	}

	/**
	 * The cached verdict, running the checks if there is not one.
	 *
	 * @param bool $force Run the checks even if a verdict is cached.
	 *
	 * @return array{spool:string,endpoint:bool,checkedAt:int}
	 */
	public function result( bool $force = false ): array {
		if ( ! $force ) {
			$cached = $this->store->get( self::KEY );

			if ( is_array( $cached ) && isset( $cached['spool'], $cached['endpoint'] ) ) {
				return [
					'spool'     => (string) $cached['spool'],
					'endpoint'  => (bool) $cached['endpoint'],
					'checkedAt' => (int) ( $cached['checkedAt'] ?? 0 ),
				];
			}
		}

		$result = [
			'spool'     => $this->checkSpool(),
			'endpoint'  => $this->checkEndpoint(),
			'checkedAt' => time(),
		];

		$this->store->set( self::KEY, $result, self::TTL );

		return $result;
	}

	/**
	 * Whether a file in the spool directory can be fetched over the web.
	 *
	 * Writes a probe with a random token in it, asks for it, and deletes it.
	 * The extension is deliberately not the one the drain looks for, so a probe
	 * left behind by a fatal cannot become a malformed batch.
	 */
	private function checkSpool(): string {
		$directory = Paths::spoolDir();

		if ( ! is_dir( $directory ) || ! wp_is_writable( $directory ) ) {
			return self::SPOOL_UNKNOWN;
		}

		$url = Paths::spoolUrl();

		if ( '' === $url ) {
			// The data directory has been moved outside the web root, which is
			// the strongest protection there is. Nothing to check.
			return self::SPOOL_PROTECTED;
		}

		$this->sweepProbes( $directory );

		$token = wp_generate_password( 24, false );
		$name  = 'probe-' . $token . '.txt';
		$path  = $directory . '/' . $name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === @file_put_contents( $path, $token ) ) {
			return self::SPOOL_UNKNOWN;
		}

		$response = wp_remote_get(
			$url . '/' . $name,
			[
				'timeout'   => 5,
				// `local` marks this as a request to ourselves, which is what
				// makes core apply the `https_local_ssl_verify` filter. Without
				// it a self-request is treated as external, and a staging site
				// with a self-signed certificate fails this probe and reports a
				// broken collection endpoint that is not broken - with no way
				// to say so.
				'local'     => true,
				'sslverify' => true,
				'headers'   => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		wp_delete_file( $path );

		if ( is_wp_error( $response ) ) {
			// A failed request is not evidence of safety. Say so rather than
			// reporting a clean bill of health nobody checked.
			return self::SPOOL_UNKNOWN;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return str_contains( $body, $token ) ? self::SPOOL_PUBLIC : self::SPOOL_PROTECTED;
	}

	/**
	 * Delete probes an earlier check did not live to delete.
	 *
	 * The probe is written, fetched and unlinked in one request, so the only
	 * way one survives is a fatal or a `max_execution_time` kill between the
	 * write and the delete - which is exactly what a site slow enough to be
	 * worth probing does. Nothing else looks at these files, so they would
	 * accumulate one per failed check, forever, in the directory this check
	 * exists to say is protected.
	 *
	 * An hour, because a probe still in flight belongs to a request that has
	 * long since hit any plausible time limit, and deleting one out from under
	 * a live check would only make it report `unknown`.
	 *
	 * @param string $directory Spool directory.
	 */
	private function sweepProbes( string $directory ): void {
		$stale = time() - HOUR_IN_SECONDS;

		foreach ( (array) glob( $directory . '/probe-*.txt' ) as $file ) {
			if ( ! is_string( $file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$modified = @filemtime( $file );

			if ( false !== $modified && $modified < $stale ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Whether the collection endpoint answers.
	 *
	 * Sends a request the endpoint will reject - no path - because the point is
	 * to learn whether it is reachable, not to count a visit. Every answer from
	 * it is 204 regardless, so the status code is the whole test.
	 */
	private function checkEndpoint(): bool {
		$url = Settings::ENDPOINT_PLAIN === $this->settings->collectEndpoint
			? home_url( '/?honest-analytics=collect' )
			: rest_url( 'honest-analytics/v1/collect' );

		$response = wp_remote_post(
			$url,
			[
				'timeout'   => 5,
				'local'     => true,
				'sslverify' => true,
				'body'      => [ 'p' => '' ],
				'headers'   => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			Log::warning( 'Loopback to the collection endpoint failed: ' . $response->get_error_message() );

			return false;
		}

		return 204 === (int) wp_remote_retrieve_response_code( $response );
	}
}
