<?php
/**
 * Lite / Pro / Agency edition gate.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Edition;

use HonestAnalytics\Licensing\LicenceService;
use HonestAnalytics\Licensing\LicenceState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place that decides which edition this is.
 *
 * Every gate in the plugin asks this class and nothing else. Scattering licence
 * logic through templates is how a downgraded site ends up still rendering Pro
 * figures from rows it already has: the check has to sit in front of the query,
 * not in front of the markup.
 *
 * There are three editions and two behaviours. Lite is the free build. Pro and
 * Agency are the same build and the same features - the licence decides how
 * many sites may use it, and nothing else. Anything asking "is this Agency"
 * outside the Licence screen is asking the wrong question.
 */
final class Edition {
	/*
	 * Literals, not aliases of the matching LicenceState constants.
	 *
	 * PHP resolves a class-constant initialiser the first time it is read, not
	 * when the file is parsed, so `const LITE = LicenceState::TIER_LITE` in a
	 * build where the Licensing namespace has been stripped is a fatal waiting
	 * for whoever first calls tier() - the CLI, as it happens. The free build
	 * carries no licence layer at all, and these strings still have to mean
	 * something there.
	 *
	 * LicenceStateTest asserts the two sets stay identical.
	 */
	public const LITE   = 'lite';
	public const PRO    = 'pro';
	public const AGENCY = 'agency';

	/**
	 * Memoised answer for this request.
	 *
	 * @var bool|null
	 */
	private static ?bool $isPro = null;

	/**
	 * Whether this build contains the Pro code at all.
	 *
	 * The free build is distributed through wordpress.org and the paid one as a
	 * direct download; they are produced from the same source, but the free one
	 * ships without the Pro screens. A build that does not contain them must
	 * not offer a licence field for unlocking them.
	 *
	 * Defined as true when the constant is absent, because the working tree is
	 * the full build.
	 */
	public static function hasPro(): bool {
		return ! defined( 'HONEST_ANALYTICS_HAS_PRO' ) || (bool) constant( 'HONEST_ANALYTICS_HAS_PRO' );
	}

	/**
	 * Whether the licence layer is present in this build at all.
	 *
	 * The free build ships without it. Every call into Licensing goes through
	 * this first, so that stripping the namespace is a packaging decision
	 * rather than a fatal.
	 */
	private static function hasLicensing(): bool {
		return class_exists( LicenceService::class );
	}

	/**
	 * Whether the Pro features are available.
	 */
	public static function isPro(): bool {
		if ( null !== self::$isPro ) {
			return self::$isPro;
		}

		$isPro = false;

		if ( self::hasPro() ) {
			if ( defined( 'HONEST_ANALYTICS_PRO' ) && constant( 'HONEST_ANALYTICS_PRO' ) ) {
				$isPro = true;
			} elseif ( self::hasLicensing() ) {
				$isPro = LicenceService::state()->isPro();
			}
		}

		/**
		 * Filters whether the Pro edition is active.
		 *
		 * @param bool $isPro Whether Pro features are available.
		 */
		self::$isPro = (bool) apply_filters( 'honest_analytics_is_pro', $isPro );

		return self::$isPro;
	}

	/**
	 * Whether this is the free edition.
	 */
	public static function isLite(): bool {
		return ! self::isPro();
	}

	/**
	 * Whether the licence covers more than one site.
	 *
	 * Of interest to the Licence screen and to nobody else: Agency unlocks no
	 * feature that Pro does not.
	 */
	public static function isAgency(): bool {
		return self::isPro() && self::hasLicensing() && LicenceService::state()->isAgency();
	}

	/**
	 * The edition name.
	 */
	public static function tier(): string {
		if ( ! self::isPro() ) {
			return self::LITE;
		}

		return self::isAgency() ? self::AGENCY : self::PRO;
	}

	/**
	 * The edition name.
	 *
	 * Kept because the diagnostics output and the CLI both print it.
	 */
	public static function name(): string {
		return self::tier();
	}

	/**
	 * The current licence state.
	 *
	 * Pro builds only. The free build has no licence layer in it, which is why
	 * the Licence screen - the one caller - is stripped alongside it. Check
	 * `hasPro()` before reaching for this from anywhere new.
	 */
	public static function state(): LicenceState {
		return LicenceService::state();
	}

	/**
	 * Forget the memoised edition. For tests and for the licence screen.
	 */
	public static function flush(): void {
		self::$isPro = null;

		if ( self::hasLicensing() ) {
			LicenceService::flush();
		}
	}

	/**
	 * Stop a request that asked for something this edition does not include.
	 */
	public static function requirePro(): void {
		if ( self::isPro() ) {
			return;
		}

		wp_die(
			esc_html__( 'This report is part of Honest Analytics Pro.', 'honest-analytics' ),
			esc_html__( 'Not available', 'honest-analytics' ),
			[ 'response' => 403 ]
		);
	}
}
