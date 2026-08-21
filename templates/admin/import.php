<?php
/**
 * The Import data screen, and the step it is showing.
 *
 * @package HonestAnalytics
 *
 * @var \HonestAnalytics\Admin\Screens\ImportScreen        $screen
 * @var \HonestAnalytics\Admin\RequestParams               $params
 * @var string                                             $step
 * @var \HonestAnalytics\Import\ImporterInterface|null     $importer
 * @var \HonestAnalytics\Import\ImporterInterface[]        $importers
 * @var \HonestAnalytics\Import\ImportJob|null             $job
 * @var string                                             $notice
 * @var bool                                               $noticeIsProblem
 * @var bool                                               $available
 * @var array<string,mixed>|null                          $connection
 */

declare(strict_types=1);

use HonestAnalytics\Admin\Views\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( '' !== $notice ) : ?>
	<div class="ha-info<?php echo $noticeIsProblem ? ' is-warning' : ''; ?>">
		<p><?php echo esc_html( $notice ); ?></p>
	</div>
<?php endif; ?>

<?php
View::render(
	'admin/import/' . $step,
	[
		'screen'     => $screen,
		'params'     => $params,
		'importer'   => $importer,
		'importers'  => $importers,
		'job'        => $job,
		'available'  => $available,
		'connection' => $connection,
	]
);
