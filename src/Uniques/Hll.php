<?php
/**
 * HyperLogLog sketch.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Uniques;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counting distinct visitors without keeping a list of them.
 *
 * A day's unique visitors could be counted exactly by storing every visitor
 * hash and counting the rows. That is a per-visitor record, which is the one
 * thing this plugin does not keep. A sketch answers the same question from a
 * few kilobytes that cannot be interrogated for an individual: there is no
 * membership test, no way to ask whether a particular person was here, and
 * nothing to erase on request because there is nothing in there.
 *
 * The accuracy is stated wherever a figure derived from it appears. At the
 * default precision that is about 1.6%, which is a smaller error than the
 * difference between any two analytics products measuring the same site.
 *
 * Implemented from the published papers rather than adapted from another
 * codebase - Flajolet et al. 2007 for the estimator, Heule et al. 2013 for the
 * sparse representation.
 */
final class Hll {

	public const VERSION = 1;

	private const FORMAT_SPARSE = 0;
	private const FORMAT_DENSE  = 1;

	public const MIN_PRECISION     = 11;
	public const MAX_PRECISION     = 14;
	public const DEFAULT_PRECISION = 12;

	private const HEADER_BYTES       = 3;
	private const SPARSE_ENTRY_BYTES = 3;

	private int $precision;
	private int $registerCount;

	/**
	 * Sparse entries, index => rank, while the sketch is still small.
	 *
	 * @var array<int,int>
	 */
	private array $sparse = [];

	/**
	 * Dense registers, one byte each, once the sketch has filled out.
	 */
	private ?string $dense = null;

	/**
	 * @param int $precision Register-count exponent.
	 */
	public function __construct( int $precision = self::DEFAULT_PRECISION ) {
		if ( $precision < self::MIN_PRECISION || $precision > self::MAX_PRECISION ) {
			throw new InvalidArgumentException( 'Unsupported HyperLogLog precision.' );
		}

		$this->precision     = $precision;
		$this->registerCount = 1 << $precision;
	}

	/**
	 * The precision this sketch was built at.
	 */
	public function precision(): int {
		return $this->precision;
	}

	/**
	 * Add a visitor hash.
	 *
	 * The input is already a uniformly distributed digest, so it is used
	 * directly as the sketch's hash; re-hashing it would buy nothing.
	 *
	 * @param string $visitorHash Sixteen hexadecimal characters.
	 */
	public function add( string $visitorHash ): void {
		if ( 16 !== strlen( $visitorHash ) || ! ctype_xdigit( $visitorHash ) ) {
			throw new InvalidArgumentException( 'A visitor hash must be sixteen hexadecimal characters.' );
		}

		$binary = hex2bin( $visitorHash );

		if ( false === $binary ) {
			throw new InvalidArgumentException( 'A visitor hash must be sixteen hexadecimal characters.' );
		}

		$parts = unpack( 'N2', $binary );

		if ( ! is_array( $parts ) ) {
			return;
		}

		$hi = (int) $parts[1];
		$lo = (int) $parts[2];

		$index = $hi >> ( 32 - $this->precision );
		$rank  = self::rank( $hi, $lo, $this->precision );

		$this->setRegister( $index, $rank );
	}

	/**
	 * Merge another sketch into this one.
	 *
	 * The union of two days is the merge of their sketches, never the sum of
	 * their counts. Summing daily uniques to get a month is the single most
	 * common lie in analytics tooling, and it is why every reader in this
	 * plugin merges.
	 *
	 * @param self $other Sketch to merge in.
	 */
	public function merge( self $other ): void {
		if ( $other->precision !== $this->precision ) {
			throw new InvalidArgumentException( 'Sketches of different precisions cannot be merged.' );
		}

		if ( null === $other->dense ) {
			foreach ( $other->sparse as $index => $rank ) {
				$this->setRegister( $index, $rank );
			}

			return;
		}

		$this->toDense();

		for ( $i = 0; $i < $this->registerCount; $i++ ) {
			$incoming = ord( $other->dense[ $i ] );

			if ( $incoming > ord( $this->dense[ $i ] ) ) {
				$this->dense[ $i ] = chr( $incoming );
			}
		}
	}

	/**
	 * The estimated number of distinct values.
	 */
	public function count(): int {
		$m    = $this->registerCount;
		$sum  = 0.0;
		$zero = 0;

		if ( null === $this->dense ) {
			$zero = $m - count( $this->sparse );
			$sum  = (float) $zero;

			foreach ( $this->sparse as $rank ) {
				$sum += 2 ** -$rank;
			}
		} else {
			for ( $i = 0; $i < $m; $i++ ) {
				$rank = ord( $this->dense[ $i ] );

				if ( 0 === $rank ) {
					++$zero;
					$sum += 1.0;

					continue;
				}

				$sum += 2 ** -$rank;
			}
		}

		if ( $sum <= 0.0 ) {
			return 0;
		}

		$estimate = self::alpha( $m ) * $m * $m / $sum;

		// Small cardinalities are where the raw estimator is worst, and where a
		// site's numbers are most likely to be checked by hand against a short
		// list. Linear counting is exact enough there to survive that.
		if ( $estimate <= 2.5 * $m && $zero > 0 ) {
			$estimate = $m * log( $m / $zero );
		}

		return (int) round( $estimate );
	}

	/**
	 * The relative error at this precision, as a signed percentage string.
	 */
	public function accuracy(): string {
		$error = 1.04 / sqrt( (float) $this->registerCount ) * 100;

		return sprintf( '±%.1f%%', $error );
	}

	/**
	 * The stored form.
	 */
	public function serialize(): string {
		if ( null === $this->dense ) {
			$out = pack( 'CCC', self::VERSION, $this->precision, self::FORMAT_SPARSE );

			$entries = $this->sparse;
			ksort( $entries );

			foreach ( $entries as $index => $rank ) {
				$out .= pack( 'nC', $index, $rank );
			}

			return $out;
		}

		return pack( 'CCC', self::VERSION, $this->precision, self::FORMAT_DENSE ) . $this->dense;
	}

	/**
	 * Read a stored sketch.
	 *
	 * @param string $blob Serialised sketch.
	 */
	public static function deserialize( string $blob ): self {
		if ( strlen( $blob ) < self::HEADER_BYTES ) {
			throw new InvalidArgumentException( 'A sketch must carry its header.' );
		}

		$header = unpack( 'Cversion/Cprecision/Cformat', substr( $blob, 0, self::HEADER_BYTES ) );

		if ( ! is_array( $header ) || self::VERSION !== (int) $header['version'] ) {
			throw new InvalidArgumentException( 'Unsupported sketch version.' );
		}

		$sketch = new self( (int) $header['precision'] );
		$body   = substr( $blob, self::HEADER_BYTES );

		if ( self::FORMAT_DENSE === (int) $header['format'] ) {
			if ( strlen( $body ) !== $sketch->registerCount ) {
				throw new InvalidArgumentException( 'A dense sketch must carry one byte per register.' );
			}

			$sketch->dense = $body;

			return $sketch;
		}

		if ( 0 !== strlen( $body ) % self::SPARSE_ENTRY_BYTES ) {
			throw new InvalidArgumentException( 'A sparse sketch must carry whole entries.' );
		}

		for ( $offset = 0; $offset < strlen( $body ); $offset += self::SPARSE_ENTRY_BYTES ) {
			$entry = unpack( 'nindex/Crank', substr( $body, $offset, self::SPARSE_ENTRY_BYTES ) );

			if ( is_array( $entry ) ) {
				$sketch->setRegister( (int) $entry['index'], (int) $entry['rank'] );
			}
		}

		return $sketch;
	}

	/**
	 * Merge a set of stored sketches into one.
	 *
	 * Tolerant by design: one unreadable hourly sketch must not stop a day -
	 * and every day after it - from ever compacting.
	 *
	 * @param iterable<string|null> $blobs      Serialised sketches.
	 * @param int                   $precision  Precision to merge at.
	 * @param callable|null         $onUnreadable Called with the failure message.
	 */
	public static function mergeAll( iterable $blobs, int $precision, ?callable $onUnreadable = null ): self {
		$merged = new self( $precision );

		foreach ( $blobs as $blob ) {
			if ( null === $blob || '' === $blob ) {
				continue;
			}

			try {
				$merged->merge( self::deserialize( $blob ) );
			} catch ( InvalidArgumentException $e ) {
				if ( null !== $onUnreadable ) {
					$onUnreadable( $e->getMessage() );
				}
			}
		}

		return $merged;
	}

	/**
	 * Raise a register, never lower it.
	 *
	 * @param int $index Register index.
	 * @param int $rank  Rank.
	 */
	private function setRegister( int $index, int $rank ): void {
		if ( $index < 0 || $index >= $this->registerCount || $rank <= 0 ) {
			return;
		}

		if ( null !== $this->dense ) {
			if ( $rank > ord( $this->dense[ $index ] ) ) {
				$this->dense[ $index ] = chr( min( 255, $rank ) );
			}

			return;
		}

		if ( ! isset( $this->sparse[ $index ] ) || $rank > $this->sparse[ $index ] ) {
			$this->sparse[ $index ] = min( 255, $rank );
		}

		// Once the sparse form costs as much as the dense one it has stopped
		// being a saving.
		if ( count( $this->sparse ) * self::SPARSE_ENTRY_BYTES >= $this->registerCount ) {
			$this->toDense();
		}
	}

	/**
	 * Promote to the dense representation.
	 */
	private function toDense(): void {
		if ( null !== $this->dense ) {
			return;
		}

		$dense = str_repeat( "\0", $this->registerCount );

		foreach ( $this->sparse as $index => $rank ) {
			$dense[ $index ] = chr( min( 255, $rank ) );
		}

		$this->dense  = $dense;
		$this->sparse = [];
	}

	/**
	 * The position of the leftmost set bit after the index bits.
	 *
	 * @param int $hi        High 32 bits.
	 * @param int $lo        Low 32 bits.
	 * @param int $precision Index bit count.
	 */
	private static function rank( int $hi, int $lo, int $precision ): int {
		$remaining = 32 - $precision;
		$rest      = $hi & ( ( 1 << $remaining ) - 1 );
		$rank      = 1;

		for ( $bit = $remaining - 1; $bit >= 0; $bit-- ) {
			if ( 0 !== ( $rest & ( 1 << $bit ) ) ) {
				return $rank;
			}

			++$rank;
		}

		for ( $bit = 31; $bit >= 0; $bit-- ) {
			if ( 0 !== ( $lo & ( 1 << $bit ) ) ) {
				return $rank;
			}

			++$rank;
		}

		return $rank;
	}

	/**
	 * The bias-correction constant for a register count.
	 *
	 * @param int $m Register count.
	 */
	private static function alpha( int $m ): float {
		return match ( true ) {
			16 === $m => 0.673,
			32 === $m => 0.697,
			64 === $m => 0.709,
			default   => 0.7213 / ( 1 + 1.079 / $m ),
		};
	}
}
