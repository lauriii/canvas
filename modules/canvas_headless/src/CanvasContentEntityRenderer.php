<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Builds content only when Canvas owns the entity's full rendered output.
 */
final class CanvasContentEntityRenderer {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  /**
   * Resolves and builds the Canvas rendering strategy for an entity.
   *
   * @return array{build: ?array, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The Canvas render array, or NULL when Canvas does not render the entity,
   *   plus dependencies that determined the result.
   */
  public function build(
    ContentEntityInterface $entity,
    string $view_mode,
    bool $is_preview,
  ): array {
    if ($entity instanceof ComponentTreeEntityInterface) {
      return [
        'build' => $entity
          ->getComponentTree()
          ->toRenderable($entity, $is_preview),
        'cacheability' => new CacheableMetadata(),
      ];
    }

    $entity_type = $this->entityTypeManager
      ->getDefinition($entity->getEntityTypeId());
    if (!$entity_type->hasHandlerClass('view_builder')) {
      return self::unsupported();
    }
    $view_builder = $this->entityTypeManager
      ->getViewBuilder($entity->getEntityTypeId());
    if (!$view_builder instanceof ContentTemplateAwareViewBuilder) {
      return self::unsupported();
    }

    $template = ContentTemplate::loadForEntity($entity, $view_mode);
    $cacheability = (new CacheableMetadata())
      ->addCacheTags(
        $this->entityTypeManager
          ->getDefinition(ContentTemplate::ENTITY_TYPE_ID)
          ->getListCacheTags(),
      );
    if ($is_preview) {
      $cacheability->addCacheTags([AutoSaveManager::CACHE_TAG]);
      if ($template !== NULL) {
        $auto_save = $this->autoSaveManager->getAutoSaveEntity($template);
        if (!$auto_save->isEmpty()) {
          \assert($auto_save->entity instanceof ContentTemplate);
          $template = $auto_save->entity;
          $template->setStatus(TRUE);
        }
      }
    }
    if ($template !== NULL) {
      $cacheability->addCacheableDependency($template);
    }
    if ($template === NULL || (!$is_preview && !$template->status())) {
      return self::unsupported($cacheability);
    }

    return [
      'build' => $view_builder->build(
        $view_builder->view($entity, $view_mode),
      ),
      'cacheability' => $cacheability,
    ];
  }

  /**
   * Returns an unsupported decision with its cacheability.
   *
   * @return array{build: null, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   The unsupported decision.
   */
  private static function unsupported(
    ?CacheableMetadata $cacheability = NULL,
  ): array {
    return [
      'build' => NULL,
      'cacheability' => $cacheability ?? new CacheableMetadata(),
    ];
  }

}
