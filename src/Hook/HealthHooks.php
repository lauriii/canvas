<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Health\Doctor;
use Drupal\canvas\Health\HealthRecords;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;

/**
 * Cron and pruning hooks for incremental Canvas data-health validation.
 *
 * @internal
 */
final class HealthHooks {

  public const string CURSOR_STATE_KEY = 'canvas.doctor.cron_cursor';

  public function __construct(
    private readonly Doctor $engine,
    private readonly StateInterface $state,
    private readonly HealthRecords $healthRecords,
  ) {}

  /**
   * Builds data-health coverage on sites that never run the CLI.
   *
   * @see \Drupal\canvas\Health\Doctor::runCronWindow()
   */
  #[Hook('cron')]
  public function cron(): void {
    $cursor = (int) $this->state->get(self::CURSOR_STATE_KEY, 0);
    $result = $this->engine->runCronWindow($cursor);
    $next = $result['wrapped'] ? 0 : $cursor + $result['processed'];
    $this->state->set(self::CURSOR_STATE_KEY, $next);
  }

  /**
   * Prunes all stored results of a deleted entity.
   *
   * Includes its revisions and auto-save snapshots, pruned at the moment they
   * become orphans.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->healthRecords->deleteForEntity($entity);
  }

  /**
   * Prunes the stored result of a single deleted revision.
   */
  #[Hook('entity_revision_delete')]
  public function entityRevisionDelete(EntityInterface $entity): void {
    if ($entity instanceof RevisionableInterface) {
      $this->healthRecords->deleteForRevision($entity);
    }
  }

}
