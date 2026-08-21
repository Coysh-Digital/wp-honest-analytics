<?php
/**
 * Asking optimisers to leave the tracker alone.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Integrations;

use HonestAnalytics\Capture\ScriptInjector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the tracker with every optimiser that has an exclusion list.
 *
 * The script tag already carries `data-no-optimize`, `data-no-minify`,
 * `data-no-defer` and `data-cfasync="false"`, which most tools honour. These
 * filters are the other half: the tools that keep their exclusions in settings
 * rather than reading attributes, and the configurations where an administrator
 * has turned on "delay all JavaScript" without realising what it applies to.
 *
 * Delayed JavaScript is the one that matters. Combining or minifying the
 * tracker is harmless - it is thirteen hundred bytes and has no dependencies.
 * Holding it back until the visitor clicks something means a visitor who reads
 * and leaves is never counted, and the graph simply goes quiet.
 *
 * Nothing here fights the optimiser. Every filter is additive, every entry is
 * this plugin's own path, and a site that would rather have the tracker delayed
 * can remove any of them.
 */
final class OptimizerExclusions {

	/**
	 * The path fragment that identifies the tracker and its companions.
	 */
	private const PATH = 'honest-analytics/assets/js/';

	/**
	 * Attach the exclusions.
	 */
	public static function register(): void {
		foreach ( self::arrayFilters() as $filter ) {
			add_filter( $filter, [ self::class, 'addPaths' ] );
		}

		add_filter( 'autoptimize_filter_js_exclude', [ self::class, 'addToCommaSeparated' ] );
		add_filter( 'w3tc_minify_js_do_tag_minification', [ self::class, 'skipTagMinification' ], 10, 2 );
	}

	/**
	 * Add the tracker to an array-shaped exclusion list.
	 *
	 * @param mixed $excluded Existing exclusions.
	 *
	 * @return string[]
	 */
	public static function addPaths( mixed $excluded ): array {
		$excluded = is_array( $excluded ) ? $excluded : [];

		return array_values( array_unique( array_merge( $excluded, self::paths() ) ) );
	}

	/**
	 * Add the tracker to a comma-separated exclusion list.
	 *
	 * @param mixed $excluded Existing exclusions.
	 */
	public static function addToCommaSeparated( mixed $excluded ): string {
		$excluded = is_string( $excluded ) ? $excluded : '';

		return '' === $excluded ? self::PATH : $excluded . ',' . self::PATH;
	}

	/**
	 * Leave the tracker's tag alone.
	 *
	 * @param bool   $minify Whether to minify this tag.
	 * @param string $script The script tag.
	 */
	public static function skipTagMinification( bool $minify, string $script ): bool {
		return str_contains( $script, self::PATH ) ? false : $minify;
	}

	/**
	 * The paths and handles worth listing.
	 *
	 * Both forms, because some optimisers match on the URL and others on the
	 * registered handle.
	 *
	 * @return string[]
	 */
	private static function paths(): array {
		return [
			self::PATH,
			'tracker.js',
			ScriptInjector::HANDLE,
			ScriptInjector::HANDLE_PRO,
			ScriptInjector::HANDLE_CONSENT,
		];
	}

	/**
	 * The filters that take an array of exclusions.
	 *
	 * @return string[]
	 */
	private static function arrayFilters(): array {
		return [
			'rocket_exclude_js',
			'rocket_exclude_defer_js',
			'rocket_delay_js_exclusions',
			'rocket_minify_excluded_external_js',
			'litespeed_optimize_js_excludes',
			'litespeed_optm_js_defer_exc',
			'perfmatters_delay_js_exclusions',
			'perfmatters_defer_js_exclusions',
			'sgo_js_minify_exclude',
			'sgo_javascript_combine_exclude',
			'sgo_js_async_exclude',
		];
	}
}
