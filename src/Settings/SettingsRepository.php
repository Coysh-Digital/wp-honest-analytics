<?php
/**
 * Settings persistence.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the settings, applying the two override layers above the database.
 *
 * A value set in wp-config.php or by a filter wins over the stored one, which
 * is how staging and production differ without anybody editing a screen. The
 * settings UI renders overridden fields disabled and says where the value came
 * from, so nobody spends an afternoon changing a setting that cannot change.
 */
final class SettingsRepository {

	public const OPTION = 'honest_analytics_settings';

	/**
	 * Memoised settings for this request.
	 *
	 * @var Settings|null
	 */
	private static ?Settings $memo = null;

	/**
	 * Keys currently overridden outside the database.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $overrides = null;

	/**
	 * The effective settings.
	 */
	public static function get(): Settings {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		$values          = array_merge( Settings::defaults(), $stored );
		self::$overrides = [];

		if ( defined( 'HONEST_ANALYTICS_SETTINGS' ) ) {
			$constant = constant( 'HONEST_ANALYTICS_SETTINGS' );

			if ( is_array( $constant ) ) {
				foreach ( $constant as $key => $value ) {
					if ( array_key_exists( $key, $values ) ) {
						$values[ $key ]          = $value;
						self::$overrides[ $key ] = 'constant';
					}
				}
			}
		}

		/**
		 * Filters the effective settings.
		 *
		 * Anything changed here is treated as an override: the settings screen
		 * shows the field as read-only rather than pretending it can be saved.
		 *
		 * @param array<string,mixed> $values Settings values.
		 */
		$filtered = apply_filters( 'honest_analytics_settings', $values );

		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $key => $value ) {
				if ( array_key_exists( $key, $values ) && $values[ $key ] !== $value ) {
					self::$overrides[ $key ] = 'filter';
				}
			}

			$values = array_merge( $values, array_intersect_key( $filtered, $values ) );
		}

		self::$memo = new Settings( $values );

		return self::$memo;
	}

	/**
	 * Where a setting's value comes from, when not the database.
	 *
	 * @param string $key Setting name.
	 *
	 * @return string|null 'constant', 'filter', or null.
	 */
	public static function overrideSource( string $key ): ?string {
		if ( null === self::$overrides ) {
			self::get();
		}

		return self::$overrides[ $key ] ?? null;
	}

	/**
	 * Save a whole settings array.
	 *
	 * @param array<string,mixed> $values Values.
	 */
	public static function save( array $values ): void {
		update_option( self::OPTION, array_merge( Settings::defaults(), $values ), true );
		self::flush();
	}

	/**
	 * Update a subset of settings.
	 *
	 * @param array<string,mixed> $values Values.
	 */
	public static function update( array $values ): void {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		self::save( array_merge( $stored, $values ) );
	}

	/**
	 * Forget the memoised settings.
	 */
	public static function flush(): void {
		self::$memo      = null;
		self::$overrides = null;
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * Persistence and nonce handling come from core; the rendering does not,
	 * because add_settings_field cannot express a group that appears only when
	 * another switch is on, or a field that is read-only because wp-config says
	 * so.
	 */
	public static function register(): void {
		register_setting(
			'honest_analytics',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Sanitizer::class, 'sanitize' ],
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			]
		);

		add_filter(
			'option_page_capability_honest_analytics',
			static fn (): string => \HonestAnalytics\Capabilities\Capabilities::MANAGE
		);
	}
}
