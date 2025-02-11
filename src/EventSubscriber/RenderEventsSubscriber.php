<?php

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DisplayVariant\PageTemplateDisplayVariant;
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
   * Selects the Experience Builder PageTemplate page display variant.
   *
   * @param \Drupal\Core\Render\PageDisplayVariantSelectionEvent $event
   *   The event to process.
   *
   * @see \Drupal\experience_builder\Plugin\DisplayVariant\PageTemplateDisplayVariant
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    $page_template = PageTemplate::forActiveTheme();
    if (!$page_template) {
      // No active page template for this theme.
      return;
    }

    // If we're previewing a page, see if we have an auto-save version to use.
    $preview = $event->getRouteMatch()->getRouteObject()?->getOption('_xb_use_template_draft');
    if ($preview && $autoSaveData = $this->autoSaveManager->getAutoSaveData($page_template)->data) {
      // Generate a new template based on the auto-saved data.
      try {
        $page_template = $page_template->forAutoSaveData($autoSaveData)->enable();
      }
      catch (ConstraintViolationException) {
        // The autosave entry is invalid, we don't use it and instead fallback
        // to the saved version.
      }
    }

    $event->setPluginId('experience_builder_page_template_display');
    $event->setPluginConfiguration([
      PageTemplateDisplayVariant::PAGE_TEMPLATE_CONFIG_ENTITY_KEY => $page_template,
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
