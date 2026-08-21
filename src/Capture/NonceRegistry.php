<?php
/**
 * Hybrid-mode deduplication.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Capture;

use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Store\KeyValueStoreInterface;
use HonestAnalytics\Store\StoreFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stops the server and the browser both counting the same view.
 *
 * In hybrid mode PHP records the view as it builds the page and mints a
 * one-time nonce into the tracker's script tag. When the beacon arrives it
 * tries to claim that nonce: if it succeeds, PHP already counted this view and
 * the beacon only contributes engagement. If it fails, nobody counted it - the
 * page came from a cache and PHP never ran - so the beacon counts it.
 *
 * The record is keyed on the nonce *and* the visitor, which is the part that is
 * easy to get wrong. A full-page cache bakes one nonce into HTML served to
 * everybody. Keyed on the nonce alone, the first visitor to arrive would be
 * mistaken for the one PHP rendered for and their view swallowed - and if a
 * crawler warmed the cache, the first real visitor would be the one lost.
 * Keyed on both, a thousand visitors to a cached page produce a thousand views.
 */
final class NonceRegistry {

	private const PREFIX = 'nonce:';

	private Settings $settings;
	private KeyValueStoreInterface $store;

	/**
	 * @param Settings                    $settings Settings.
	 * @param KeyValueStoreInterface|null $store    Store.
	 */
	public function __construct( Settings $settings, ?KeyValueStoreInterface $store = null ) {
		$this->settings = $settings;
		$this->store    = $store ?? StoreFactory::keyValue();
	}

	/**
	 * Mint a nonce for a page about to be rendered.
	 */
	public function issue(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Record that the server counted a view for this visitor.
	 *
	 * @param string $nonce       Nonce.
	 * @param string $visitorHash Visitor hash.
	 */
	public function record( string $nonce, string $visitorHash ): void {
		if ( ! self::isWellFormed( $nonce ) ) {
			return;
		}

		$this->store->set( self::key( $nonce, $visitorHash ), 1, max( 60, $this->settings->nonceTtl ) );
	}

	/**
	 * Try to claim a nonce.
	 *
	 * @param string $nonce       Nonce.
	 * @param string $visitorHash Visitor hash.
	 *
	 * @return bool True when the server had already counted this view.
	 */
	public function claim( string $nonce, string $visitorHash ): bool {
		if ( ! self::isWellFormed( $nonce ) ) {
			return false;
		}

		// A conditional delete that reports whether it removed anything is the
		// claim. Read-then-delete is not atomic on every backend; the documented
		// failure mode there is losing one view, which is the safe side.
		return $this->store->delete( self::key( $nonce, $visitorHash ) );
	}

	/**
	 * Whether a string could be one of our nonces.
	 *
	 * @param string $nonce Candidate.
	 */
	public static function isWellFormed( string $nonce ): bool {
		return 16 === strlen( $nonce ) && ctype_xdigit( $nonce );
	}

	/**
	 * The store key for a nonce and visitor.
	 *
	 * @param string $nonce       Nonce.
	 * @param string $visitorHash Visitor hash.
	 */
	private static function key( string $nonce, string $visitorHash ): string {
		return self::PREFIX . $nonce . ':' . $visitorHash;
	}
}
