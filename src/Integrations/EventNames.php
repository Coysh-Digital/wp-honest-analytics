<?php
/**
 * How an integration's events are named.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable handles, because a goal has to outlive a rename.
 *
 * An event goal matches on the event name, exactly. If the name were the form's
 * title then renaming "Contact us" to "Get in touch" would break every goal
 * pointing at it and split the events report into two rows that look like two
 * forms. So the stored name is a handle built from the integration's slug and
 * the form's numeric id, and the title is looked up again at the moment of
 * display.
 *
 * The handle is ugly on purpose. It is an identifier, not a label, and
 * `Registry::label()` is what turns it back into English.
 */
final class EventNames {

	/** Everything a form submission produces starts with this. */
	public const FORM_PREFIX = 'form:';

	/** A completed order. */
	public const PURCHASE = 'purchase';

	/** Money given back. Recorded with a negative value. */
	public const REFUND = 'purchase refunded';

	/** Something added to a basket. */
	public const ADD_TO_CART = 'add to cart';

	/**
	 * The handle for one form.
	 *
	 * @param string $slug   Integration slug.
	 * @param int    $formId The form's id in that plugin.
	 */
	public static function form( string $slug, int $formId ): string {
		$slug = self::slug( $slug );

		if ( '' === $slug || $formId <= 0 ) {
			// A form with no id is not identifiable, so it is counted as one
			// anonymous bucket rather than silently dropped or, worse, given a
			// name that collides with a real form's.
			return self::FORM_PREFIX . ( '' !== $slug ? $slug : 'unknown' );
		}

		return self::FORM_PREFIX . $slug . '-' . $formId;
	}

	/**
	 * Read a handle back, if it is one.
	 *
	 * @param string $eventName The stored event name.
	 *
	 * @return array{slug:string,formId:int}|null
	 */
	public static function parseForm( string $eventName ): ?array {
		$eventName = trim( $eventName );

		if ( ! str_starts_with( $eventName, self::FORM_PREFIX ) ) {
			return null;
		}

		$rest = substr( $eventName, strlen( self::FORM_PREFIX ) );

		if ( 1 !== preg_match( '/^([a-z0-9]+)(?:-([0-9]+))?$/', $rest, $matches ) ) {
			return null;
		}

		return [
			'slug'   => $matches[1],
			'formId' => isset( $matches[2] ) ? (int) $matches[2] : 0,
		];
	}

	/**
	 * A label built from a plugin's name and the form's current title.
	 *
	 * @param string $pluginName The integration's name.
	 * @param string $title      The form's title now, which may be empty.
	 * @param int    $formId     The form's id, for when it has no title.
	 */
	public static function label( string $pluginName, string $title, int $formId ): string {
		$title = trim( wp_strip_all_tags( $title ) );

		if ( '' === $title ) {
			$title = $formId > 0
				? sprintf(
					/* translators: %d: the form's numeric id. */
					__( 'form %d', 'honest-analytics' ),
					$formId
				)
				: __( 'untitled form', 'honest-analytics' );
		}

		return sprintf(
			/* translators: 1: the form's title, 2: the plugin it belongs to. */
			__( '%1$s (%2$s)', 'honest-analytics' ),
			mb_substr( $title, 0, 100 ),
			$pluginName
		);
	}

	/**
	 * Reduce a slug to the characters a handle may contain.
	 *
	 * Deliberately not sanitize_key(): a handle allows no hyphens or
	 * underscores, because the hyphen is the separator between the slug and
	 * the id and an underscore would make two slugs that read the same.
	 *
	 * @param string $slug Raw slug.
	 */
	private static function slug( string $slug ): string {
		$clean = preg_replace( '/[^a-z0-9]/', '', strtolower( trim( $slug ) ) );

		return is_string( $clean ) ? substr( $clean, 0, 24 ) : '';
	}
}
