<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\CanvasNotificationHandler;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Cron hook for notification cleanup.
 */
class NotificationCronHook {

  public function __construct(
    private readonly CanvasNotificationHandler $notificationHandler,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function __invoke(): void {
    $this->notificationHandler->purgeStaleProcessing();
    $this->notificationHandler->deleteExpired();
  }

}
