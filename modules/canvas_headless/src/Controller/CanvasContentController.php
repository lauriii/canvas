<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas_headless\CanvasContentEntityRenderer;
use Drupal\canvas_headless\CanvasContentHeadBuilder;
use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\canvas_headless\RenderConverter\JsComponentCanvasRenderConverter;
use Drupal\canvas_headless\Routing\CanvasContentRouteEnhancer;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\custom_elements\CustomElementNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Builds a Canvas API response for a kernel-routed Drupal request.
 */
final class CanvasContentController {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly CanvasContentEntityRenderer $entityRenderer,
    private readonly CanvasContentHeadBuilder $headBuilder,
    #[Autowire(service: 'custom_elements.canvas_render_converter')]
    private readonly JsComponentCanvasRenderConverter $canvasRenderConverter,
    #[Autowire(service: 'custom_elements.normalizer')]
    private readonly CustomElementNormalizer $customElementNormalizer,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Builds content and metadata for the routed request.
   */
  public function get(
    Request $request,
    RouteMatchInterface $route_match,
  ): CacheableJsonResponse {
    $request_uri = $request->attributes->get(
      CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE,
    );
    if (!\is_string($request_uri)) {
      throw new BadRequestHttpException('The Canvas content API request is missing routed request context.');
    }

    $primary_entity_param = $request->attributes->get(
      CanvasContentRouteEnhancer::PRIMARY_ENTITY_PARAMETER,
    );
    $entity = \is_string($primary_entity_param)
      ? $route_match->getParameter($primary_entity_param)
      : NULL;
    $content = NULL;
    $rendered_entity = NULL;
    $managed_by_canvas = FALSE;

    if ($entity instanceof ContentEntityInterface) {
      $is_preview = PreviewTokenInspector::hasPreviewScope($this->currentUser->getAccount());
      $view_mode = NULL;
      if ($is_preview) {
        $route = $route_match->getRouteObject();
        \assert($route !== NULL);
        $route->setOption('_canvas_use_template_draft', TRUE);
        $api_query_parameters = $request->attributes->get(
          CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY,
          [],
        );
        \assert(\is_array($api_query_parameters));
        $view_mode = $api_query_parameters[CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY] ?? NULL;
        \assert($view_mode === NULL || \is_string($view_mode));
      }

      [$build, $rendered_entity, $render_cacheability] = $this->renderEntity(
        $entity,
        $is_preview,
        $view_mode ?? 'full',
      );
      $managed_by_canvas = $build !== NULL;
      $head_result = $this->headBuilder->build($rendered_entity);
      $cacheability = (new BubbleableMetadata())
        ->addCacheableDependency($render_cacheability)
        ->addCacheableDependency($head_result['cacheability']);
      if ($build !== NULL) {
        $custom_element = $this->canvasRenderConverter->convertRenderArray($build);
        $cacheability->addCacheableDependency($custom_element);
        $content = $this->customElementNormalizer->normalize(
          $custom_element,
          context: [
            'explicit' => TRUE,
            'cache_metadata' => $cacheability,
          ],
        );
      }
    }
    else {
      $head_result = $this->headBuilder->buildFromRoute($request, $route_match);
      $cacheability = $head_result['cacheability'];
    }

    // @todo Remove additional Custom Elements JSON normalization when json-render support is added.
    if (\is_array($content) && $content === ['element' => 'drupal-markup']) {
      $content = NULL;
    }
    elseif (\is_array($content) && array_is_list($content)) {
      $content = match (\count($content)) {
        0 => NULL,
        1 => $content[0],
        default => [
          'element' => 'renderless-container',
          'slots' => ['default' => $content],
        ],
      };
    }

    $response = new CacheableJsonResponse([
      'content' => $content,
      'head' => $head_result['head'],
      'route' => self::normalizeRoute(
        $route_match,
        $request_uri,
        $rendered_entity,
        $managed_by_canvas,
      ),
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Renders a content entity, selecting its auto-save for preview tokens.
   *
   * @return array{?array, ContentEntityInterface, CacheableMetadata}
   *   The Canvas render array when supported, the selected entity, and the
   *   dependencies that determined whether Canvas can render it.
   */
  private function renderEntity(
    ContentEntityInterface $stored_entity,
    bool $is_preview,
    string $view_mode = 'full',
  ): array {
    $entity = $stored_entity;
    $auto_save = NULL;
    $access = NULL;
    if ($is_preview) {
      $auto_save = $this->autoSaveManager->getAutoSaveEntityForPreview($stored_entity);
      if (!$auto_save->isEmpty()) {
        \assert($auto_save->entity instanceof ContentEntityInterface);
        $entity = $auto_save->entity;
        // Route access cached the result for the stored entity. The auto-save
        // has the same UUID and revision ID, but its access-relevant fields may
        // differ, so force a separate access check for the reconstructed copy.
        $this->entityTypeManager
          ->getAccessControlHandler($entity->getEntityTypeId())
          ->resetCache();
        $access = $entity->access('view', $this->currentUser->getAccount(), TRUE);
        if (!$access->isAllowed()) {
          $cacheability = (new CacheableMetadata())
            ->addCacheableDependency($auto_save)
            ->addCacheableDependency($access)
            ->addCacheContexts(['oauth2_scopes']);
          throw new CacheableAccessDeniedHttpException($cacheability, 'The auto-saved entity is not viewable.');
        }
      }
    }

    $render_result = $this->entityRenderer->build(
      $entity,
      $view_mode,
      $is_preview,
    );
    $build = $render_result['build'];
    $cacheability = $render_result['cacheability']
      ->addCacheableDependency($entity)
      ->addCacheTags([$entity->getEntityTypeId() . '_view'])
      ->addCacheContexts(['oauth2_scopes']);
    if ($is_preview) {
      $cacheability->addCacheContexts([
        'url.query_args:' . CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY,
      ]);
    }
    if ($build !== NULL) {
      $cacheability->addCacheableDependency(
        CacheableMetadata::createFromRenderArray($build),
      );
    }
    if ($auto_save !== NULL) {
      $cacheability->addCacheableDependency($auto_save);
    }
    if ($access !== NULL) {
      $cacheability->addCacheableDependency($access);
    }
    if ($build !== NULL) {
      $cacheability->applyTo($build);
    }
    return [$build, $entity, $cacheability];
  }

  /**
   * Returns identity-only route context for the frontend.
   */
  private static function normalizeRoute(
    RouteMatchInterface $route_match,
    string $request_uri,
    ?ContentEntityInterface $rendered_entity = NULL,
    bool $managed_by_canvas = FALSE,
  ): array {
    $route = $route_match->getRouteObject();
    \assert($route !== NULL);
    $params = [];
    foreach ($route->compile()->getVariables() as $name) {
      $raw = $route_match->getRawParameter($name);
      if (\is_scalar($raw)) {
        $params[$name] = (string) $raw;
      }
    }

    $entity = NULL;
    if ($rendered_entity !== NULL) {
      $entity = [
        'entityType' => $rendered_entity->getEntityTypeId(),
        'bundle' => $rendered_entity->bundle(),
        'id' => (string) $rendered_entity->id(),
        'uuid' => $rendered_entity->uuid(),
        'langcode' => $rendered_entity->language()->getId(),
      ];
    }

    return [
      'name' => (string) $route_match->getRouteName(),
      'requestUri' => $request_uri,
      'params' => $params,
      'managedByCanvas' => $managed_by_canvas,
      'entity' => $entity,
    ];
  }

}
