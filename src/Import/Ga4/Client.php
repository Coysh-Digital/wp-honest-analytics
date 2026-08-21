<?php
/**
 * Talking to Google.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages thrown here are technical detail for the log and are never printed; Ga4Exception::friendly() is what any screen shows, and escaping a log line would corrupt the only copy of the diagnostic.

/**
 * The only thing in the plugin that makes a request to Google.
 *
 * Two APIs: the Admin API to find out which properties the person can see, and
 * the Data API to read reports out of the one they chose. Read-only on both,
 * because the scope is read-only and there is nothing here that would want more.
 *
 * The transport is injectable so the tests can feed recorded responses. Nothing
 * in the suite ever touches the live API: a test that depends on somebody's
 * Google account is a test that fails on a Tuesday for reasons nobody can
 * reproduce.
 */
final class Client {

	/**
	 * The only scope asked for.
	 *
	 * Read-only, and analytics only. There is a broader `analytics` scope and a
	 * `analytics.edit` scope; this plugin has no business with either, and
	 * asking for them would put a frightening consent screen in front of
	 * somebody for capabilities it will never use.
	 */
	public const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

	private const ADMIN_BASE = 'https://analyticsadmin.googleapis.com/v1beta';
	private const DATA_BASE  = 'https://analyticsdata.googleapis.com/v1beta';

	/** One quick retry for a blip. Anything more belongs to the batch runner. */
	private const TRANSIENT_RETRIES = 1;

	/**
	 * @var callable|null
	 */
	private $transport;

	/**
	 * @var callable|null
	 */
	private $tokenResolver;

	/**
	 * @param Ga4ProviderInterface $provider      Where the token comes from.
	 * @param callable|null        $transport     Swapped in by the tests; signature (method, url, body, token) returning status and body.
	 * @param callable|null        $tokenResolver Swapped in by the tests, so that no stored connection is needed.
	 */
	public function __construct(
		private Ga4ProviderInterface $provider,
		?callable $transport = null,
		?callable $tokenResolver = null
	) {
		$this->transport     = $transport;
		$this->tokenResolver = $tokenResolver;
	}

	/**
	 * Every GA4 property this connection can see.
	 *
	 * One request, or a few if the account list is long. Deliberately does not
	 * fetch each property's timezone and website: that is a request per
	 * property, and a screen that makes forty of them to draw a list is a
	 * screen nobody waits for. The detail is fetched for the one that gets
	 * chosen, on the step where it matters.
	 *
	 * @return Property[]
	 *
	 * @throws Ga4Exception When Google will not answer.
	 */
	public function properties(): array {
		$properties = [];
		$pageToken  = '';

		do {
			$url = self::ADMIN_BASE . '/accountSummaries?pageSize=200';

			if ( '' !== $pageToken ) {
				$url .= '&pageToken=' . rawurlencode( $pageToken );
			}

			$body = $this->request( 'GET', $url );

			foreach ( (array) ( $body['accountSummaries'] ?? [] ) as $summary ) {
				$account = isset( $summary['displayName'] ) ? (string) $summary['displayName'] : '';

				foreach ( (array) ( $summary['propertySummaries'] ?? [] ) as $property ) {
					if ( ! is_array( $property ) || ! isset( $property['property'] ) ) {
						continue;
					}

					$properties[] = Property::fromSummary( $property, $account );
				}
			}

			$pageToken = isset( $body['nextPageToken'] ) ? (string) $body['nextPageToken'] : '';
		} while ( '' !== $pageToken && count( $properties ) < 500 );

		return $properties;
	}

	/**
	 * One property's timezone, currency and website.
	 *
	 * Two requests: the property itself, then its data streams for the website
	 * address. The second is allowed to fail quietly - a property with no web
	 * stream is unusual but legal, and it is not a reason to block an import.
	 *
	 * @param string $name `properties/123456789`.
	 *
	 * @throws Ga4Exception When the property cannot be read.
	 */
	public function propertyDetail( string $name ): Property {
		$body = $this->request( 'GET', self::ADMIN_BASE . '/' . ltrim( $name, '/' ) );

		$property = new Property(
			isset( $body['name'] ) ? (string) $body['name'] : $name,
			isset( $body['displayName'] ) ? (string) $body['displayName'] : '',
			isset( $body['parent'] ) ? (string) $body['parent'] : '',
			'',
			isset( $body['timeZone'] ) ? (string) $body['timeZone'] : '',
			isset( $body['currencyCode'] ) ? (string) $body['currencyCode'] : ''
		);

		try {
			$streams = $this->request( 'GET', self::ADMIN_BASE . '/' . ltrim( $name, '/' ) . '/dataStreams?pageSize=50' );

			foreach ( (array) ( $streams['dataStreams'] ?? [] ) as $stream ) {
				$uri = $stream['webStreamData']['defaultUri'] ?? '';

				if ( is_string( $uri ) && '' !== $uri ) {
					return $property->withDetail( $uri, $property->timezone, $property->currency );
				}
			}
		} catch ( Ga4Exception $e ) {
			// A missing website is cosmetic. Carry on with what we have.
			unset( $e );
		}

		return $property;
	}

	/**
	 * Run one report.
	 *
	 * @param string                         $property   `properties/123456789`.
	 * @param string[]                       $dimensions Dimension names.
	 * @param string[]                       $metrics    Metric names.
	 * @param string                         $from       Y-m-d.
	 * @param string                         $to         Y-m-d.
	 * @param int                            $limit      Rows per page.
	 * @param int                            $offset     Rows already read.
	 * @param array<int,array<string,mixed>> $orderBys Ordering, so that a truncated report keeps the rows worth keeping.
	 *
	 * @return array{rows:array<int,array{dimensions:string[],metrics:string[]}>,rowCount:int,quota:array<string,mixed>}
	 *
	 * @throws Ga4Exception When Google will not answer.
	 */
	public function runReport(
		string $property,
		array $dimensions,
		array $metrics,
		string $from,
		string $to,
		int $limit = 100000,
		int $offset = 0,
		array $orderBys = []
	): array {
		$request = [
			'dateRanges'          => [
				[
					'startDate' => $from,
					'endDate'   => $to,
				],
			],
			'dimensions'          => array_map( static fn ( string $d ): array => [ 'name' => $d ], $dimensions ),
			'metrics'             => array_map( static fn ( string $m ): array => [ 'name' => $m ], $metrics ),
			'limit'               => $limit,
			'offset'              => $offset,
			// Empty rows are days with nothing on them. There is no row to
			// write for those, and asking for them makes every response
			// larger for no gain.
			'keepEmptyRows'       => false,
			'returnPropertyQuota' => true,
		];

		if ( [] !== $orderBys ) {
			$request['orderBys'] = $orderBys;
		}

		$body = $this->request(
			'POST',
			self::DATA_BASE . '/' . ltrim( $property, '/' ) . ':runReport',
			$request
		);

		$rows = [];

		foreach ( (array) ( $body['rows'] ?? [] ) as $row ) {
			$rows[] = [
				'dimensions' => array_map(
					static fn ( $value ): string => is_array( $value ) && isset( $value['value'] ) ? (string) $value['value'] : '',
					(array) ( $row['dimensionValues'] ?? [] )
				),
				'metrics'    => array_map(
					static fn ( $value ): string => is_array( $value ) && isset( $value['value'] ) ? (string) $value['value'] : '0',
					(array) ( $row['metricValues'] ?? [] )
				),
			];
		}

		return [
			'rows'     => $rows,
			'rowCount' => isset( $body['rowCount'] ) ? (int) $body['rowCount'] : count( $rows ),
			'quota'    => is_array( $body['propertyQuota'] ?? null ) ? $body['propertyQuota'] : [],
		];
	}

	/**
	 * A request, with the token attached and the failure modes sorted out.
	 *
	 * @param string                   $method GET or POST.
	 * @param string                   $url    Absolute URL.
	 * @param array<string,mixed>|null $body   JSON body, for POST.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws Ga4Exception On anything that is not a 200 with JSON.
	 */
	private function request( string $method, string $url, ?array $body = null ): array {
		$attempt = 0;

		while ( true ) {
			$token    = null !== $this->tokenResolver
				? (string) ( $this->tokenResolver )( $this->provider )
				: TokenStore::accessToken( $this->provider );
			$response = $this->send( $method, $url, $body, $token );
			$status   = (int) ( $response['status'] ?? 0 );
			$decoded  = json_decode( (string) ( $response['body'] ?? '' ), true );
			$decoded  = is_array( $decoded ) ? $decoded : [];

			if ( 200 === $status ) {
				return $decoded;
			}

			$detail = isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '';
			$reason = isset( $decoded['error']['status'] ) ? (string) $decoded['error']['status'] : '';

			if ( 401 === $status ) {
				throw new Ga4Exception( Ga4Exception::AUTH, 'Google returned 401: ' . $detail );
			}

			// 403 is two different things wearing one status. RESOURCE_EXHAUSTED
			// is a quota that will refill; anything else is a permission the
			// account genuinely does not have, and waiting will not fix it.
			if ( 403 === $status ) {
				if ( 'RESOURCE_EXHAUSTED' === $reason || str_contains( strtolower( $detail ), 'quota' ) ) {
					throw new Ga4Exception( Ga4Exception::RATE_LIMIT, 'Google quota exhausted: ' . $detail, $this->retryAfter( $response ) );
				}

				throw new Ga4Exception( Ga4Exception::FATAL, 'Google returned 403: ' . $detail );
			}

			if ( 429 === $status ) {
				throw new Ga4Exception( Ga4Exception::RATE_LIMIT, 'Google returned 429: ' . $detail, $this->retryAfter( $response ) );
			}

			if ( $status >= 500 || 0 === $status ) {
				++$attempt;

				if ( $attempt > self::TRANSIENT_RETRIES ) {
					throw new Ga4Exception( Ga4Exception::TRANSIENT, 'Google returned ' . $status . ': ' . $detail );
				}

				continue;
			}

			throw new Ga4Exception( Ga4Exception::FATAL, 'Google returned ' . $status . ': ' . $detail );
		}
	}

	/**
	 * How long Google asked us to wait, when it said.
	 *
	 * @param array<string,mixed> $response The transport's answer.
	 */
	private function retryAfter( array $response ): int {
		$header = $response['retryAfter'] ?? 0;

		return is_numeric( $header ) ? max( 0, (int) $header ) : 0;
	}

	/**
	 * The actual HTTP, or whatever was injected in its place.
	 *
	 * @param string                   $method GET or POST.
	 * @param string                   $url    Absolute URL.
	 * @param array<string,mixed>|null $body   JSON body.
	 * @param string                   $token  Access token.
	 *
	 * @return array{status:int,body:string,retryAfter?:int}
	 */
	private function send( string $method, string $url, ?array $body, string $token ): array {
		if ( null !== $this->transport ) {
			return ( $this->transport )( $method, $url, $body, $token );
		}

		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
		];

		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return [
				'status' => 0,
				'body'   => '',
			];
		}

		return [
			'status'     => (int) wp_remote_retrieve_response_code( $response ),
			'body'       => (string) wp_remote_retrieve_body( $response ),
			'retryAfter' => (int) wp_remote_retrieve_header( $response, 'retry-after' ),
		];
	}
}
