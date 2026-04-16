<?php

declare(strict_types=1);

namespace Drupal\canvas_test_preview_messenger\EventSubscriber;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Test-only: adds a status on layout GET when the gate is set.
 *
 * The gate matches when any of these are true:
 * - request header X-Canvas-Test-Preview-Message: 1
 * - query parameter canvas_test_preview_message=1 on the layout XHR itself
 * - query parameter canvas_test_preview_message=1 on the Referer URL (so
 *   adding ?canvas_test_preview_message=1 to the editor page URL is enough
 *   to surface a toast on every subsequent layout fetch for UI smoke tests)
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
    $gateOk = ($header !== NULL && trim($header) === '1')
      || $query === '1'
      || $this->refererHasGate($request->headers->get('referer'));
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

  /**
   * Whether the Referer carries canvas_test_preview_message=1.
   */
  private function refererHasGate(?string $referer): bool {
    if (!\is_string($referer) || $referer === '') {
      return FALSE;
    }
    $query = \parse_url($referer, PHP_URL_QUERY);
    if (!\is_string($query) || $query === '') {
      return FALSE;
    }
    $pairs = [];
    \parse_str($query, $pairs);
    return (($pairs[self::QUERY_PARAM] ?? NULL) === '1');
  }

}
