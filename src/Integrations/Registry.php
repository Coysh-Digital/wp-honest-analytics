<?php
/**
 * Which integrations exist, which are here, and which are switched on.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Integrations;

use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The list of plugins this one knows how to listen to.
 *
 * Integrations are opt-out rather than opt-in. Somebody who installs Gravity
 * Forms on a site already running this plugin should get their form
 * conversions without first having to find a checkbox; the setting exists for
 * the rarer case of wanting one switched off.
 */
final class Registry {

	/**
	 * Every integration, whether or not its plugin is installed.
	 *
	 * @param Settings|null $settings Settings, or null to read them.
	 *
	 * @return IntegrationInterface[]
	 */
	public static function all( ?Settings $settings = null ): array {
		$settings = $settings ?? SettingsRepository::get();

		$built = [];

		// Named, then constructed only if the class is here. The free build is
		// packaged with these five files removed, and this list is reached from
		// detected() and label() as well as from register() - so an edition
		// check in one caller would still leave the other two fatal.
		foreach ( [ ContactForm7::class, GravityForms::class, WPForms::class, NinjaForms::class, WooCommerce::class ] as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$built[] = WooCommerce::class === $class ? new WooCommerce( $settings ) : new $class();
		}

		/**
		 * Filters the integrations Honest Analytics offers.
		 *
		 * @param IntegrationInterface[] $integrations The integrations.
		 * @param Settings               $settings     Current settings.
		 */
		$integrations = apply_filters( 'honest_analytics_integrations', $built, $settings );

		return array_values(
			array_filter(
				(array) $integrations,
				static fn ( mixed $integration ): bool => $integration instanceof IntegrationInterface
			)
		);
	}

	/**
	 * Attach the hooks for every integration that should be listening.
	 *
	 * Four things have to be true, and all four are cheap to check, so nothing
	 * is attached speculatively: this has to be the paid edition, integrations
	 * have to be on, this one must not have been switched off, and its plugin
	 * has to actually be here.
	 *
	 * @param Settings|null $settings Settings, or null to read them.
	 */
	public static function register( ?Settings $settings = null ): void {
		$settings = $settings ?? SettingsRepository::get();

		if ( ! Edition::isPro() || ! $settings->enableIntegrations ) {
			return;
		}

		foreach ( self::all( $settings ) as $integration ) {
			if ( ! $integration->isInstalled() || ! self::isEnabled( $settings, $integration->slug() ) ) {
				continue;
			}

			$integration->register();
		}
	}

	/**
	 * What is installed, for the Settings screen to list.
	 *
	 * Each row is:
	 *
	 *     [
	 *         'slug'      => 'gravityforms',
	 *         'name'      => 'Gravity Forms',
	 *         'version'   => '2.8.3',   // '' when the plugin does not say
	 *         'installed' => true,      // the plugin is active on this site
	 *         'enabled'   => true,      // not switched off in settings
	 *         'active'    => true,      // installed, enabled, Pro, and on
	 *     ]
	 *
	 * Sorted with the installed ones first, because a list of five plugins the
	 * site does not have is not a useful thing to read.
	 *
	 * @param Settings|null $settings Settings, or null to read them.
	 *
	 * @return array<int,array{slug:string,name:string,version:string,installed:bool,enabled:bool,active:bool}>
	 */
	public static function detected( ?Settings $settings = null ): array {
		$settings = $settings ?? SettingsRepository::get();
		$isPro    = Edition::isPro();
		$rows     = [];

		foreach ( self::all( $settings ) as $integration ) {
			$installed = $integration->isInstalled();
			$enabled   = self::isEnabled( $settings, $integration->slug() );

			$rows[] = [
				'slug'      => $integration->slug(),
				'name'      => $integration->name(),
				'version'   => $installed ? $integration->version() : '',
				'installed' => $installed,
				'enabled'   => $enabled,
				'active'    => $installed && $enabled && $isPro && $settings->enableIntegrations,
			];
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				if ( $a['installed'] !== $b['installed'] ) {
					return $a['installed'] ? -1 : 1;
				}

				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Whether one integration has been switched off by hand.
	 *
	 * @param Settings $settings Settings.
	 * @param string   $slug     Integration slug.
	 */
	public static function isEnabled( Settings $settings, string $slug ): bool {
		return ! in_array( $slug, $settings->disabledIntegrations, true );
	}

	/**
	 * Turn a stored event name into something a person can read.
	 *
	 * Event names are stable handles so that goals survive a rename; this is
	 * where the current title is fetched and put back in front of the reader.
	 * A handle whose form has since been deleted keeps its handle, because
	 * inventing a name for a form that no longer exists would be worse than
	 * showing the identifier that is genuinely all that is left.
	 *
	 * @param string        $eventName The stored event name.
	 * @param Settings|null $settings  Settings, or null to read them.
	 */
	public static function label( string $eventName, ?Settings $settings = null ): string {
		if ( null === EventNames::parseForm( $eventName ) ) {
			return $eventName;
		}

		foreach ( self::all( $settings ) as $integration ) {
			$label = $integration->describeEvent( $eventName );

			if ( null !== $label ) {
				return $label;
			}
		}

		return $eventName;
	}
}
