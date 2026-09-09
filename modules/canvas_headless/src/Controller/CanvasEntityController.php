<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas_headless\CanvasContentEntityRenderer;
use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\canvas_headless\RenderConverter\JsComponentCanvasRenderConverter;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\custom_elements\CustomElementNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds a Canvas API response for one supported entity render target.
 */
final class CanvasEntityController {

  public const API_PATH = '/canvas/content-api/entity';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly CanvasContentEntityRenderer $entityRenderer,
    #[Autowire(service: 'custom_elements.canvas_render_converter')]
    private readonly JsComponentCanvasRenderConverter $canvasRenderConverter,
    #[Autowire(service: 'custom_elements.normalizer')]
    private readonly CustomElementNormalizer $customElementNormalizer,
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Builds content and metadata for one supported entity render target.
   */
  public function get(Request $request): CacheableJsonResponse {
    // Reuse the content API's request-scoped absolute file URL behavior for
    // entity renders so image props resolve from the headless frontend origin.
    $request->attributes->set(
      CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE,
      self::API_PATH,
    );
    $is_preview = PreviewTokenInspector::hasPreviewScope($this->currentUser->getAccount());
    if ($is_preview) {
      // ContentTemplateAwareViewBuilder only loads an auto-saved content
      // template when the current route sets this option.
      $route = $this->routeMatch->getRouteObject();
      \assert($route !== NULL);
      $route->setOption('_canvas_use_template_draft', TRUE);
    }
    $entity_type_id = (string) $request->query->get('type', '');
    if ($entity_type_id === '') {
      throw new BadRequestHttpException('The type query parameter is required.');
    }

    try {
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
    }
    catch (PluginNotFoundException | InvalidPluginDefinitionException) {
      throw new BadRequestHttpException('Unknown entity type.');
    }

    $entity_id = $request->query->all()['id'] ?? NULL;
    if (!\is_scalar($entity_id) || (string) $entity_id === '') {
      throw new BadRequestHttpException('The id query parameter is required.');
    }

    $entity = $storage->load((string) $entity_id);
    if (!$entity instanceof ContentEntityInterface) {
      if ($entity === NULL) {
        throw new NotFoundHttpException('The entity does not exist.');
      }
      throw new BadRequestHttpException('The entity type must be a content entity type.');
    }
    // Storage loads the default translation; use the request's instead.
    $entity = $this->entityRepository->getTranslationFromContext($entity);

    $view_mode = (string) $request->query->get('viewMode', 'full');
    if ($view_mode === '' || preg_match('/^[a-z0-9_]+$/', $view_mode) !== 1) {
      throw new BadRequestHttpException('The viewMode query parameter is invalid.');
    }

    $access = $entity->access('view', $this->currentUser->getAccount(), TRUE);
    if (!$access->isAllowed()) {
      $cacheability = (new CacheableMetadata())
        ->addCacheableDependency($entity)
        ->addCacheableDependency($access)
        ->addCacheContexts(['oauth2_scopes']);
      throw new CacheableAccessDeniedHttpException($cacheability, 'The entity may not be viewed.');
    }

    [$build, $rendered_entity, $cacheability] = $this->renderEntity(
      $entity,
      $is_preview,
      $view_mode,
    );
    $cacheability = (new BubbleableMetadata())
      ->addCacheableDependency($cacheability)
      ->addCacheableDependency($access);

    $content = NULL;
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

    $response = new CacheableJsonResponse([
      'content' => $content,
      'managedByCanvas' => $managed_by_canvas,
      'entity' => [
        'entityType' => $rendered_entity->getEntityTypeId(),
        'bundle' => $rendered_entity->bundle(),
        'id' => (string) $rendered_entity->id(),
        'uuid' => $rendered_entity->uuid(),
        'langcode' => $rendered_entity->language()->getId(),
      ],
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Renders a content entity, selecting its auto-save for preview tokens.
   *
   * @return array{?array, ContentEntityInterface, BubbleableMetadata}
   *   The Canvas render array when supported, the selected entity, and the
   *   dependencies that determined whether Canvas can render it.
   */
  private function renderEntity(
    ContentEntityInterface $stored_entity,
    bool $is_preview,
    string $view_mode,
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

    $render_result = $this->entityRenderer->buildEntity(
      $entity,
      $view_mode,
      $is_preview,
    );
    $build = $render_result['build'];
    $cacheability = (new BubbleableMetadata())
      ->addCacheableDependency($render_result['cacheability'])
      ->addCacheableDependency($entity)
      ->addCacheTags([$entity->getEntityTypeId() . '_view'])
      ->addCacheContexts(['oauth2_scopes']);
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

}
