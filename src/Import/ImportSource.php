<?php
/**
 * Where a figure came from.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The provenance values that appear in the rollup tables.
 *
 * Short, stable and lower case, because they are written into a `source` column
 * that is part of a unique key. Renaming one would orphan every row already
 * carrying it, so these strings are effectively permanent.
 */
final class ImportSource {

	/** Collected by this plugin, on this site. */
	public const NATIVE = 'native';

	public const WP_STATISTICS         = 'wp_statistics';
	public const INDEPENDENT_ANALYTICS = 'independent_analytics';
	public const GA4                   = 'ga4';

	/**
	 * Search Console. Not written by {@see ImportSink} - that writer's tables
	 * hold pages, sessions, sources, devices and countries, and Search Console
	 * query data has no place in any of them - but the string still belongs
	 * here, since it is what every job, coverage row and log line under this
	 * source is stamped with, and this is the one place that turns it into a
	 * name a person would recognise.
	 */
	public const GSC = 'gsc';

	/**
	 * Every source that can be imported from.
	 *
	 * @return string[]
	 */
	public static function importable(): array {
		return [ self::WP_STATISTICS, self::INDEPENDENT_ANALYTICS, self::GA4, self::GSC ];
	}

	/**
	 * The name a person would recognise.
	 *
	 * @param string $source One of the constants above.
	 */
	public static function label( string $source ): string {
		switch ( $source ) {
			case self::WP_STATISTICS:
				return __( 'WP Statistics', 'honest-analytics' );

			case self::INDEPENDENT_ANALYTICS:
				return __( 'Independent Analytics', 'honest-analytics' );

			case self::GA4:
				return __( 'Google Analytics', 'honest-analytics' );

			case self::GSC:
				return __( 'Google Search Console', 'honest-analytics' );

			case self::NATIVE:
				return __( 'Honest Analytics', 'honest-analytics' );
		}

		return $source;
	}

	/**
	 * Whether a string is a source this plugin knows about.
	 *
	 * @param string $source Candidate.
	 */
	public static function isValid( string $source ): bool {
		return self::NATIVE === $source || in_array( $source, self::importable(), true );
	}
}
