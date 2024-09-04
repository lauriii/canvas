<?php

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\experience_builder\RenderArrayXB;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class XbMainContentViewSubscriber implements EventSubscriberInterface {

  public function markRenderArrayXB(ViewEvent $event): void {
    $result = $event->getControllerResult();
    // Travel through the render array and if any item has `#is_xb` set to TRUE,
    // set `#is_xb` to TRUE on all children as well. Elements with #is_xb set
    // to TRUE will have additional theme suggestions that render with React
    // instead of Twig.
    // @see experience_builder_theme_suggestions_alter()
    if (is_array($result)) {
      if (!empty($result['#is_xb'])) {
        RenderArrayXB::markXB($result);
      }
      else {
        RenderArrayXB::findAndMarkXB($result);
      }
    }

    $event->setControllerResult($result);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::VIEW][] = ['markRenderArrayXB', 50];

    return $events;
  }

}
