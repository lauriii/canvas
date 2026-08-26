<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\PageVariantResolver;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Selects the Drupal Canvas page display variant when a page variant resolves.
 *
 * @see \Drupal\canvas\PageVariantResolver
 * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant
 * @see \Drupal\Core\Render\RenderEvents
 */
final class PageVariantSelectorSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly PageVariantResolver $resolver,
    private readonly AdminContext $adminContext,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * Selects the Drupal Canvas page display variant.
   *
   * @param \Drupal\Core\Render\PageDisplayVariantSelectionEvent $event
   *   The event to process.
   */
  public function onSelectPageDisplayVariant(PageDisplayVariantSelectionEvent $event): void {
    // Page variants render front-end pages only, never administration routes
    // (the Canvas editor, admin pages, and so on).
    if ($this->adminContext->isAdminRoute($event->getRouteMatch()->getRouteObject())) {
      return;
    }

    // When the request edits a page variant itself (the layout API and the
    // component instance form have it as a route parameter), the edited
    // variant IS the page: wrapping its preview in the route's resolved
    // variant would nest the variant inside page chrome, or even inside
    // itself. Leave core block layout to render the page; the preview
    // renderer strips its regions.
    // @see \Drupal\canvas\Controller\ApiLayoutController::buildPreviewRenderable()
    // @see \Drupal\canvas\Render\MainContent\CanvasPreviewRenderer::prepare()
    foreach ($event->getRouteMatch()->getParameters() as $parameter) {
      if ($parameter instanceof PageVariant) {
        return;
      }
    }

    // The layout API and preview routes opt into rendering the auto-saved
    // (unpublished) draft.
    $is_preview = (bool) $event->getRouteMatch()->getRouteObject()?->getOption('_canvas_use_template_draft');

    $route_entity = self::getRouteEntity($event->getRouteMatch());
    if ($is_preview && $route_entity !== NULL) {
      // In the editor preview, honor the page's pending (auto-saved) template
      // selection so switching templates updates the preview before it is
      // published. The route entity is the published version, whose
      // `page_variant` value still points at the previously selected template.
      // Mirrors CanvasPageVariant::build(), which swaps in the auto-saved
      // variant's own chrome for previews.
      $auto_save = $this->autoSaveManager->getAutoSaveEntity($route_entity);
      if (!$auto_save->isEmpty() && $auto_save->entity instanceof FieldableEntityInterface) {
        $route_entity = $auto_save->entity;
      }
    }

    $variant = $this->resolver->resolve($route_entity);

    // Which variant resolves depends on the default-variant setting (and, for a
    // selection, on the entity or template). Vary the selection on the setting
    // so adding or changing the default invalidates cached pages.
    $event->addCacheableDependency($this->configFactory->get('canvas.settings'));
    if ($variant === NULL) {
      // Render legacy regions when page variant could not be resolved
      // and active theme still has page regions available.
      $regions = PageRegion::loadForActiveTheme();
      if ($regions === []) {
        // No variant or legacy region resolves: leave core block layout to
        // render the page.
        return;
      }
      foreach ($regions as $region) {
        $event->addCacheableDependency($region);
      }
      $event->setPluginId(CanvasPageVariant::PLUGIN_ID);
      $event->setPluginConfiguration([
        CanvasPageVariant::PREVIEW_KEY => $is_preview,
      ]);
      return;
    }
    $event->addCacheableDependency($variant);

    $event->setPluginId(CanvasPageVariant::PLUGIN_ID);
    $event->setPluginConfiguration([
      CanvasPageVariant::PREVIEW_KEY => $is_preview,
      CanvasPageVariant::VARIANT_ID_KEY => $variant->id(),
    ]);
  }

  /**
   * Returns the first content entity among the route parameters, if any.
   */
  private static function getRouteEntity(RouteMatchInterface $route_match): ?FieldableEntityInterface {
    foreach ($route_match->getParameters() as $parameter) {
      if ($parameter instanceof FieldableEntityInterface) {
        return $parameter;
      }
    }
    return NULL;
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
