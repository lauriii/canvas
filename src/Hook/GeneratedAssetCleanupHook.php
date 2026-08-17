<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\GeneratedAssetCleanup;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Cron hook for generated asset cleanup.
 */
final class GeneratedAssetCleanupHook {

  public function __construct(
    private readonly GeneratedAssetCleanup $generatedAssetCleanup,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function __invoke(): void {
    $this->generatedAssetCleanup->deleteStaleFiles();
  }

}
