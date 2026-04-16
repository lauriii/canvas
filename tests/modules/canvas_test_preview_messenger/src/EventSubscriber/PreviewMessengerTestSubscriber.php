<?php

declare(strict_types=1);

namespace Drupal\canvas_test_preview_messenger\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Test-only: adds a status on layout GET when the gated header or query parameter is set.
 *
 * Uses VIEW at priority 100 so the message is present before preview JSON is built.
 */
final class PreviewMessengerTestSubscriber implements EventSubscriberInterface {

  public const HEADER_NAME = 'X-Canvas-Test-Preview-Message';

  public const QUERY_PARAM = 'canvas_test_preview_message';

  public const PROBE_MESSAGE = 'Playwright preview messenger probe.';

  public function __construct(
    private readonly MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::VIEW => ['onView', 100]];
  }

  public function onView(ViewEvent $event): void {
    $request = $event->getRequest();
    $header = $request->headers->get(self::HEADER_NAME);
    $query = $request->query->get(self::QUERY_PARAM);
    $gateOk = ($header !== NULL && trim($header) === '1') || $query === '1';
    if (!$gateOk) {
      return;
    }
    $routeName = $request->attributes->get(RouteObjectInterface::ROUTE_NAME);
    if (!\is_string($routeName)) {
      return;
    }
    if (!\in_array($routeName, [
      'canvas.api.layout.get',
      'canvas.api.layout.get.content_template',
    ], TRUE)) {
      return;
    }
    $this->messenger->addStatus(self::PROBE_MESSAGE);
  }

}
