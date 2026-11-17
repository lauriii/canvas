<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\EventSubscriber;

use Drupal\canvas_ssr\HostStatus;
use Drupal\canvas_ssr\JsModules\BaseModules;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\canvas_ssr\Renderer;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Builds a base the runner couldn't take in time, once a response is sent.
 *
 * It runs at the end of every request, page cache hits included, so a site
 * whose pages are all cached still gets its base built. When nothing is
 * waiting, that costs the runner's check. Once the base is built, the
 * islands that fell back to CSR for want of it are invalidated, so they
 * render with SSR.
 *
 * Under PHP-FPM, the response has been sent by then, so no visitor waits.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::buildPendingBase()
 */
final class PendingBaseSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a PendingBaseSubscriber.
   *
   * The services are closures, so a response with nothing to build builds
   * only the runner.
   *
   * @param \Closure(): \Drupal\canvas_ssr\JsRunner\RunnerInterface $runner
   *   Returns the runner renders use.
   * @param \Closure(): \Drupal\canvas_ssr\JsModules\BaseModules $baseModules
   *   Returns the site's base.
   * @param \Closure(): \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   Returns the cache tags invalidator.
   * @param \Closure(): \Drupal\canvas_ssr\HostStatus $hostStatus
   *   Returns where this host records that its base can't be built, for the
   *   status report.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logs a build that failed.
   */
  public function __construct(
    private readonly \Closure $runner,
    private readonly \Closure $baseModules,
    private readonly \Closure $cacheTagsInvalidator,
    private readonly \Closure $hostStatus,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

  /**
   * Builds the pending base, if the runner has one.
   */
  public function onTerminate(): void {
    $runner = ($this->runner)();
    \assert($runner instanceof RunnerInterface);
    try {
      if (!$runner->hasPendingBase()) {
        return;
      }
      $base_modules = ($this->baseModules)();
      \assert($base_modules instanceof BaseModules);
      $result = $runner->buildPendingBase($base_modules->id(), $base_modules->getDefinition(...));
    }
    catch (\Throwable $e) {
      // The response is sent, so there's no one to tell but the log.
      $this->logger->warning('Building the SSR base after a response failed: {message}', ['message' => $e->getMessage()]);
      return;
    }
    if ($result === NULL || $result === FALSE) {
      return;
    }
    $host_status = ($this->hostStatus)();
    \assert($host_status instanceof HostStatus);
    if ($result === TRUE) {
      $invalidator = ($this->cacheTagsInvalidator)();
      \assert($invalidator instanceof CacheTagsInvalidatorInterface);
      $invalidator->invalidateTags([Renderer::BASE_PENDING_TAG]);
      $host_status->recordBaseBuilt();
      return;
    }
    $host_status->recordBaseFailing($result);
    $this->logger->warning('The SSR base still couldn\'t be built, so islands render in the browser until it can. The next try is in {minutes} minutes. {message}', [
      'minutes' => \max(1, (int) \round(($result['due'] - \time()) / 60)),
      'message' => $result['error'],
    ]);
  }

}
