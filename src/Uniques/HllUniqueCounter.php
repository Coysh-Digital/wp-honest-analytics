<?php
/**
 * Sketch-based unique counting.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Uniques;

use HonestAnalytics\Identity\IdentityService;
use HonestAnalytics\Settings\Settings;
use HonestAnalytics\Support\Log;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The default: one small sketch per rollup row.
 *
 * Four kilobytes on a row that already exists, holding no visitor and no way to
 * ask about one.
 */
final class HllUniqueCounter implements UniqueCounterInterface {

	private Settings $settings;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Add hashes to the row's sketch.
	 *
	 * @param UniqueScope $scope   Scope.
	 * @param string[]    $hashes  Visitor hashes.
	 * @param string|null $current Stored sketch.
	 */
	public function record( UniqueScope $scope, array $hashes, ?string $current ): string {
		unset( $scope );

		$sketch = $this->readOrRestart( $current );

		foreach ( $hashes as $hash ) {
			if ( ! IdentityService::isValidHash( $hash ) ) {
				continue;
			}

			$sketch->add( (string) $hash );
		}

		return $sketch->serialize();
	}

	/**
	 * Union the sketches for a set of scopes.
	 *
	 * @param UniqueScope[]             $scopes   Scopes.
	 * @param array<string,string|null> $sketches Stored sketches.
	 */
	public function estimate( array $scopes, array $sketches ): int {
		$blobs = [];

		foreach ( $scopes as $scope ) {
			$blob = $sketches[ $scope->key() ] ?? null;

			if ( null !== $blob && '' !== $blob ) {
				$blobs[] = $blob;
			}
		}

		if ( [] === $blobs ) {
			return 0;
		}

		$merged = Hll::mergeAll(
			$blobs,
			$this->precision(),
			static function ( string $message ): void {
				Log::warning( 'Skipped an unreadable sketch while estimating: ' . $message );
			}
		);

		return $merged->count();
	}

	/**
	 * Sketches live on the row.
	 */
	public function storesOnRow(): bool {
		return true;
	}

	/**
	 * Nothing to do: the compactor merges the row blobs itself.
	 *
	 * @param UniqueScope   $daily  Destination.
	 * @param UniqueScope[] $hourly Sources.
	 */
	public function compact( UniqueScope $daily, array $hourly ): void {
		unset( $daily, $hourly );
	}

	/**
	 * Nothing to discard: the hourly rows go with their sketches.
	 *
	 * @param UniqueScope[] $scopes Scopes.
	 */
	public function discardCompacted( array $scopes ): void {
		unset( $scopes );
	}

	/**
	 * The relative error, never rounded in our favour.
	 */
	public function accuracy(): string {
		return ( new Hll( $this->precision() ) )->accuracy();
	}

	/**
	 * The implementation name.
	 */
	public function name(): string {
		return Settings::UNIQUES_HLL;
	}

	/**
	 * Read a stored sketch, starting fresh if it cannot be used.
	 *
	 * A sketch built at a different precision cannot be merged with the current
	 * one, and neither can a corrupted blob. Starting again loses that row's
	 * history; refusing would leave the row unwritable forever, which loses
	 * every day after it too.
	 *
	 * @param string|null $current Stored sketch.
	 */
	private function readOrRestart( ?string $current ): Hll {
		if ( null === $current || '' === $current ) {
			return new Hll( $this->precision() );
		}

		try {
			$sketch = Hll::deserialize( $current );

			if ( $sketch->precision() !== $this->precision() ) {
				Log::warning( 'A stored sketch was built at a different precision; starting a fresh one for this row.' );

				return new Hll( $this->precision() );
			}

			return $sketch;
		} catch ( InvalidArgumentException $e ) {
			Log::warning( 'A stored sketch could not be read; starting a fresh one for this row: ' . $e->getMessage() );

			return new Hll( $this->precision() );
		}
	}

	/**
	 * The configured precision, clamped to what is supported.
	 */
	private function precision(): int {
		return max( Hll::MIN_PRECISION, min( Hll::MAX_PRECISION, $this->settings->hllPrecision ) );
	}
}
