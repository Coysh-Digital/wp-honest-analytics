<?php
/**
 * Base admin screen.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Admin\Screens;

use HonestAnalytics\Admin\Assets;
use HonestAnalytics\Admin\RequestParams;
use HonestAnalytics\Admin\Views\View;
use HonestAnalytics\Capabilities\Capabilities;
use HonestAnalytics\Edition\Edition;
use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What every report screen has in common.
 */
abstract class Screen {

	/**
	 * The hook suffix WordPress gave this screen.
	 */
	protected string $hook = '';

	/**
	 * The request state.
	 */
	protected ?RequestParams $params = null;

	/**
	 * The page slug.
	 */
	abstract public function slug(): string;

	/**
	 * The page title.
	 */
	abstract public function title(): string;

	/**
	 * Render the screen.
	 */
	abstract public function render(): void;

	/**
	 * The menu label, when it differs from the title.
	 */
	public function menuLabel(): string {
		return $this->title();
	}

	/**
	 * The capability needed to see it.
	 */
	public function capability(): string {
		return Capabilities::VIEW;
	}

	/**
	 * Whether this screen is part of the Pro edition.
	 */
	public function isPro(): bool {
		return false;
	}

	/**
	 * Whether this screen has a date range and an export button.
	 */
	public function hasRange(): bool {
		return true;
	}

	/**
	 * The heading at the top of the screen.
	 *
	 * Distinct from title(), which names the menu item. A detail view is still
	 * the Pages screen as far as the menu is concerned, but its heading is the
	 * path being looked at - and there is only ever one h1 on a page.
	 */
	public function heading(): string {
		return $this->title();
	}

	/**
	 * A class for the heading, where it needs one.
	 */
	public function headingClass(): string {
		return '';
	}

	/**
	 * What this screen exports, if anything.
	 */
	public function exportKind(): ?string {
		return null;
	}

	/**
	 * Whether this screen draws charts.
	 */
	public function usesCharts(): bool {
		return true;
	}

	/**
	 * Add the screen to the menu.
	 *
	 * @param string $parent Parent slug.
	 */
	public function register( string $parent ): string {
		// The screen that shares the parent's slug must not supply a callback.
		// `add_menu_page()` has already bound one to the same hook, and adding a
		// second renders the whole screen twice.
		$isParent = $this->slug() === $parent;

		$hook = add_submenu_page(
			$parent,
			$this->title() . ' - ' . __( 'Analytics', 'honest-analytics' ),
			$this->menuLabel(),
			$this->capability(),
			$this->slug(),
			$isParent ? '' : [ $this, 'renderPage' ]
		);

		return $this->bind( (string) $hook );
	}

	/**
	 * Attach the per-screen hooks to a hook suffix.
	 *
	 * @param string $hook Hook suffix from add_menu_page or add_submenu_page.
	 */
	public function bind( string $hook ): string {
		if ( '' === $hook ) {
			return '';
		}

		$this->hook = $hook;

		add_action( 'load-' . $hook, [ $this, 'load' ] );
		add_action( 'admin_print_styles-' . $hook, [ $this, 'enqueue' ] );

		return $hook;
	}

	/**
	 * Run before the screen renders.
	 */
	public function load(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view analytics for this site.', 'honest-analytics' ),
				esc_html__( 'Not allowed', 'honest-analytics' ),
				[ 'response' => 403 ]
			);
		}

		// The gate sits in front of the queries, not in front of the markup: a
		// site that has lapsed to Lite still has its Pro rows, and a template
		// check would happily render them.
		if ( $this->isPro() ) {
			Edition::requirePro();
		}

		// No migration here. This used to be the only place `maybeUpgrade()` ran
		// from, which made an ALTER against a table of millions the price of
		// opening a report - and left a site nobody had signed into running its
		// drain against a schema it could not write to. Cron and the CLI
		// bootstrap do it now, and Health says so while it is outstanding.
		$this->params = new RequestParams( $this->slug() );
	}

	/**
	 * Enqueue this screen's assets.
	 */
	public function enqueue(): void {
		Assets::admin( $this->usesCharts() );
	}

	/**
	 * Render the whole page, chrome included.
	 */
	public function renderPage(): void {
		if ( null === $this->params ) {
			$this->params = new RequestParams( $this->slug() );
		}

		View::render(
			'admin/layout/header',
			[
				'screen' => $this,
				'params' => $this->params,
				'plugin' => Plugin::instance(),
			]
		);

		$this->render();

		View::render( 'admin/layout/footer' );
	}

	/**
	 * The request state.
	 */
	public function params(): RequestParams {
		if ( null === $this->params ) {
			$this->params = new RequestParams( $this->slug() );
		}

		return $this->params;
	}

	/**
	 * The maximum width this screen renders at.
	 *
	 * Reports are wide because they are tables and charts. Privacy and Settings
	 * are narrower because they are prose, and prose at 1180 pixels is hard to
	 * read.
	 */
	public function maxWidth(): int {
		return 1180;
	}

	/**
	 * The site this screen reports on.
	 */
	protected function siteId(): int {
		return get_current_blog_id();
	}
}
