<?php

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Selects the Experience Builder page display variant.
 *
 * @see \Drupal\Core\Render\RenderEvents
 */
final class PageVariantSelectorSubscriber implements EventSubscriberInterface {

  /**
   * Selects the Experience Builder page display variant.
   *
   * @param \Drupal\Core\Render\PageDisplayVariantSelectionEvent $event
   *   The event to process.
   *
   * @see \Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    $regions = PageRegion::loadForActiveTheme();
    if (empty($regions)) {
      // No active page regions for this theme.
      return;
    }
    $event->setPluginId(XbPageVariant::PLUGIN_ID);
    $event->setPluginConfiguration([
      XbPageVariant::PREVIEW_KEY => $event->getRouteMatch()->getRouteObject()?->getOption('_xb_use_template_draft'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // This must run after all other page variant subscribers.
    // @see \Drupal\block\EventSubscriber\BlockPageDisplayVariantSubscriber
    $events[RenderEvents::SELECT_PAGE_DISPLAY_VARIANT][] = ['onSelectPageDisplayVariant', -100];
    return $events;
  }

}
