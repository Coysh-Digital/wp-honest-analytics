<?php
/**
 * The contract every third-party integration meets.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One plugin this one knows how to listen to.
 *
 * An integration is deliberately small. It answers whether its plugin is here,
 * attaches one or two hooks, and turns what those hooks hand it into a name and
 * sometimes a number. It never reads a submitted value, and there is no method
 * on this interface that would let it.
 */
interface IntegrationInterface {

	/**
	 * A stable slug.
	 *
	 * Used in event names and in the disabled-integrations setting, so it is
	 * part of the stored data and cannot be renamed casually.
	 */
	public function slug(): string;

	/**
	 * The plugin's name, as its authors write it.
	 */
	public function name(): string;

	/**
	 * Whether the plugin is present and active.
	 */
	public function isInstalled(): bool;

	/**
	 * Its version, or an empty string when it does not say.
	 */
	public function version(): string;

	/**
	 * Attach the hooks.
	 *
	 * Only called for an integration that is installed and switched on, so an
	 * implementation does not have to check either again.
	 */
	public function register(): void;

	/**
	 * A human label for one of this integration's event names.
	 *
	 * Event names are stable handles - `form:cf7-5` - because a goal has to
	 * survive somebody renaming a form. This turns a handle back into
	 * something a person can read, at the moment of display, by asking the
	 * plugin what the form is called now.
	 *
	 * @param string $eventName The stored event name.
	 *
	 * @return string|null The label, or null when this integration did not
	 *                     produce that name.
	 */
	public function describeEvent( string $eventName ): ?string;
}
