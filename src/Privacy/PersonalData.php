<?php
/**
 * WordPress personal data exporter and eraser.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into WordPress's own data request tools.
 *
 * A request keyed on an email address can only reach data joined to a user
 * account, which means the consented journeys layer with account linking on.
 * Everything else this plugin stores is a counter with no individual in it. The
 * exporter says so rather than returning an empty result that reads like a bug.
 */
final class PersonalData {

	/**
	 * Register the exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function registerExporter( array $exporters ): array {
		$exporters['honest-analytics'] = [
			'exporter_friendly_name' => __( 'Honest Analytics', 'honest-analytics' ),
			'callback'               => [ self::class, 'export' ],
		];

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function registerEraser( array $erasers ): array {
		$erasers['honest-analytics'] = [
			'eraser_friendly_name' => __( 'Honest Analytics', 'honest-analytics' ),
			'callback'             => [ self::class, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * Export what is held about an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 *
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		unset( $page );

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$export = ( new PrivacyService() )->export( null, (int) $user->ID );
		$items  = [];

		foreach ( $export['journeys'] as $index => $journey ) {
			$items[] = [
				'group_id'          => 'honest-analytics-journeys',
				'group_label'       => __( 'Analytics page history', 'honest-analytics' ),
				'group_description' => __( 'Pages recorded for this account, kept only because durable tracking was consented to.', 'honest-analytics' ),
				'item_id'           => 'honest-analytics-journey-' . $index,
				'data'              => [
					[
						'name'  => __( 'Time', 'honest-analytics' ),
						'value' => (string) ( $journey['occurredAt'] ?? '' ),
					],
					[
						'name'  => __( 'Page', 'honest-analytics' ),
						'value' => (string) ( $journey['path'] ?? '' ),
					],
					[
						'name'  => __( 'Step in visit', 'honest-analytics' ),
						'value' => (string) ( $journey['sequence'] ?? '' ),
					],
				],
			];
		}

		if ( [] === $items ) {
			$items[] = [
				'group_id'    => 'honest-analytics-journeys',
				'group_label' => __( 'Analytics', 'honest-analytics' ),
				'item_id'     => 'honest-analytics-none',
				'data'        => [
					[
						'name'  => __( 'Records held', 'honest-analytics' ),
						'value' => __( 'None. This site\'s analytics are aggregate counts with no individual inside them, and no per-visitor records are kept for this account.', 'honest-analytics' ),
					],
				],
			];
		}

		return [
			'data' => $items,
			'done' => true,
		];
	}

	/**
	 * Erase what is held about an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		unset( $page );

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return [
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => [],
				'done'           => true,
			];
		}

		$result = ( new PrivacyService() )->erase( null, (int) $user->ID, false );

		$messages = [];

		if ( $result['journeys'] > 0 ) {
			$messages[] = __( 'The consent record has been kept: it is the evidence that the processing now erased was lawful while it was happening.', 'honest-analytics' );
		}

		return [
			'items_removed'  => $result['journeys'] > 0,
			'items_retained' => $result['journeys'] > 0,
			'messages'       => $messages,
			'done'           => true,
		];
	}
}
