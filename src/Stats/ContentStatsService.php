<?php
/**
 * Content reports.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Stats;

use HonestAnalytics\Schema\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Views joined to WordPress's own content model.
 *
 * There is no extra storage here at all: everything is a query-time join from
 * the page rollup's post id into `wp_posts` and the taxonomy tables. That has a
 * consequence worth stating, because it surprises people - moving a post
 * between post types moves its whole history with it, since the answer is
 * computed from how the site is structured now rather than from how it was
 * structured when the views happened.
 */
final class ContentStatsService {

	/**
	 * Views by post type.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function byPostType( int $siteId, DateRange $range, int $limit = 100 ): array {
		global $wpdb;

		$rollup = Tables::name( Tables::PAGES_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_type AS slug,
					COALESCE(SUM(r.views),0) AS views,
					COUNT(DISTINCT r.postId) AS posts,
					COALESCE(SUM(r.totalDwellMs),0) AS dwell
				FROM `$rollup` r
				INNER JOIN {$wpdb->posts} p ON p.ID = r.postId
				WHERE r.siteId = %d AND r.date BETWEEN %s AND %s
					AND p.post_status = 'publish'
				GROUP BY p.post_type
				ORDER BY views DESC
				LIMIT %d",
				$siteId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [];

		foreach ( (array) $rows as $row ) {
			$slug   = (string) $row['slug'];
			$object = get_post_type_object( $slug );

			// Only public types belong in a content report. Revisions,
			// attachments and menu items are not things anybody publishes.
			if ( null === $object || ! $object->public ) {
				continue;
			}

			$out[] = $this->shape( $object->labels->name ?? $slug, $slug, $row );
		}

		return $out;
	}

	/**
	 * Views by taxonomy term.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function byTaxonomy( int $siteId, DateRange $range, int $limit = 100 ): array {
		global $wpdb;

		$taxonomies = get_taxonomies( [ 'public' => true ], 'names' );

		if ( [] === $taxonomies ) {
			return [];
		}

		$rollup       = Tables::name( Tables::PAGES_ROLLUP );
		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		$args = array_merge(
			[ $siteId, $range->from, $range->to ],
			array_values( $taxonomies ),
			[ max( 1, $limit ) ]
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.name AS label, tt.taxonomy AS taxonomy, tt.term_id AS termId,
					COALESCE(SUM(r.views),0) AS views,
					COUNT(DISTINCT r.postId) AS posts,
					COALESCE(SUM(r.totalDwellMs),0) AS dwell
				FROM `$rollup` r
				INNER JOIN {$wpdb->posts} p ON p.ID = r.postId
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE r.siteId = %d AND r.date BETWEEN %s AND %s
					AND p.post_status = 'publish'
					AND tt.taxonomy IN ($placeholders)
				GROUP BY tt.term_taxonomy_id, t.name, tt.taxonomy, tt.term_id
				ORDER BY views DESC
				LIMIT %d",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$out = [];

		foreach ( (array) $rows as $row ) {
			$taxonomy = get_taxonomy( (string) $row['taxonomy'] );
			$prefix   = $taxonomy && isset( $taxonomy->labels->singular_name )
				? $taxonomy->labels->singular_name
				: (string) $row['taxonomy'];

			$out[] = $this->shape(
				$prefix . ': ' . (string) $row['label'],
				(string) $row['taxonomy'] . ':' . (int) $row['termId'],
				$row
			);
		}

		return $out;
	}

	/**
	 * Views by author.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function byAuthor( int $siteId, DateRange $range, int $limit = 100 ): array {
		global $wpdb;

		$rollup = Tables::name( Tables::PAGES_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID AS authorId, u.display_name AS label,
					COALESCE(SUM(r.views),0) AS views,
					COUNT(DISTINCT r.postId) AS posts,
					COALESCE(SUM(r.totalDwellMs),0) AS dwell
				FROM `$rollup` r
				INNER JOIN {$wpdb->posts} p ON p.ID = r.postId
				INNER JOIN {$wpdb->users} u ON u.ID = p.post_author
				WHERE r.siteId = %d AND r.date BETWEEN %s AND %s
					AND p.post_status = 'publish'
				GROUP BY u.ID, u.display_name
				ORDER BY views DESC
				LIMIT %d",
				$siteId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = [];

		foreach ( (array) $rows as $row ) {
			$out[] = $this->shape( (string) $row['label'], (string) $row['authorId'], $row );
		}

		return $out;
	}

	/**
	 * The most-viewed posts.
	 *
	 * @param int       $siteId Site ID.
	 * @param DateRange $range  Period.
	 * @param int       $limit  Maximum rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function topPosts( int $siteId, DateRange $range, int $limit = 20 ): array {
		global $wpdb;

		$rollup = Tables::name( Tables::PAGES_ROLLUP );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Rollup tables have no core API and are deliberately uncached; identifiers come from Schema\Tables and internal whitelists, and every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.postId, p.post_title AS title, COALESCE(SUM(r.views),0) AS views
				FROM `$rollup` r
				INNER JOIN {$wpdb->posts} p ON p.ID = r.postId
				WHERE r.siteId = %d AND r.date BETWEEN %s AND %s AND p.post_status = 'publish'
				GROUP BY r.postId, p.post_title
				ORDER BY views DESC
				LIMIT %d",
				$siteId,
				$range->from,
				$range->to,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (array) $rows;
	}

	/**
	 * Shape a content row.
	 *
	 * @param string              $label Display label.
	 * @param string              $key   Stable key.
	 * @param array<string,mixed> $row   Database row.
	 *
	 * @return array<string,mixed>
	 */
	private function shape( string $label, string $key, array $row ): array {
		$views = (int) $row['views'];
		$posts = (int) $row['posts'];
		$dwell = (int) $row['dwell'];

		return [
			'label'      => $label,
			'key'        => $key,
			'views'      => $views,
			'posts'      => $posts,
			// The column worth reading. A post type with twenty posts and two
			// thousand views is not obviously beating one with two posts and
			// eight hundred.
			'perPost'    => $posts > 0 ? (int) round( $views / $posts ) : 0,
			'avgDwellMs' => $views > 0 ? (int) round( $dwell / $views ) : 0,
		];
	}
}
