<?php
/**
 * One page, one hour, one site.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The counters accumulating for one page in one hour.
 *
 * This is where "storage grows with dimensions, not traffic" actually happens:
 * a million views of one page in one hour are a million calls to `add()` and
 * one row at the end of it.
 */
final class PageBucket {

	public int $views   = 0;
	public int $dwellMs = 0;

	/**
	 * Visitor hashes seen, as a set.
	 *
	 * @var array<string,true>
	 */
	public array $visitorHashes = [];

	public function __construct(
		public readonly int $siteId,
		public readonly string $date,
		public readonly int $hour,
		public readonly string $path,
		public readonly ?int $postId = null
	) {
	}

	/**
	 * Fold a hit in.
	 *
	 * @param string $visitorHash Visitor hash.
	 * @param bool   $countView   Whether this hit is a view.
	 * @param int    $dwellMs     Time on page reported by the beacon.
	 */
	public function add( string $visitorHash, bool $countView = true, int $dwellMs = 0 ): void {
		if ( $countView ) {
			++$this->views;
		}

		$this->dwellMs += max( 0, $dwellMs );

		// The visitor belongs in the sketch either way. Somebody who arrived on
		// a cached page and only reported their time on the way out was still
		// here.
		if ( '' !== $visitorHash ) {
			$this->visitorHashes[ $visitorHash ] = true;
		}
	}

	/**
	 * The visitor hashes seen, as strings.
	 *
	 * They are held as array keys so that adding one is deduplicated for free -
	 * but PHP casts a numeric-looking key to an integer, and roughly one visitor
	 * hash in forty thousand is all digits. Reading them back through here is
	 * what stops that becoming an integer where a string is expected.
	 *
	 * @return string[]
	 */
	public function visitorHashes(): array {
		return array_map( 'strval', array_keys( $this->visitorHashes ) );
	}

	/**
	 * The key this bucket accumulates under.
	 *
	 * @param int    $siteId Site ID.
	 * @param string $date   Local date.
	 * @param int    $hour   Local hour.
	 * @param string $path   Path.
	 */
	public static function key( int $siteId, string $date, int $hour, string $path ): string {
		return $siteId . '|' . $date . '|' . $hour . '|' . $path;
	}
}
