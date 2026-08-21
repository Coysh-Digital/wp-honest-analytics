<?php
/**
 * Custom capabilities.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three capabilities, because "can see the reports", "can take the data out of
 * the building" and "can change what is collected" are three different
 * decisions.
 */
final class Capabilities {

	public const VIEW   = 'honest_view_analytics';
	public const EXPORT = 'honest_export_analytics';
	public const MANAGE = 'honest_manage_analytics';

	/**
	 * All three, in order of increasing privilege.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return [ self::VIEW, self::EXPORT, self::MANAGE ];
	}

	/**
	 * Grant the capabilities to the roles that should have them.
	 *
	 * Runs on activation. Roles are per-site on multisite, which is why this is
	 * called once per site rather than once per network.
	 */
	public static function grant(): void {
		/**
		 * Filters which roles receive which analytics capabilities on activation.
		 *
		 * @param array<string,string[]> $map Role slug => capability names.
		 */
		$map = (array) apply_filters(
			'honest_analytics_default_role_caps',
			[
				'administrator' => self::all(),
			]
		);

		foreach ( $map as $roleName => $caps ) {
			$role = get_role( (string) $roleName );

			if ( ! $role ) {
				continue;
			}

			foreach ( (array) $caps as $cap ) {
				if ( in_array( $cap, self::all(), true ) ) {
					$role->add_cap( (string) $cap );
				}
			}
		}
	}

	/**
	 * Remove the capabilities from every role.
	 *
	 * Only called on uninstall: deactivating a plugin should not cost an
	 * administrator their permissions if they turn it back on.
	 */
	public static function revoke(): void {
		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $roleName ) {
			$role = get_role( (string) $roleName );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Register the meta-cap mapping.
	 */
	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'mapMetaCap' ], 10, 4 );
	}

	/**
	 * Let anyone who can manage the site reach the reports.
	 *
	 * Without this, a site whose activation ran before a role existed - or a
	 * multisite blog the network activation loop never reached - locks its own
	 * administrator out of a plugin they just installed. `manage_options` is not
	 * one of ours, so there is no recursion.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $userId  User ID.
	 * @param mixed[]  $args    Extra arguments.
	 *
	 * @return string[]
	 */
	public static function mapMetaCap( array $caps, string $cap, int $userId, array $args ): array {
		unset( $args );

		if ( ! in_array( $cap, self::all(), true ) ) {
			return $caps;
		}

		$user = get_userdata( $userId );

		if ( $user && isset( $user->allcaps[ $cap ] ) && $user->allcaps[ $cap ] ) {
			return $caps;
		}

		return [ 'manage_options' ];
	}
}
