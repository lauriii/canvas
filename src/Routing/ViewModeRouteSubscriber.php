<?php

declare(strict_types=1);

namespace Drupal\canvas\Routing;

use Drupal\canvas\ContentTemplateRepository;
use Drupal\canvas\Controller\ViewModeDisplayController;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters routes for view mode display to use Canvas alternative.
 */
final class ViewModeRouteSubscriber extends RouteSubscriberBase {

  public function __construct(
    private readonly ContentTemplateRepository $contentTemplateRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($this->contentTemplateRepository->getSupportedEntityTypeIds() as $entity_type_id) {
      $route = $collection->get(ContentTemplateRepository::getViewModeDisplayRouteName($entity_type_id));
      $route?->setDefault('_controller', ViewModeDisplayController::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    // Register alterRoutes with high priority to ensure it runs after
    // "field_ui" route subscriber.
    // @see \Drupal\field_ui\Routing\RouteSubscriber::getSubscribedEvents()
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -110];
    return $events;
  }

}
