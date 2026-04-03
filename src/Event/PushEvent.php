<?php

declare(strict_types=1);

namespace Drupal\canvas\Event;

use Drupal\canvas\Push\PushStatus;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a CLI push lifecycle transition occurs.
 */
final class PushEvent extends Event {

  public function __construct(
    public readonly PushStatus $status,
  ) {}

}
