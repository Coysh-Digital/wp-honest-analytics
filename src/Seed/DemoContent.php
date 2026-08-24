<?php
/**
 * Demo posts, pages, terms and authors.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Seed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content for the seeder to attribute traffic to.
 *
 * The traffic seeder counts paths. Half the reports - Content by post type, by
 * taxonomy, by author, the views column on the post list, the panel in the
 * editor - only say anything once those paths belong to real posts. A fresh
 * WordPress has one post and one page, which demonstrates nothing.
 *
 * Everything created here is marked, so a second run adds nothing and a
 * developer can find and remove it in one query.
 */
final class DemoContent {

	/**
	 * Marks a post as ours, so this is idempotent and reversible.
	 */
	public const MARKER = '_honest_analytics_demo';

	/**
	 * Categories, and the posts that belong to them.
	 */
	private const CATEGORIES = [
		'Guides'        => 'Long-form pieces that answer a question properly.',
		'Notes'         => 'Shorter thoughts, published as they happen.',
		'Case studies'  => 'What we did, and what it changed.',
		'Announcements' => 'Releases and other news.',
	];

	/**
	 * Tags, spread across the posts.
	 */
	private const TAGS = [ 'privacy', 'performance', 'wordpress', 'analytics', 'accessibility', 'caching' ];

	/**
	 * Authors, so the Authors tab has more than one row.
	 *
	 * @return array<int,array{login:string,name:string}>
	 */
	private static function authors(): array {
		return [
			[
				'login' => 'ha_demo_rowan',
				'name'  => 'Rowan Ellis',
			],
			[
				'login' => 'ha_demo_priya',
				'name'  => 'Priya Raman',
			],
			[
				'login' => 'ha_demo_marek',
				'name'  => 'Marek Nowak',
			],
			[
				'login' => 'ha_demo_esi',
				'name'  => 'Esi Boateng',
			],
		];
	}

	/**
	 * Titles for the demo posts.
	 *
	 * @return string[]
	 */
	private static function titles(): array {
		return [
			'Why privacy-first analytics is worth the trade',
			'Counting cached pages without breaking the cache',
			'What a rotating salt actually protects',
			'Reading a bounce rate without fooling yourself',
			'Unique visitors are a daily number, and that matters',
			'The case against storing referrer URLs',
			'How much disk does a year of traffic take?',
			'Global Privacy Control, honoured properly',
			'Sampling, sketches and the ±1.6%',
			'Server-side counting on a fully cached site',
			'What we stopped measuring, and why',
			'Designing an admin screen nobody has to learn',
			'Retention limits that cannot be edited away',
			'A funnel is a question, not a report',
			'Attribution models, briefly',
			'Crawlers are traffic, but they are not people',
			'Accessible charts start with a table',
			'The cost of an analytics request',
			'What we send to third parties: nothing',
			'Migrating two years of history, or not',
			'Scroll depth without a fingerprint',
			'Notes on the WordPress admin colour schemes',
			'A week of hourly detail is usually enough',
			'Answering a subject access request in one command',
		];
	}

	/**
	 * Pages the seeder can attribute traffic to.
	 *
	 * @return array<string,string>
	 */
	private static function pages(): array {
		return [
			'About'          => 'Who we are, in three paragraphs.',
			'Pricing'        => 'What it costs, without a form to fill in.',
			'Contact'        => 'How to reach a person.',
			'Documentation'  => 'Everything the plugin does, written down.',
			'Privacy notice' => 'What this site collects, and what it does not.',
		];
	}

	/**
	 * Create the demo content, unless it is already there.
	 *
	 * @return array{posts:int,pages:int,authors:int,terms:int} What was created.
	 */
	public static function install(): array {
		$created = [
			'posts'   => 0,
			'pages'   => 0,
			'authors' => 0,
			'terms'   => 0,
		];

		if ( self::alreadyInstalled() ) {
			return $created;
		}

		$authors = self::ensureAuthors( $created );
		$terms   = self::ensureTerms( $created );

		require_once ABSPATH . 'wp-admin/includes/post.php';

		foreach ( self::titles() as $index => $title ) {
			$postId = wp_insert_post(
				[
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_title'    => $title,
					'post_content'  => self::body( $title ),
					'post_excerpt'  => '',
					'post_author'   => $authors[ $index % count( $authors ) ],
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( ( count( self::titles() ) - $index ) * DAY_IN_SECONDS * 5 ) ),
					'meta_input'    => [ self::MARKER => 1 ],
				],
				true
			);

			if ( is_wp_error( $postId ) ) {
				continue;
			}

			++$created['posts'];

			wp_set_post_categories( $postId, [ $terms['categories'][ $index % count( $terms['categories'] ) ] ] );

			wp_set_post_tags(
				$postId,
				[
					self::TAGS[ $index % count( self::TAGS ) ],
					self::TAGS[ ( $index + 3 ) % count( self::TAGS ) ],
				]
			);
		}

		foreach ( self::pages() as $title => $summary ) {
			$pageId = wp_insert_post(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => self::body( $summary ),
					'post_author'  => $authors[0],
					'meta_input'   => [ self::MARKER => 1 ],
				],
				true
			);

			if ( ! is_wp_error( $pageId ) ) {
				++$created['pages'];
			}
		}

		// Named back out one key at a time rather than returned whole: the
		// counters travel through ensureAuthors() and ensureTerms() by
		// reference, so by here the array is only known to hold ints under
		// string keys, and the four the caller reads are worth stating.
		return [
			'posts'   => $created['posts'],
			'pages'   => $created['pages'],
			'authors' => $created['authors'],
			'terms'   => $created['terms'],
		];
	}

	/**
	 * Whether a previous run already did this.
	 */
	private static function alreadyInstalled(): bool {
		$existing = get_posts(
			[
				'post_type'        => 'any',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => self::MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'suppress_filters' => false,
			]
		);

		return [] !== $existing;
	}

	/**
	 * Create the demo authors, returning their IDs.
	 *
	 * @param array<string,int> $created Counters, by reference.
	 *
	 * @return int[]
	 */
	private static function ensureAuthors( array &$created ): array {
		$ids = [];

		foreach ( self::authors() as $author ) {
			$existing = get_user_by( 'login', $author['login'] );

			if ( $existing instanceof \WP_User ) {
				$ids[] = (int) $existing->ID;

				continue;
			}

			$userId = wp_insert_user(
				[
					'user_login'   => $author['login'],
					'user_pass'    => wp_generate_password( 32 ),
					'user_email'   => $author['login'] . '@example.test',
					'display_name' => $author['name'],
					'first_name'   => explode( ' ', $author['name'] )[0],
					'last_name'    => explode( ' ', $author['name'] )[1] ?? '',
					'role'         => 'author',
				]
			);

			if ( is_wp_error( $userId ) ) {
				continue;
			}

			++$created['authors'];

			$ids[] = (int) $userId;
		}

		// A site with no author accounts still needs somebody to write the
		// posts, so fall back to whoever is running the command.
		return [] !== $ids ? $ids : [ max( 1, get_current_user_id() ) ];
	}

	/**
	 * Create the demo categories and tags.
	 *
	 * @param array<string,int> $created Counters, by reference.
	 *
	 * @return array{categories:int[]}
	 */
	private static function ensureTerms( array &$created ): array {
		$categories = [];

		foreach ( self::CATEGORIES as $name => $description ) {
			$term = term_exists( $name, 'category' );

			if ( ! is_array( $term ) ) {
				$term = wp_insert_term( $name, 'category', [ 'description' => $description ] );

				if ( is_wp_error( $term ) ) {
					continue;
				}

				++$created['terms'];
			}

			$categories[] = (int) $term['term_id'];
		}

		if ( [] === $categories ) {
			$categories[] = (int) get_option( 'default_category', 1 );
		}

		return [ 'categories' => $categories ];
	}

	/**
	 * Something to fill the post with.
	 *
	 * @param string $title The post's title.
	 */
	private static function body( string $title ): string {
		return sprintf(
			"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->",
			esc_html( $title ) . '. Demo content, created by the Honest Analytics seeder so that the reports have something to attribute traffic to.',
			'It has no editorial value and can be deleted at any time - every post the seeder created carries the ' . self::MARKER . ' meta key.'
		);
	}

	/**
	 * Delete everything a previous run created.
	 *
	 * @return int How many posts were removed.
	 */
	public static function remove(): int {
		$ids = get_posts(
			[
				'post_type'        => 'any',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => self::MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'suppress_filters' => false,
			]
		);

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}

		return count( $ids );
	}
}
