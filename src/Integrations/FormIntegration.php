<?php
/**
 * What every form plugin integration has in common.
 *
 * @package HonestAnalytics
 */

declare(strict_types=1);

namespace HonestAnalytics\Integrations;

use HonestAnalytics\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shared half of a form integration.
 *
 * Five form plugins, five different hook signatures, one thing to do with them:
 * turn a form id and a page URL into an event. The differences live in the
 * subclasses and are only ever a few lines each; everything below is the part
 * that has to behave identically whichever plugin fired.
 *
 * Note what is not here. There is no method that takes a field, a value, an
 * entry or a `$_POST`. A subclass that wanted to record what somebody typed
 * would have to reach past this class to do it, which is the point.
 */
abstract class FormIntegration implements IntegrationInterface {

	use ResolvesPage;

	/**
	 * Submissions already counted in this request.
	 *
	 * A submission hook fires once per submission, and then occasionally twice,
	 * because another plugin re-ran it or the form plugin retried an AJAX
	 * handler. Counting an enquiry twice is the kind of error that never looks
	 * like an error, so the guard is cheap and always on.
	 *
	 * @var array<string,true>
	 */
	private static array $seen = [];

	/**
	 * Record one submission.
	 *
	 * @param int         $formId  The form's id.
	 * @param string|null $url     The page the form was on, if the plugin knows.
	 * @param int|string  $entryId The entry's id, used only to spot a repeat.
	 */
	final protected function record( int $formId, ?string $url = null, int|string $entryId = 0 ): bool {
		if ( ! $this->claimSubmission( $formId, $entryId ) ) {
			return false;
		}

		return Plugin::instance()->trackEvent(
			EventNames::form( $this->slug(), $formId ),
			null,
			$this->pathFrom( $url ),
			$this->postIdFrom( $url )
		);
	}

	/**
	 * Claim the right to count this submission, once.
	 *
	 * Separate from record() so the guard can be exercised without a database
	 * behind it, and because "have I already counted this" is a different
	 * question from "what shall I call it".
	 *
	 * @param int        $formId  The form's id.
	 * @param int|string $entryId The entry's id, where the plugin has one.
	 */
	final protected function claimSubmission( int $formId, int|string $entryId = 0 ): bool {
		$key = $this->slug() . ':' . $formId . ':' . $entryId;

		if ( isset( self::$seen[ $key ] ) ) {
			return false;
		}

		self::$seen[ $key ] = true;

		return true;
	}

	/**
	 * Forget which submissions have been seen. For tests.
	 */
	final public static function forgetSeen(): void {
		self::$seen = [];
	}

	/**
	 * The label for one of this integration's handles.
	 *
	 * @param string $eventName The stored event name.
	 */
	public function describeEvent( string $eventName ): ?string {
		$parsed = EventNames::parseForm( $eventName );

		if ( null === $parsed || $parsed['slug'] !== $this->slug() || ! $this->isInstalled() ) {
			return null;
		}

		$title = $this->titleFor( $parsed['formId'] );

		// An empty title means the form could not be found - deleted, or on
		// another site of the network. Declining leaves the handle on screen,
		// which is honest. Answering would put a name on a form nobody can
		// open, and the reader would have no way to tell the difference.
		if ( '' === trim( $title ) ) {
			return null;
		}

		return EventNames::label( $this->name(), $title, $parsed['formId'] );
	}

	/**
	 * What the plugin currently calls a form.
	 *
	 * Looked up rather than stored, so a rename shows up in the report without
	 * anything having to be migrated.
	 *
	 * @param int $formId The form's id.
	 */
	abstract protected function titleFor( int $formId ): string;
}
