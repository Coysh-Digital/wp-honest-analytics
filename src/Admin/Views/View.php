<?php
/**
 * Template rendering.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Views;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain PHP templates.
 *
 * No template engine, because WordPress admin screens are already PHP and a
 * second syntax to learn buys nothing. Escaping happens at the point of output
 * in the template, which is where it can be checked by reading it.
 */
final class View {

	/**
	 * Render a template.
	 *
	 * @param string              $template Template path, relative to templates/.
	 * @param array<string,mixed> $vars     Variables.
	 */
	public static function render( string $template, array $vars = [] ): void {
		$path = HONEST_ANALYTICS_DIR . 'templates/' . ltrim( $template, '/' ) . '.php';

		if ( ! is_file( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );

		include $path;
	}

	/**
	 * Render a template to a string.
	 *
	 * @param string              $template Template path.
	 * @param array<string,mixed> $vars     Variables.
	 */
	public static function capture( string $template, array $vars = [] ): string {
		ob_start();

		self::render( $template, $vars );

		return (string) ob_get_clean();
	}
}
