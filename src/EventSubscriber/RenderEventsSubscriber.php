<?php

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\Plugin\DisplayVariant\PageTemplateDisplayVariant;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Experience Builder's reactions to Drupal core's RenderEvents.
 *
 * @see \Drupal\Core\Render\RenderEvents
 */
final class RenderEventsSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
    $active_theme_name = $this->themeManager->getActiveTheme()->getName();
    $page_template = $this->entityTypeManager
      ->getStorage('page_template')
      ->load($active_theme_name);

    // For this theme, an Experience Builder PageTemplate config entity exists.
    if ($page_template instanceof PageTemplate && $page_template->status()) {
      $event->setPluginId('experience_builder_page_template_display');
      $event->setPluginConfiguration([
        PageTemplateDisplayVariant::PAGE_TEMPLATE_CONFIG_ENTITY_KEY => $page_template,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[RenderEvents::SELECT_PAGE_DISPLAY_VARIANT][] = ['onSelectPageDisplayVariant'];
    return $events;
  }

}
