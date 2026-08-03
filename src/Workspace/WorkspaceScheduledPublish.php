<?php

declare(strict_types=1);

namespace Drupal\canvas\Workspace;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Utility\Error;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publishes workspaces whose scheduled publish time has passed.
 *
 * Cron runs the exact same validated, review-gated pipeline as the publish
 * button. A failure cancels the schedule and records the error on the
 * workspace (surfaced in the review panel) instead of retrying blindly.
 *
 * @see \Drupal\canvas\Workspace\CanvasWorkspacePublisher
 */
final class WorkspaceScheduledPublish {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CanvasWorkspacePublisher $publisher,
    private readonly TimeInterface $time,
    private readonly AccountSwitcherInterface $accountSwitcher,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Publishes every workspace whose scheduled time has passed.
   *
   * @return int
   *   The number of workspaces published.
   */
  public function publishDue(): int {
    $storage = $this->entityTypeManager->getStorage('workspace');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('canvas_scheduled_publish_at', $this->time->getRequestTime(), '<=')
      ->exists('canvas_scheduled_publish_at')
      ->execute();
    $published = 0;
    foreach ($storage->loadMultiple($ids) as $workspace) {
      /** @var \Drupal\workspaces\WorkspaceInterface $workspace */
      $scheduler_id = (int) ($workspace->get('canvas_scheduled_publish_by')->target_id ?? 0);
      // Cron publishes on behalf of the user who set the schedule: the
      // per-item update access checks in the pipeline run against them. The
      // full user entity is needed — a bare UserSession would carry no roles.
      $account = $this->entityTypeManager->getStorage('user')->load($scheduler_id > 0 ? $scheduler_id : 1);
      if (!$account instanceof AccountInterface) {
        $this->cancelWithError($workspace, \sprintf('The scheduling user %d no longer exists.', $scheduler_id));
        continue;
      }
      $this->accountSwitcher->switchTo($account);
      try {
        $this->publisher->publish((string) $workspace->id(), $account);
        $published++;
        $this->logger->info('Published the scheduled workspace %workspace.', ['%workspace' => (string) $workspace->label()]);
      }
      catch (WorkspacePublishValidationException $e) {
        $this->cancelWithError($workspace, self::formatValidationError($e));
      }
      catch (\Throwable $e) {
        Error::logException($this->logger, $e);
        $this->cancelWithError($workspace, $e->getMessage());
      }
      finally {
        $this->accountSwitcher->switchBack();
      }
    }
    return $published;
  }

  /**
   * Cancels a failed schedule and records the failure on the workspace.
   */
  private function cancelWithError(object $workspace, string $error): void {
    /** @var \Drupal\workspaces\WorkspaceInterface $workspace */
    $workspace->set('canvas_scheduled_publish_at', NULL);
    $workspace->set('canvas_scheduled_publish_by', NULL);
    $workspace->set('canvas_scheduled_publish_error', $error);
    $workspace->save();
    $this->logger->error('The scheduled publish of workspace %workspace was cancelled: @error', [
      '%workspace' => (string) $workspace->label(),
      '@error' => $error,
    ]);
  }

  private static function formatValidationError(WorkspacePublishValidationException $e): string {
    $labels = [];
    foreach ($e->getViolationSets() as $set) {
      // @phpstan-ignore-next-line
      $entity = $set->entity ?? NULL;
      $labels[] = $entity !== NULL ? (string) $entity->label() : 'unknown item';
    }
    return \sprintf('Validation failed for: %s.', \implode(', ', \array_unique($labels)));
  }

}
