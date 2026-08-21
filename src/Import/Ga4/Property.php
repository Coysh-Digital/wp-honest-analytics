<?php
/**
 * A GA4 property, as somebody would recognise it.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import\Ga4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One property, named the way its owner thinks of it.
 *
 * Nobody knows their property id. They know "example.com", and they know which
 * of the four accounts their agency set up is the live one. So the id is
 * carried, because the API needs it, and shown last.
 */
final class Property {

	/**
	 * @param string $name        The API name, `properties/123456789`.
	 * @param string $displayName What the property is called in Google Analytics.
	 * @param string $account     The account it sits under.
	 * @param string $url         The website of its web stream, where there is one.
	 * @param string $timezone    The property's reporting timezone.
	 * @param string $currency    Its reporting currency.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $displayName,
		public readonly string $account = '',
		public readonly string $url = '',
		public readonly string $timezone = '',
		public readonly string $currency = ''
	) {
	}

	/**
	 * The numeric half of the API name.
	 */
	public function id(): string {
		return str_replace( 'properties/', '', $this->name );
	}

	/**
	 * @return array<string,string>
	 */
	public function toArray(): array {
		return [
			'name'        => $this->name,
			'displayName' => $this->displayName,
			'account'     => $this->account,
			'url'         => $this->url,
			'timezone'    => $this->timezone,
			'currency'    => $this->currency,
		];
	}

	/**
	 * Rebuild an `accountSummaries` property summary.
	 *
	 * @param array<string,mixed> $data    One propertySummary.
	 * @param string              $account The parent account's display name.
	 */
	public static function fromSummary( array $data, string $account = '' ): self {
		return new self(
			isset( $data['property'] ) ? (string) $data['property'] : '',
			isset( $data['displayName'] ) ? (string) $data['displayName'] : '',
			$account
		);
	}

	/**
	 * A copy with the details only the property endpoint knows.
	 *
	 * @param string $url      Stream URL.
	 * @param string $timezone Reporting timezone.
	 * @param string $currency Reporting currency.
	 */
	public function withDetail( string $url, string $timezone, string $currency ): self {
		return new self( $this->name, $this->displayName, $this->account, $url, $timezone, $currency );
	}
}
