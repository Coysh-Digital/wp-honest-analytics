<?php
/**
 * Settings sanitization and validation.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize callback for the settings option.
 *
 * Two jobs. First, meaning-specific sanitization: a path pattern, a cookie name
 * and a retention window are not "text", and treating them as such is how a
 * settings screen ends up storing something the pipeline then has to defend
 * against. Second, the merge: the settings screen posts one tab at a time, so
 * the incoming array is folded over the stored one rather than replacing it -
 * without that, saving Tracking would silently reset Retention to defaults.
 */
final class Sanitizer {

	/**
	 * Sanitize an incoming settings array.
	 *
	 * @param mixed $input Raw $_POST value.
	 *
	 * @return array<string,mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$stored   = get_option( SettingsRepository::OPTION, [] );
		$stored   = is_array( $stored ) ? $stored : [];
		$existing = array_merge( Settings::defaults(), $stored );

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$defaults = Settings::defaults();
		$errors   = [];
		$out      = $existing;

		// Checkboxes that are absent from the post are off - but only for the
		// group that was actually submitted, which the form declares.
		$submitted = isset( $input['_submitted'] ) && is_string( $input['_submitted'] )
			? array_filter( array_map( 'trim', explode( ',', $input['_submitted'] ) ) )
			: [];

		foreach ( $submitted as $key ) {
			if ( isset( $defaults[ $key ] ) && is_bool( $defaults[ $key ] ) && ! isset( $input[ $key ] ) ) {
				$input[ $key ] = false;
			}
		}

		unset( $input['_submitted'] );

		foreach ( $input as $key => $value ) {
			$key = (string) $key;

			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			$clean = self::field( $key, $value, $defaults[ $key ], $errors );

			if ( null !== $clean ) {
				$out[ $key ] = $clean;
			}
		}

		$out = self::crossFieldRules( $out, $errors );

		foreach ( $errors as $code => $message ) {
			add_settings_error( SettingsRepository::OPTION, (string) $code, $message, 'error' );
		}

		if ( [] === $errors && function_exists( 'get_settings_errors' ) && [] === get_settings_errors( SettingsRepository::OPTION ) ) {
			add_settings_error(
				SettingsRepository::OPTION,
				'saved',
				__( 'Settings saved.', 'honest-analytics' ),
				'success'
			);
		}

		SettingsRepository::flush();

		return $out;
	}

	/**
	 * Sanitize one field.
	 *
	 * @param string               $key      Field name.
	 * @param mixed                $value    Raw value.
	 * @param mixed                $default  Default, used for its type.
	 * @param array<string,string> $errors   Collected errors, by reference.
	 *
	 * @return mixed Sanitized value, or null to leave the stored one alone.
	 */
	private static function field( string $key, mixed $value, mixed $default, array &$errors ): mixed {
		switch ( $key ) {
			case 'trackingMode':
				return self::choice( $value, [ Settings::TRACKING_HYBRID, Settings::TRACKING_SERVER, Settings::TRACKING_CLIENT ], $default );

			case 'writeDriver':
				return self::choice( $value, [ Settings::WRITE_AUTO, Settings::WRITE_SPOOL, Settings::WRITE_DB, Settings::WRITE_DIRECT ], $default );

			case 'collectEndpoint':
				return self::choice( $value, [ Settings::ENDPOINT_REST, Settings::ENDPOINT_PLAIN ], $default );

			case 'sessionStore':
				return self::choice( $value, [ Settings::STORE_AUTO, Settings::STORE_CACHE, Settings::STORE_DB ], $default );

			case 'uniqueCounterDriver':
				return self::choice( $value, [ Settings::UNIQUES_HLL, Settings::UNIQUES_EXACT ], $default );

			case 'attributionModel':
				return self::choice( $value, [ 'last-click', 'first-click', 'linear' ], $default );

			case 'reportPeriod':
				return self::choice( $value, [ 'today', 'yesterday', '7d', '30d', '90d', '12mo' ], $default );

			case 'ipSource':
				return self::choice( $value, [ 'auto', 'remote_addr', 'cf_connecting_ip', 'x_forwarded_for', 'x_real_ip' ], $default );

			case 'sessionWindow':
				return self::int( $value, 60, 14400, $default );

			case 'nonceTtl':
				return self::int( $value, 60, 86400, $default );

			case 'beaconRateLimit':
				return self::int( $value, 1, 100000, $default );

			case 'saltRotationInterval':
				$seconds = self::int( $value, 3600, 31536000, $default );

				if ( $seconds > 86400 ) {
					$errors['saltRotationInterval'] = __( 'Salt rotation longer than 24 hours lengthens how long a visitor stays re-identifiable, and weakens the case that the retained data is anonymous. The value has been saved, and the Privacy screen now carries a warning.', 'honest-analytics' );
				}

				return $seconds;

			case 'saltRotationHour':
				return self::int( $value, 0, 23, $default );

			case 'hourlyWindowDays':
				return self::int( $value, 1, 90, $default );

			case 'dimensionCap':
				return self::int( $value, 10, 100000, $default );

			case 'rollupRetentionMonths':
				return self::int( $value, 1, Settings::ROLLUP_MAX_RETENTION_MONTHS, $default );

			case 'spoolMaxBytes':
				return self::int( $value, 1048576, PHP_INT_MAX, $default );

			case 'hllPrecision':
				return self::int( $value, 11, 14, $default );

			case 'journeyRetentionDays':
				$days = self::int( $value, 1, Settings::JOURNEY_MAX_RETENTION_DAYS, $default );

				if ( is_numeric( $value ) && (int) $value > Settings::JOURNEY_MAX_RETENTION_DAYS ) {
					$errors['journeyRetentionDays'] = __( 'Raw journeys may not be kept longer than 26 months. The value has been capped.', 'honest-analytics' );
				}

				return $days;

			case 'consentLogRetentionDays':
				return self::int( $value, 0, 36500, $default );

			case 'consentCookieDuration':
				$duration = self::int( $value, 3600, Settings::CONSENT_COOKIE_MAX_DURATION, $default );

				if ( is_numeric( $value ) && (int) $value > Settings::CONSENT_COOKIE_MAX_DURATION ) {
					$errors['consentCookieDuration'] = __( 'A consented visitor cookie may not outlive 24 months. The value has been capped.', 'honest-analytics' );
				}

				return $duration;

			case 'consentCookieName':
				$name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
				$name = is_string( $name ) ? substr( $name, 0, 64 ) : '';

				if ( '' === $name ) {
					$errors['consentCookieName'] = __( 'The consent cookie needs a name made of letters, numbers, hyphens or underscores.', 'honest-analytics' );

					return null;
				}

				return $name;

			case 'cmpCookieName':
				$name = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );

				return is_string( $name ) ? substr( $name, 0, 64 ) : '';

			case 'policyVersion':
				$version = sanitize_text_field( (string) $value );
				$version = substr( $version, 0, 32 );

				return '' !== $version ? $version : null;

			case 'licenceKey':
				return substr( trim( sanitize_text_field( (string) $value ) ), 0, 128 );

			case 'siteSearchParam':
				$param = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
				$param = is_string( $param ) ? substr( $param, 0, 64 ) : '';

				return '' !== $param ? $param : null;

			case 'excludePaths':
				return self::pathList( $value );

			case 'excludeQueryParams':
				return self::paramList( $value );

			case 'downloadExtensions':
				return self::extensionList( $value );

			case 'cmpCookieGrantedValues':
				return Settings::toList( $value );

			case 'trustedProxies':
				return self::cidrList( $value, $errors );

			case 'reportRecipients':
				return self::emailList( $value, $errors );

			case 'disabledIntegrations':
				return self::slugList( $value );
		}

		if ( is_bool( $default ) ) {
			return Settings::toBool( $value );
		}

		if ( is_int( $default ) ) {
			return is_numeric( $value ) ? (int) $value : null;
		}

		if ( is_array( $default ) ) {
			return Settings::toList( $value );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Rules that need more than one field to decide.
	 *
	 * @param array<string,mixed>  $values Settings.
	 * @param array<string,string> $errors Collected errors, by reference.
	 *
	 * @return array<string,mixed>
	 */
	private static function crossFieldRules( array $values, array &$errors ): array {
		// Plain permalinks put the page's identity in the query string, so
		// collapsing it would collapse every page onto one row.
		if ( ! empty( $values['stripQueryString'] ) && '' === (string) get_option( 'permalink_structure', '' ) ) {
			$values['stripQueryString'] = false;

			$errors['stripQueryString'] = __( 'This site uses plain permalinks, so the query string is the page. Stripping it would collapse every page onto one row, and the setting has been left off.', 'honest-analytics' );
		}

		if ( ! empty( $values['enableConsent'] ) && '' === trim( (string) ( $values['consentCookieName'] ?? '' ) ) ) {
			$values['consentCookieName'] = '_ha_vid';
		}

		if ( empty( $values['enableEvents'] ) ) {
			// The master switch means what it says: with events off, nothing
			// that rides the events beacon is recorded either.
			$values['trackOutbound']  = false;
			$values['trackDownloads'] = false;
			$values['trackScroll']    = false;
		}

		return $values;
	}

	/**
	 * A value from a fixed set.
	 *
	 * @param mixed    $value   Raw value.
	 * @param string[] $allowed Allowed values.
	 * @param mixed    $default Fallback.
	 */
	private static function choice( mixed $value, array $allowed, mixed $default ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return in_array( $value, $allowed, true ) ? $value : (string) $default;
	}

	/**
	 * A clamped integer.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $min     Minimum.
	 * @param int   $max     Maximum.
	 * @param mixed $default Fallback.
	 */
	private static function int( mixed $value, int $min, int $max, mixed $default ): int {
		if ( ! is_numeric( $value ) ) {
			return (int) $default;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Path glob patterns.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[]
	 */
	private static function pathList( mixed $value ): array {
		$out = [];

		foreach ( Settings::toList( $value ) as $pattern ) {
			$pattern = wp_strip_all_tags( $pattern );
			$pattern = str_replace( [ "\r", "\n", "\0" ], '', $pattern );

			if ( '' === $pattern ) {
				continue;
			}

			if ( ! str_starts_with( $pattern, '/' ) && ! str_starts_with( $pattern, '*' ) ) {
				$pattern = '/' . $pattern;
			}

			$out[] = substr( $pattern, 0, 500 );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Query parameter names, minus the ones WordPress needs to identify a page.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[]
	 */
	private static function paramList( mixed $value ): array {
		$reserved = [ 'p', 'page_id', 'cat', 'tag', 'paged', 'post_type', 's', 'attachment_id', 'author', 'name', 'pagename', 'feed' ];
		$out      = [];

		foreach ( Settings::toList( $value ) as $param ) {
			$param = preg_replace( '/[^A-Za-z0-9_\-\[\]]/', '', $param );

			if ( ! is_string( $param ) || '' === $param || in_array( strtolower( $param ), $reserved, true ) ) {
				continue;
			}

			$out[] = substr( $param, 0, 64 );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * File extensions, without the dot.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[]
	 */
	private static function extensionList( mixed $value ): array {
		$out = [];

		foreach ( Settings::toList( $value ) as $extension ) {
			$extension = strtolower( ltrim( trim( $extension ), '.' ) );
			$extension = preg_replace( '/[^a-z0-9]/', '', $extension );

			if ( is_string( $extension ) && '' !== $extension ) {
				$out[] = substr( $extension, 0, 12 );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Integration slugs.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[]
	 */
	private static function slugList( mixed $value ): array {
		$out = [];

		foreach ( Settings::toList( $value ) as $slug ) {
			$slug = sanitize_key( trim( $slug ) );

			if ( '' !== $slug ) {
				$out[] = substr( $slug, 0, 40 );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * CIDR ranges or bare addresses.
	 *
	 * @param mixed                $value  Raw value.
	 * @param array<string,string> $errors Collected errors, by reference.
	 *
	 * @return string[]
	 */
	private static function cidrList( mixed $value, array &$errors ): array {
		$out = [];

		foreach ( Settings::toList( $value ) as $range ) {
			$candidate = $range;

			if ( str_contains( $range, '/' ) ) {
				[ $address, $bits ] = explode( '/', $range, 2 );
				$candidate          = $address;

				if ( ! ctype_digit( $bits ) ) {
					$errors['trustedProxies'] = __( 'Trusted proxies must be an IP address or a CIDR range, one per line.', 'honest-analytics' );

					continue;
				}
			}

			if ( false === filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$errors['trustedProxies'] = __( 'Trusted proxies must be an IP address or a CIDR range, one per line.', 'honest-analytics' );

				continue;
			}

			$out[] = $range;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Email addresses, allowing an environment placeholder through untouched.
	 *
	 * @param mixed                $value  Raw value.
	 * @param array<string,string> $errors Collected errors, by reference.
	 *
	 * @return string[]
	 */
	private static function emailList( mixed $value, array &$errors ): array {
		$out = [];

		foreach ( Settings::toList( $value ) as $email ) {
			if ( str_starts_with( $email, '$' ) ) {
				$out[] = $email;

				continue;
			}

			$clean = sanitize_email( $email );

			if ( '' === $clean || ! is_email( $clean ) ) {
				$errors['reportRecipients'] = __( 'Report recipients must be email addresses, one per line.', 'honest-analytics' );

				continue;
			}

			$out[] = $clean;
		}

		return array_values( array_unique( $out ) );
	}
}
