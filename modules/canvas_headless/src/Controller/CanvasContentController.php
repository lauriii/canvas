<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas_headless\CanvasContentContextBuilder;
use Drupal\canvas_headless\CanvasContentEntityRenderer;
use Drupal\canvas_headless\CanvasContentHeadBuilder;
use Drupal\canvas_headless\CanvasContentTranslationLinks;
use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\canvas_headless\RenderConverter\JsComponentCanvasRenderConverter;
use Drupal\canvas_headless\Routing\CanvasContentRoute;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\custom_elements\CustomElementNormalizer;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds a Canvas API response for a kernel-routed Drupal request.
 */
final class CanvasContentController {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly CanvasContentEntityRenderer $entityRenderer,
    private readonly CanvasContentHeadBuilder $headBuilder,
    private readonly CanvasContentContextBuilder $contextBuilder,
    private readonly CanvasContentTranslationLinks $translationLinks,
    #[Autowire(service: 'custom_elements.canvas_render_converter')]
    private readonly JsComponentCanvasRenderConverter $canvasRenderConverter,
    #[Autowire(service: 'custom_elements.normalizer')]
    private readonly CustomElementNormalizer $customElementNormalizer,
    private readonly AccountProxyInterface $currentUser,
    private readonly AccountSwitcherInterface $accountSwitcher,
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

    $content_route = CanvasContentRoute::resolve($route_match->getRouteName(), $route_match->getRouteObject(), $route_match->getParameters()->all());
    $entity = $content_route?->entity;
    $content = NULL;
    $rendered_entity = NULL;
    $managed_by_canvas = FALSE;
    $build = NULL;
    $is_preview = PreviewTokenInspector::hasPreviewScope($this->currentUser->getAccount());
    // The route has selected the exact unsaved entity or revision. A Canvas
    // auto-save must not replace it, nor select draft content templates.
    $is_entity_preview = $content_route !== NULL && $content_route->isPreview;
    $view_mode = NULL;
    $api_query_parameters = [];
    if ($is_preview) {
      $api_query_parameters = $request->attributes->get(
        CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY,
        [],
      );
      \assert(\is_array($api_query_parameters));
      $view_mode = $api_query_parameters[CanvasContentApiRequest::PREVIEW_VIEW_MODE_QUERY] ?? NULL;
      \assert($view_mode === NULL || \is_string($view_mode));
    }

    $use_auto_save = $is_preview && !$is_entity_preview && ($api_query_parameters[CanvasContentApiRequest::EXCLUDE_AUTO_SAVE_QUERY] ?? 'false') !== 'true';
    if ($is_preview) {
      $route = $route_match->getRouteObject();
      \assert($route !== NULL);
      $route->setOption('_canvas_use_template_draft', $use_auto_save);
    }

    $preview_language = $api_query_parameters[CanvasContentApiRequest::PREVIEW_LANGUAGE_QUERY] ?? NULL;
    \assert($preview_language === NULL || \is_string($preview_language));
    $component_preview_id = $api_query_parameters[CanvasContentApiRequest::COMPONENT_PREVIEW_QUERY] ?? NULL;
    \assert($component_preview_id === NULL || \is_string($component_preview_id));
    $page_variant_preview_id = $api_query_parameters[CanvasContentApiRequest::PAGE_VARIANT_PREVIEW_QUERY] ?? NULL;
    \assert($page_variant_preview_id === NULL || \is_string($page_variant_preview_id));
    if ($is_preview && $component_preview_id !== NULL) {
      [$build, $render_cacheability] = $this->renderComponentPreview($component_preview_id);
      if ($entity instanceof ContentEntityInterface) {
        $rendered_entity = $entity;
        $head_result = $this->headBuilder->build($rendered_entity);
      }
      else {
        $head_result = $this->headBuilder->buildFromRoute($request, $route_match);
      }
      $cacheability = (new BubbleableMetadata())
        ->addCacheableDependency($render_cacheability)
        ->addCacheableDependency($head_result['cacheability']);
    }
    elseif ($is_preview && $page_variant_preview_id !== NULL) {
      [$build, $render_cacheability] = $this->renderPageVariantPreview($page_variant_preview_id, $preview_language, $use_auto_save);
      $head_result = $this->headBuilder->buildFromRoute($request, $route_match);
      $cacheability = (new BubbleableMetadata())
        ->addCacheableDependency($render_cacheability)
        ->addCacheableDependency($head_result['cacheability']);
    }
    elseif ($entity instanceof ContentEntityInterface) {
      [$build, $rendered_entity, $render_cacheability] = $this->renderEntity(
        $entity,
        $use_auto_save,
        $is_entity_preview ? $content_route->viewMode : ($view_mode ?? $content_route->viewMode),
        $preview_language,
        $is_entity_preview,
      );
      $head_result = $this->headBuilder->build($rendered_entity);
      $cacheability = (new BubbleableMetadata())
        ->addCacheableDependency($render_cacheability)
        ->addCacheableDependency($head_result['cacheability']);
    }
    else {
      $head_result = $this->headBuilder->buildFromRoute($request, $route_match);
      $cacheability = $head_result['cacheability'];
    }
    if ($is_entity_preview) {
      // Prevent render caching as well as response caching: unsaved form values
      // can share the stored entity's UUID and revision ID.
      $cacheability->setCacheMaxAge(0);
      if ($build !== NULL) {
        $cacheability->applyTo($build);
      }
    }
    $managed_by_canvas = $build !== NULL;
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

    // The page and site context React Code Components read through the
    // `drupal-canvas` context hooks, generated for the routed request.
    $context = $this->contextBuilder->build(
      $cacheability,
      $rendered_entity instanceof ContentEntityInterface ? $rendered_entity : NULL,
    );

    $language_context = $this->translationLinks->build($rendered_entity, $request, $cacheability);
    $response = new CacheableJsonResponse([
      'content' => $content,
      'head' => $head_result['head'],
      'route' => self::normalizeRoute(
        $route_match,
        $request_uri,
        $rendered_entity,
        $managed_by_canvas,
      ) + $language_context,
      'context' => $context,
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Builds the selected page variant, optionally including its auto-save.
   *
   * @return array{?array, \Drupal\Core\Cache\CacheableMetadata}
   *   The variant render array when headless-compatible and its cacheability.
   */
  private function renderPageVariantPreview(string $page_variant_id, ?string $preview_language = NULL, bool $use_auto_save = TRUE): array {
    $variant = $this->entityTypeManager
      ->getStorage(PageVariant::ENTITY_TYPE_ID)
      ->load($page_variant_id);
    if (!$variant instanceof PageVariant) {
      throw new NotFoundHttpException('The page variant does not exist.');
    }
    $preview_account = $this->currentUser->getAccount();
    \assert($preview_account instanceof TokenAuthUserInterface);
    // Preview tokens intentionally exclude administrative permissions. Check
    // this read against the user bound to the token, whose access was also
    // checked before Drupal minted the page variant preview assertion. The
    // account switch is required because the token and subject share a user
    // ID while varying by OAuth scope in the permission cache.
    $access_handler = $this->entityTypeManager
      ->getAccessControlHandler(PageVariant::ENTITY_TYPE_ID);
    $access_handler->resetCache();
    $this->accountSwitcher->switchTo($preview_account->getSubject());
    try {
      $access = $access_handler->access(
        $variant,
        'view',
        $preview_account->getSubject(),
        TRUE,
      );
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    if (!$access->isAllowed()) {
      $cacheability = (new CacheableMetadata())
        ->addCacheableDependency($variant)
        ->addCacheableDependency($access)
        ->addCacheContexts(['oauth2_scopes']);
      throw new CacheableAccessDeniedHttpException($cacheability, 'The page variant may not be viewed.');
    }

    $result = $this->entityRenderer->buildPageVariantPreview($variant, $preview_language, $use_auto_save);
    $build = $result['build'];
    $cacheability = $result['cacheability']
      ->addCacheableDependency($access)
      ->addCacheContexts([
        'oauth2_scopes',
        'url.query_args:' . CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY,
      ]);
    if ($build !== NULL) {
      $cacheability
        ->addCacheableDependency(CacheableMetadata::createFromRenderArray($build))
        ->applyTo($build);
    }
    return [$build, $cacheability];
  }

  /**
   * Builds the default-value preview for one app-owned component.
   *
   * @return array{array, \Drupal\Core\Render\BubbleableMetadata}
   *   The component render array and its cacheability.
   */
  private function renderComponentPreview(string $component_id): array {
    $component = $this->entityTypeManager
      ->getStorage(Component::ENTITY_TYPE_ID)
      ->load($component_id);
    if (!$component instanceof Component) {
      throw new NotFoundHttpException('The component does not exist.');
    }

    $source = $component->getComponentSource();
    if (!$source instanceof JsComponent || !$source->getJavaScriptComponent()->isExternal()) {
      throw new NotFoundHttpException('The component is not owned by the headless application.');
    }

    $info = $source->getClientSideInfo($component);
    $build = $info['build'];
    \assert(\is_array($build));
    $cacheability = (new BubbleableMetadata())
      ->addCacheableDependency($component)
      ->addCacheableDependency(BubbleableMetadata::createFromRenderArray($build))
      ->addCacheContexts([
        'oauth2_scopes',
        'url.query_args:' . CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY,
      ]);
    $cacheability->applyTo($build);
    return [$build, $cacheability];
  }

  /**
   * Renders a content entity, optionally selecting its Canvas auto-save.
   *
   * @return array{?array, ContentEntityInterface, CacheableMetadata}
   *   The Canvas render array when supported, the selected entity, and the
   *   dependencies that determined whether Canvas can render it.
   */
  private function renderEntity(
    ContentEntityInterface $stored_entity,
    bool $use_auto_save,
    string $view_mode = 'full',
    ?string $preview_language = NULL,
    bool $is_entity_preview = FALSE,
  ): array {
    $entity = $stored_entity;
    $auto_save = NULL;
    $access = NULL;
    if ($use_auto_save) {
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
      $use_auto_save,
      $preview_language,
      $is_entity_preview,
    );
    $build = $render_result['build'];
    $cacheability = $render_result['cacheability']
      ->addCacheableDependency($entity)
      ->addCacheTags([$entity->getEntityTypeId() . '_view'])
      ->addCacheContexts([
        'oauth2_scopes',
        'url.query_args:' . CanvasContentApiRequest::API_QUERY_PARAMETERS_KEY,
      ]);
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
