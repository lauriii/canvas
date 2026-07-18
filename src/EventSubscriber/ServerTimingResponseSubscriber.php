<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\Render\ServerTiming;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds a Server-Timing header when Canvas endpoints recorded stage timings.
 *
 * @see \Drupal\canvas\Render\ServerTiming
 */
final class ServerTimingResponseSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ServerTiming $serverTiming,
  ) {
  }

  public function onResponse(ResponseEvent $event): void {
    if ($event->isMainRequest() && $this->serverTiming->hasMetrics()) {
      $event->getResponse()->headers->set('Server-Timing', $this->serverTiming->getHeaderValue());
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', -1000]];
  }

}
