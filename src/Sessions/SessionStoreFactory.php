<?php
/**
 * Session store selection.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Sessions;

use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\StoreFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks a session store, once.
 */
final class SessionStoreFactory {

	/**
	 * Build the store for a configuration.
	 *
	 * @param Settings $settings Settings.
	 */
	public static function make( Settings $settings ): SessionStoreInterface {
		$mode = $settings->sessionStore;

		if ( Settings::STORE_CACHE === $mode ) {
			return new CacheSessionStore( $settings );
		}

		if ( Settings::STORE_DB === $mode ) {
			return new DbSessionStore( $settings );
		}

		return StoreFactory::usingObjectCache()
			? new CacheSessionStore( $settings )
			: new DbSessionStore( $settings );
	}
}
