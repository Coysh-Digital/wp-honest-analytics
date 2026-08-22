<?php
/**
 * The importers this build has.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;

use HonestAnalytics\Import\Sources\IndependentAnalyticsImporter;
use HonestAnalytics\Import\Sources\WPStatisticsImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One list, in the order the import screen shows them.
 *
 * Classes are named rather than instantiated and each is checked before it is
 * built, so a build packaged without one - or a tree where one is still being
 * written - lists the rest instead of fataling. Detection is memoised for the
 * request because the import screen asks every importer whether it has anything,
 * and asking twice would double the queries for no new answer.
 */
final class ImporterRegistry {

	/**
	 * Built importers, keyed by id.
	 *
	 * @var array<string,ImporterInterface>|null
	 */
	private static ?array $importers = null;

	/**
	 * Detection results for this request, keyed by id.
	 *
	 * @var array<string,DetectionResult>
	 */
	private static array $detected = [];

	/**
	 * Every importer, in display order.
	 *
	 * @return array<string,ImporterInterface>
	 */
	public static function all(): array {
		if ( null !== self::$importers ) {
			return self::$importers;
		}

		$classes = [
			WPStatisticsImporter::class,
			IndependentAnalyticsImporter::class,
			// Named as a string: the GA4 importer lives in its own namespace and
			// a build without it should list the other two rather than fail to
			// load this file.
			'HonestAnalytics\\Import\\Sources\\Ga4Importer',
			// Search Console is Pro-only and stripped from Lite along with the
			// rest of src/Import/Gsc/, so the same string-name-and-class_exists()
			// treatment applies.
			'HonestAnalytics\\Import\\Sources\\GscImporter',
		];

		$built = [];

		foreach ( $classes as $class ) {
			// The list above is fixed and every name on it implements the
			// interface, so the only question worth asking is whether the class
			// is in this build at all. A package without one lists the others.
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$importer = new $class();

			$built[ $importer->id() ] = $importer;
		}

		/**
		 * Filters the analytics importers on offer.
		 *
		 * @param array<string,ImporterInterface> $importers Keyed by id.
		 */
		$filtered = apply_filters( 'honest_analytics_importers', $built );

		if ( ! is_array( $filtered ) ) {
			self::$importers = $built;

			return self::$importers;
		}

		// Whatever a filter returned is somebody else's code, so it is checked.
		$valid = [];

		foreach ( $filtered as $importer ) {
			if ( $importer instanceof ImporterInterface ) {
				$valid[ $importer->id() ] = $importer;
			}
		}

		self::$importers = $valid;

		return self::$importers;
	}

	/**
	 * One importer by id, or null.
	 *
	 * @param string $id Importer id.
	 */
	public static function get( string $id ): ?ImporterInterface {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Ask an importer whether it has anything, once per request.
	 *
	 * @param string $id Importer id.
	 */
	public static function detect( string $id ): DetectionResult {
		if ( isset( self::$detected[ $id ] ) ) {
			return self::$detected[ $id ];
		}

		$importer = self::get( $id );

		if ( null === $importer ) {
			self::$detected[ $id ] = DetectionResult::none();

			return self::$detected[ $id ];
		}

		try {
			self::$detected[ $id ] = $importer->detect();
		} catch ( \Throwable $e ) {
			// A source that cannot be inspected is not a broken admin screen.
			// It is one row on a list saying it could not be read.
			self::$detected[ $id ] = new DetectionResult(
				DetectionResult::UNSUPPORTED,
				__( 'This could not be checked. The data may be in a format this version does not recognise.', 'honest-analytics' )
			);
		}

		return self::$detected[ $id ];
	}

	/**
	 * Detection for every importer, keyed by id.
	 *
	 * @return array<string,DetectionResult>
	 */
	public static function detectAll(): array {
		$out = [];

		foreach ( array_keys( self::all() ) as $id ) {
			$out[ $id ] = self::detect( $id );
		}

		return $out;
	}

	/**
	 * Forget everything memoised. For tests.
	 */
	public static function flush(): void {
		self::$importers = null;
		self::$detected  = [];
	}
}
