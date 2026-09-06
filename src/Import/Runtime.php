<?php
/**
 * Where the import system attaches itself.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Import;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One registration point for everything the importers need hooked.
 *
 * Each part guards on `class_exists` so that a build, or a work-in-progress
 * tree, missing one piece still boots. That is not defensiveness for its own
 * sake: the free build strips code, and an import system that white-screens
 * the plugin directory because a class that is not in that package went
 * missing would be a poor advert
 * for a plugin whose whole pitch is that switching to it is easy.
 *
 * Importing is deliberately in every build, all three sources. Charging
 * somebody to bring their own history across would be a strange way to make
 * switching feel easy.
 */
final class Runtime {

	/**
	 * Attach everything.
	 */
	public static function register(): void {
		// The batch runner: cron event, REST tick and the safety net. Owns the
		// job lifecycle.
		if ( class_exists( Scheduler::class ) ) {
			Scheduler::register();
		}

		// Deactivation should not leave a chained batch event behind pointing
		// at a hook nothing listens to any more.
		if ( class_exists( Scheduler::class ) ) {
			add_action( 'honest_analytics_deactivated', [ Scheduler::class, 'unschedule' ] );
		}

		// Google Analytics: the OAuth callback and the token store. Ships in
		// every build, so this is a plain class_exists() guard and nothing more.
		if ( class_exists( Ga4\Connection::class ) ) {
			Ga4\Connection::register();
		}

		/**
		 * Fires while the import system is being attached.
		 *
		 * Where a source that is not part of this package registers its own
		 * connection - an OAuth callback, a token store, a daily sync.
		 */
		do_action( 'honest_analytics_register_import_sources' );
	}
}
