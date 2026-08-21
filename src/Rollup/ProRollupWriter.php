<?php
/**
 * Pro-only rollup writes.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Rollup;

use DateTimeZone;
use HonestAnalytics\Channels\AttributionModel;
use HonestAnalytics\Dimensions\DimensionCapper;
use HonestAnalytics\Dimensions\DimensionType;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Goals\FunnelsService;
use HonestAnalytics\Goals\GoalsService;
use HonestAnalytics\Schema\Tables;
use HonestAnalytics\Schema\Upsert;
use HonestAnalytics\Sessions\Session;
use HonestAnalytics\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaigns, geography, goals, funnels and interactions.
 *
 * The edition is checked here, in front of the write, rather than in front of
 * the markup. A site that lapses to Lite keeps the Pro rows it already has -
 * which is right, they are its data - so a gate that only hid the template
 * would still be rendering figures the licence does not cover.
 */
final class ProRollupWriter {

	private Settings $settings;
	private GoalsService $goals;
	private FunnelsService $funnels;
	private GoalMatcher $matcher;

	/**
	 * @param Settings       $settings Settings.
	 * @param GoalsService   $goals    Goals service.
	 * @param FunnelsService $funnels  Funnels service.
	 * @param GoalMatcher    $matcher  Goal matcher.
	 */
	public function __construct(
		Settings $settings,
		GoalsService $goals,
		FunnelsService $funnels,
		GoalMatcher $matcher
	) {
		$this->settings = $settings;
		$this->goals    = $goals;
		$this->funnels  = $funnels;
		$this->matcher  = $matcher;
	}

	/**
	 * Write what a finished session contributes to the Pro reports.
	 *
	 * @param Session         $session  Session.
	 * @param string          $date     Local date the session started.
	 * @param DimensionCapper $capper   Dimension capper.
	 * @param DateTimeZone    $timezone Site timezone.
	 */
	public function writeSession( Session $session, string $date, DimensionCapper $capper, DateTimeZone $timezone ): void {
		unset( $timezone );

		if ( ! Edition::isPro() ) {
			return;
		}

		$conversions = $this->matcher->conversions( $session );

		$this->writeCampaigns( $session, $date, $capper, $conversions );
		$this->writeGeo( $session, $date, $capper );
		$this->writeConversions( $session, $date, $conversions );
		$this->writeFunnels( $session, $date );
	}

	/**
	 * Write campaign attribution.
	 *
	 * Credit is split, never duplicated: a session touched by three campaigns
	 * gives each a share, and the shares sum to exactly one session. That is
	 * why these counters are decimals - splitting a session is honest, and
	 * inventing three of them is not.
	 *
	 * @param Session                                $session     Session.
	 * @param string                                 $date        Local date.
	 * @param DimensionCapper                        $capper      Dimension capper.
	 * @param array<int,\HonestAnalytics\Goals\Goal> $conversions Goals converted during the visit.
	 */
	private function writeCampaigns( Session $session, string $date, DimensionCapper $capper, array $conversions ): void {
		if ( ! $this->settings->enableCampaigns || [] === $session->campaigns ) {
			return;
		}

		$model   = AttributionModel::fromStored( $this->settings->attributionModel );
		$weights = $model->weights( count( $session->campaigns ) );

		$conversionCount = count( $conversions );
		$conversionValue = 0.0;

		foreach ( $conversions as $goal ) {
			$conversionValue += $goal->value;
		}

		foreach ( $session->campaigns as $index => $touch ) {
			$weight = $weights[ $index ] ?? 0.0;

			if ( $weight <= 0.0 ) {
				continue;
			}

			$keys = [
				'siteId'        => $session->siteId,
				'date'          => $date,
				'sourceDimId'   => $this->dimId( $capper, $session->siteId, $date, DimensionType::CampaignSource, (string) ( $touch['s'] ?? '' ) ),
				'mediumDimId'   => $this->dimId( $capper, $session->siteId, $date, DimensionType::CampaignMedium, (string) ( $touch['m'] ?? '' ) ),
				'campaignDimId' => $this->dimId( $capper, $session->siteId, $date, DimensionType::CampaignName, (string) ( $touch['c'] ?? '' ) ),
				'termDimId'     => $this->dimId( $capper, $session->siteId, $date, DimensionType::CampaignTerm, (string) ( $touch['t'] ?? '' ) ),
				'contentDimId'  => $this->dimId( $capper, $session->siteId, $date, DimensionType::CampaignContent, (string) ( $touch['o'] ?? '' ) ),
			];

			Upsert::counters(
				Tables::CAMPAIGNS_ROLLUP,
				$keys,
				[
					'sessions'    => $weight,
					'bounces'     => $session->isBounce() ? $weight : 0.0,
					'conversions' => $conversionCount * $weight,
					'value'       => $conversionValue * $weight,
				],
				[],
				[],
				[
					'sessions'    => Upsert::DECIMAL_12_4_MAX,
					'bounces'     => Upsert::DECIMAL_12_4_MAX,
					'conversions' => Upsert::DECIMAL_12_4_MAX,
					'value'       => Upsert::DECIMAL_14_2_MAX,
				]
			);
		}
	}

	/**
	 * Write where a visit came from.
	 *
	 * @param Session         $session Session.
	 * @param string          $date    Local date.
	 * @param DimensionCapper $capper  Dimension capper.
	 */
	private function writeGeo( Session $session, string $date, DimensionCapper $capper ): void {
		if ( ! $this->settings->enableGeo || '' === $session->countryCode ) {
			return;
		}

		Upsert::counters(
			Tables::GEO_ROLLUP,
			[
				'siteId'      => $session->siteId,
				'date'        => $date,
				'countryCode' => strtoupper( substr( $session->countryCode, 0, 2 ) ),
				'regionDimId' => $this->dimId( $capper, $session->siteId, $date, DimensionType::Region, $session->region ),
			],
			[ 'sessions' => 1 ]
		);
	}

	/**
	 * Write goal conversions.
	 *
	 * @param Session                                $session     Session.
	 * @param string                                 $date        Local date.
	 * @param array<int,\HonestAnalytics\Goals\Goal> $conversions Goals converted during the visit.
	 */
	private function writeConversions( Session $session, string $date, array $conversions ): void {
		if ( [] === $conversions ) {
			return;
		}

		foreach ( $conversions as $goal ) {
			if ( $goal->id <= 0 ) {
				continue;
			}

			Upsert::counters(
				Tables::GOALS_ROLLUP,
				[
					'siteId' => $session->siteId,
					'date'   => $date,
					'goalId' => $goal->id,
				],
				[
					'conversions' => 1,
					'value'       => $goal->value,
				],
				[],
				[],
				[ 'value' => Upsert::DECIMAL_14_2_MAX ]
			);
		}
	}

	/**
	 * Write how far each funnel got.
	 *
	 * A row is written at every position up to the one reached, so the report
	 * reads "how many got at least this far" and drop-off is the difference
	 * between neighbours.
	 *
	 * @param Session $session Session.
	 * @param string  $date    Local date.
	 */
	private function writeFunnels( Session $session, string $date ): void {
		$funnels = $this->funnels->enabled();

		if ( [] === $funnels ) {
			return;
		}

		$byHandle = $this->goals->byHandle();

		foreach ( $funnels as $funnel ) {
			$reached = $funnel->reachedStep( $session, $byHandle );

			for ( $position = 1; $position <= $reached; $position++ ) {
				Upsert::counters(
					Tables::FUNNEL_STEP_ROLLUP,
					[
						'siteId'   => $session->siteId,
						'date'     => $date,
						'funnelId' => $funnel->id,
						'position' => $position,
					],
					[ 'sessions' => 1 ]
				);
			}
		}
	}

	/**
	 * Write events, scroll depth, outbound clicks and searches.
	 *
	 * @param InteractionBuckets $interactions Interactions.
	 * @param DimensionCapper    $capper       Dimension capper.
	 */
	public function writeInteractions( InteractionBuckets $interactions, DimensionCapper $capper ): void {
		if ( ! Edition::isPro() || ! $this->settings->enableEvents ) {
			return;
		}

		foreach ( $interactions->events as $row ) {
			Upsert::counters(
				Tables::EVENTS_ROLLUP,
				[
					'siteId'         => $row['siteId'],
					'date'           => $row['date'],
					'hour'           => $row['hour'],
					'eventNameDimId' => $capper->resolve( $row['siteId'], $row['date'], DimensionType::EventName, $row['name'] ),
					'pathDimId'      => $this->dimId( $capper, $row['siteId'], $row['date'], DimensionType::Path, $row['path'] ),
				],
				[
					'hits'     => $row['count'],
					'sessions' => count( $row['sessions'] ),
					'sumValue' => $row['value'],
				],
				[],
				[],
				[ 'sumValue' => Upsert::DECIMAL_14_2_MAX ]
			);
		}

		foreach ( $interactions->scroll as $row ) {
			Upsert::counters(
				Tables::SCROLL_ROLLUP,
				[
					'siteId'    => $row['siteId'],
					'date'      => $row['date'],
					'pathDimId' => $capper->resolve( $row['siteId'], $row['date'], DimensionType::Path, $row['path'] ),
					'bucket'    => $row['bucket'],
				],
				[ 'hits' => $row['count'] ]
			);
		}

		foreach ( $interactions->outbound as $row ) {
			Upsert::counters(
				Tables::OUTBOUND_ROLLUP,
				[
					'siteId'          => $row['siteId'],
					'date'            => $row['date'],
					'targetHostDimId' => $capper->resolve( $row['siteId'], $row['date'], DimensionType::OutboundHost, $row['host'] ),
					'targetDimId'     => $this->dimId( $capper, $row['siteId'], $row['date'], DimensionType::OutboundUrl, $row['url'] ),
					'pathDimId'       => $this->dimId( $capper, $row['siteId'], $row['date'], DimensionType::Path, $row['path'] ),
				],
				[ 'hits' => $row['count'] ]
			);
		}

		if ( ! $this->settings->trackSiteSearch ) {
			return;
		}

		foreach ( $interactions->searches as $row ) {
			Upsert::counters(
				Tables::SEARCH_ROLLUP,
				[
					'siteId'    => $row['siteId'],
					'date'      => $row['date'],
					'termDimId' => $capper->resolve( $row['siteId'], $row['date'], DimensionType::SearchTerm, $row['term'] ),
				],
				[
					'hits'        => $row['count'],
					'zeroResults' => $row['zeroResults'],
				]
			);
		}
	}

	/**
	 * A dimension id, treating an empty value as the absence of one.
	 *
	 * "No medium" is not a medium called "", and minting a dimension row for it
	 * would put an empty string in every report that lists mediums.
	 *
	 * @param DimensionCapper $capper Dimension capper.
	 * @param int             $siteId Site ID.
	 * @param string          $date   Local date.
	 * @param DimensionType   $type   Dimension type.
	 * @param string          $value  Value.
	 */
	private function dimId( DimensionCapper $capper, int $siteId, string $date, DimensionType $type, string $value ): int {
		$value = trim( $value );

		return '' === $value ? 0 : $capper->resolve( $siteId, $date, $type, $value );
	}
}
