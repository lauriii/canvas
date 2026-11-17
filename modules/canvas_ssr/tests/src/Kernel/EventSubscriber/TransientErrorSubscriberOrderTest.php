<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\EventSubscriber;

use Drupal\canvas_ssr\EventSubscriber\TransientErrorSubscriber;
use Drupal\Core\EventSubscriber\FinishResponseSubscriber;
use Drupal\Core\EventSubscriber\HtmlResponseSubscriber;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the transient error subscriber runs after Drupal's own.
 *
 * FinishResponseSubscriber sets the headers it changes, and
 * HtmlResponseSubscriber renders placeholders, which add to the page's
 * cacheability. Run before either, and its changes would be overwritten or
 * miss an island.
 *
 * @legacy-covers \Drupal\canvas_ssr\EventSubscriber\TransientErrorSubscriber
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class TransientErrorSubscriberOrderTest extends CanvasSsrKernelTestBase {

  /**
   * Tests it runs after the subscribers that set headers and cacheability.
   */
  public function testRunsAfterCoreResponseSubscribers(): void {
    $dispatcher = $this->container->get(EventDispatcherInterface::class);
    \assert($dispatcher instanceof EventDispatcherInterface);
    $order = [];
    foreach ($dispatcher->getListeners(KernelEvents::RESPONSE) as $index => $listener) {
      if (\is_array($listener) && \is_object($listener[0])) {
        $order[$listener[0]::class][] = $index;
      }
    }

    self::assertArrayHasKey(TransientErrorSubscriber::class, $order);
    foreach ([FinishResponseSubscriber::class, HtmlResponseSubscriber::class] as $before) {
      self::assertArrayHasKey($before, $order);
      self::assertGreaterThan(\max($order[$before]), \min($order[TransientErrorSubscriber::class]), $before);
    }
  }

}
