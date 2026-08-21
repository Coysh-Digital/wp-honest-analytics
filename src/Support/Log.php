<?php
/**
 * Logging.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics must never break the page it is measuring, so every failure in the
 * capture path ends here rather than as an exception. Nothing sensitive is ever
 * passed in: no IP addresses, no full user agents, no request bodies.
 */
final class Log {

	/**
	 * Log a warning.
	 *
	 * @param string $message Message.
	 */
	public static function warning( string $message ): void {
		self::write( 'warning', $message );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message Message.
	 */
	public static function error( string $message ): void {
		self::write( 'error', $message );
	}

	/**
	 * Write a line, if logging is on.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 */
	private static function write( string $level, string $message ): void {
		$enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;

		/**
		 * Filters whether Honest Analytics writes to the PHP error log.
		 *
		 * @param bool   $enabled Whether to log. Defaults to WP_DEBUG.
		 * @param string $level   'warning' or 'error'.
		 * @param string $message The message.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$enabled = (bool) apply_filters( 'honest_analytics_log', $enabled, $level, $message );
		}

		if ( ! $enabled ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[honest-analytics] %s: %s', $level, $message ) );
	}
}
