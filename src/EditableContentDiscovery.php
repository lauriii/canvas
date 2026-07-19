<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Answers which content entity type+bundle pairs are Canvas-editable.
 *
 * A pair is editable when it is `canvas_page` (Canvas' own entity type), or
 * when an enabled `full` view mode content template exists for it. Templated
 * bundles are editable regardless of whether the template exposes slots: a
 * bundle without exposed slots opens with a fully locked canvas and editable
 * entity fields only.
 *
 * This is the single authority feeding route access for the content HTTP API,
 * the in-Canvas content browser, and the create-content flow.
 *
 * @see \Drupal\canvas\Storage\ComponentTreeLoader::getTemplatedBundles()
 * @internal
 */
final class EditableContentDiscovery {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentTreeLoader $componentTreeLoader,
  ) {}

  /**
   * Whether a content entity type is editable in Canvas for any bundle.
   */
  public function isEditableEntityType(string $entity_type_id): bool {
    return $this->getEditableBundles($entity_type_id) !== [];
  }

  /**
   * Whether an entity type+bundle pair is editable in Canvas.
   */
  public function isEditable(string $entity_type_id, string $bundle): bool {
    return \in_array($bundle, $this->getEditableBundles($entity_type_id), TRUE);
  }

  /**
   * Lists the Canvas-editable bundles of an entity type.
   *
   * @return string[]
   *   The bundle IDs, empty if the entity type has none.
   */
  public function getEditableBundles(string $entity_type_id): array {
    if ($entity_type_id === Page::ENTITY_TYPE_ID) {
      return [Page::ENTITY_TYPE_ID];
    }
    return $this->componentTreeLoader->getTemplatedBundles($entity_type_id);
  }

  /**
   * Lists every Canvas-editable entity type+bundle pair.
   *
   * @return array<string, string[]>
   *   Bundle IDs keyed by entity type ID. Always contains `canvas_page`.
   */
  public function getEditableTypeBundlePairs(): array {
    $pairs = [Page::ENTITY_TYPE_ID => [Page::ENTITY_TYPE_ID]];
    $storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    $template_ids = $storage->getQuery()
      ->condition('content_entity_type_view_mode', 'full')
      ->condition('status', TRUE)
      ->execute();
    foreach (\array_keys($template_ids) as $template_id) {
      // Template IDs are `content_entity_type_id.bundle.view_mode`.
      // @see \Drupal\canvas\Entity\ContentTemplate::id()
      [$entity_type_id] = \explode('.', (string) $template_id, 2);
      // Delegate per-type bundle resolution to the memoized loader so every
      // caller observes the same set.
      $pairs[$entity_type_id] ??= $this->componentTreeLoader->getTemplatedBundles($entity_type_id);
    }
    return \array_filter($pairs);
  }

  /**
   * The cacheability of the editable set.
   *
   * The set changes when content templates are created, deleted, enabled,
   * disabled, or when bundles change.
   */
  public function getCacheability(): CacheableMetadata {
    return (new CacheableMetadata())->addCacheTags([
      ContentTemplate::ENTITY_TYPE_ID . '_list',
      'entity_bundles',
    ]);
  }

}
