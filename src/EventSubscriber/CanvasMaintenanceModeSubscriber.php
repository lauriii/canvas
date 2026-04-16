<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\Core\EventSubscriber\MaintenanceModeSubscriber;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\MaintenanceModeEvents;
use Drupal\Core\Site\MaintenanceModeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Skips core's exempt-user maintenance message on Canvas routes.
 */
final class CanvasMaintenanceModeSubscriber implements EventSubscriberInterface {

  public function __construct(
    #[Autowire(service: '.inner')]
    private readonly MaintenanceModeSubscriber $inner,
    private readonly MaintenanceModeInterface $maintenanceMode,
    private readonly AccountInterface $account,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly KillSwitch $pageCacheKillSwitch,
  ) {
  }

  /**
   * Mirrors core's request-phase maintenance handling with a Canvas bypass.
   */
  public function onKernelRequestMaintenance(RequestEvent $event): void {
    $request = $event->getRequest();
    $route_match = RouteMatch::createFromRequest($request);
    if (!$this->maintenanceMode->applies($route_match)) {
      return;
    }
    $this->pageCacheKillSwitch->trigger();
    if (!$this->maintenanceMode->exempt($this->account)) {
      $this->eventDispatcher->dispatch($event, MaintenanceModeEvents::MAINTENANCE_MODE_REQUEST);
      return;
    }
    if ($this->shouldSkipCoreMaintenanceBanner($route_match)) {
      return;
    }
    $this->inner->onKernelRequestMaintenance($event);
  }

  /**
   * Whether to skip queuing core's offline message for this request.
   */
  private function shouldSkipCoreMaintenanceBanner(RouteMatchInterface $route_match): bool {
    $route_name = $route_match->getRouteName();
    if (\is_string($route_name)) {
      return str_starts_with($route_name, 'canvas.') || str_starts_with($route_name, 'canvas_ai.') || $route_name === 'system.csrftoken';
    }

    return FALSE;
  }

  public function onMaintenanceModeRequest(RequestEvent $event): void {
    $this->inner->onMaintenanceModeRequest($event);
  }

  public function onTerminate(TerminateEvent $event): void {
    $this->inner->onTerminate($event);
  }

  public static function getSubscribedEvents(): array {
    return MaintenanceModeSubscriber::getSubscribedEvents();
  }

}
