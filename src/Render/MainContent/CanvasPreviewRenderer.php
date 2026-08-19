<?php

declare(strict_types=1);

namespace Drupal\canvas\Render\MainContent;

use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\MainContent\HtmlRenderer;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderCacheInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A *private* main content renderer for endpoints returning preview markup.
 *
 * It is private because it is not exposed as a `render.main_content_renderer`-
 * tagged service. Used only by PreviewEnvelopeViewSubscriber.
 *
 * Overrides the default HTML renderer to remove the page_top and page_bottom
 * regions, to remove the toolbar and any other extraneous markup in previews,
 * and returns a JSON response containing the rendered HTML.
 *
 * Unlike CanvasTemplateRenderer the output of this renderer is intended to be
 * displayed in an iframe, so assets are included in the HTML instead of being
 * handled separately.
 *
 * Status messages are not rendered in the preview. They are returned under
 * `messages` instead, for the Canvas UI to display.
 *
 * @see \Drupal\canvas\EventSubscriber\PreviewEnvelopeViewSubscriber::onViewPreviewEnvelope
 */
final class CanvasPreviewRenderer extends HtmlRenderer {

  public function __construct(
    TitleResolverInterface $title_resolver,
    #[Autowire(service: 'plugin.manager.display_variant')]
    PluginManagerInterface $display_variant_manager,
    EventDispatcherInterface $event_dispatcher,
    ModuleHandlerInterface $module_handler,
    RendererInterface $renderer,
    RenderCacheInterface $render_cache,
    #[Autowire(param: 'renderer.config')]
    array $renderer_config,
    ThemeManagerInterface $theme_manager,
    #[Autowire(service: 'html_response.attachments_processor')]
    private readonly AttachmentsResponseProcessorInterface $attachmentsResponseProcessor,
    private readonly MessengerInterface $messenger,
  ) {
    parent::__construct($title_resolver, $display_variant_manager, $event_dispatcher, $module_handler, $renderer, $render_cache, $renderer_config, $theme_manager);
  }

  /**
   * {@inheritdoc}
   *
   * This renderer renders the HTML, processes the attachments, and wraps it
   * in a JSON response for the frontend to consume.
   *
   * @see \Drupal\Core\EventSubscriber\HtmlResponseSubscriber
   */
  public function renderResponse(array $main_content, Request $request, RouteMatchInterface $route_match, array $additionalData = []): JsonResponse {
    $response = parent::renderResponse($main_content, $request, $route_match);
    \assert($response instanceof AttachmentsInterface);
    $response = $this->attachmentsResponseProcessor->processAttachments($response);
    \assert($response instanceof Response);

    return new JsonResponse([
      'html' => $response->getContent(),
      // Collected after rendering, so that messages added while rendering a
      // component are included too.
      'messages' => $this->collectMessages(),
    ] + $additionalData);
  }

  /**
   * Takes all messages, as plain text, for the Canvas UI to display.
   *
   * @return array
   *   A list of ['type' => string, 'message' => string] arrays.
   *
   * @see \Drupal\Core\Render\Element\StatusMessages::renderMessages()
   */
  private function collectMessages(): array {
    $messages = [];
    foreach ($this->messenger->deleteAll() as $type => $messages_of_type) {
      \assert(\is_array($messages_of_type));
      foreach ($messages_of_type as $message) {
        // A message is documented to be a string or Markup, but a render array
        // is accepted too, and Canvas itself adds one.
        // @see \Drupal\canvas\Hook\FieldUiHooks::formEntityViewDisplayEditFormAlter()
        if (\is_array($message)) {
          $message = $this->renderer->renderInIsolation($message);
        }
        $messages[] = [
          'type' => $type,
          'message' => PlainTextOutput::renderFromHtml((string) $message),
        ];
      }
    }
    return $messages;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepare(array $main_content, Request $request, RouteMatchInterface $route_match) {
    [$page, $title] = parent::prepare($main_content, $request, $route_match);

    // When editing a content template for a non-full view mode, global regions
    // are not part of the display. Strip them so they are not rendered or
    // annotated for the editor.
    if ($main_content['#canvas_hide_global_regions'] ?? FALSE) {
      foreach (Element::children($page) as $region) {
        if ($region !== CanvasPageVariant::MAIN_CONTENT_REGION) {
          $page[$region] = [];
        }
      }
      return [$page, $title];
    }

    foreach (Element::children($page) as $region) {
      if ($region === CanvasPageVariant::MAIN_CONTENT_REGION) {
        continue;
      }
      // Empty regions don't need HTML comments to inform the Canvas UI; empty
      // regions are not visible. They can only be reached by right-clicking in
      // the UI and moving it to such a not yet visible region.
      if ($page[$region] === []) {
        continue;
      }
      $page_regions = PageRegion::loadForActiveThemeByClientSideId();
      if (!empty($page_regions)) {
        $access = $page_regions[$region]->access('edit', return_as_object: TRUE);
        if ($access->isAllowed()) {
          $page[$region]['#prefix'] = Markup::create("<!-- canvas-region-start-$region -->");
          $page[$region]['#suffix'] = Markup::create("<!-- canvas-region-end-$region -->");
        }
        $cacheableMetadata = CacheableMetadata::createFromRenderArray($page[$region]);
        $cacheableMetadata->addCacheableDependency($access);
        $cacheableMetadata->applyTo($page[$region]);
      }
      // @see canvas_preprocess_region()
      $page[$region]['#canvas_region_preview'] = TRUE;
    }
    return [$page, $title];
  }

  /**
   * {@inheritdoc}
   */
  public function buildPageTopAndBottom(array &$html, array $page_top = [], array $page_bottom = []): void {
    // Intentionally does nothing, so we don't get toolbar, etc.
  }

}
