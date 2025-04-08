<?php

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Experience Builder's reactions to Drupal core's RenderEvents.
 *
 * @see \Drupal\Core\Render\RenderEvents
 */
final class RenderEventsSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

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

    // If we're previewing a page, see if we have an auto-save version to use.
    $preview = $event->getRouteMatch()->getRouteObject()?->getOption('_xb_use_template_draft');
    if ($preview) {
      $contexts = $event->getContexts();
      // Instruct XbPageVariant to render a preview.
      $contexts[XbPageVariant::XB_PREVIEW_CONTEXT] = new Context(
        context_definition: new ContextDefinition(data_type: 'boolean', required: TRUE),
        context_value: TRUE
      );
      $event->setContexts($contexts);
      foreach ($regions as &$region) {
        $autoSaveData = $this->autoSaveManager->getAutoSaveData($region)->data;
        if ($autoSaveData) {
          try {
            $region = $region->forAutoSaveData($autoSaveData);
          }
          catch (ConstraintViolationException) {
            // The auto-save entry is invalid, we don't use it and instead fallback
            // to the saved version.
          }
        }
      }
    }

    $event->setPluginId(XbPageVariant::PLUGIN_ID);
    $event->setPluginConfiguration([
      XbPageVariant::REGION_CONFIG_ENTITIES_KEY => $regions,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[RenderEvents::SELECT_PAGE_DISPLAY_VARIANT][] = ['onSelectPageDisplayVariant'];
    return $events;
  }

}
