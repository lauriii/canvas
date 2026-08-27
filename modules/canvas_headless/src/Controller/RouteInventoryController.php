<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * SPIKE PROTOTYPE: enumerates the paths Canvas renders for this site.
 *
 * Lists the canonical paths of published Canvas pages plus published content
 * entities whose bundle is rendered by an enabled full-view content template.
 * Anonymous, published-only, offset-paginated. Built to prove feasibility for
 * the headless-route-inventory OpenSpec proposal; not production code. Known
 * simplifications, recorded in the proposal's tasks:
 * - offset pagination over an id-merged list (a real implementation wants a
 *   keyset cursor so pages are stable under concurrent publishes);
 * - default translation only (no per-language path variants);
 * - no representation of non-entity routes (front page alias, views pages);
 * - access variation is only covered at 'user.permissions' granularity, which
 *   is too coarse for per-user access schemes such as node grants.
 */
final class RouteInventoryController {

  private const int DEFAULT_LIMIT = 50;
  private const int MAX_LIMIT = 100;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Lists Canvas-rendered paths with change timestamps.
   */
  public function get(Request $request): CacheableJsonResponse {
    $offset = max(0, (int) $request->query->get('offset', 0));
    $limit = min(self::MAX_LIMIT, max(1, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));

    $cacheability = (new CacheableMetadata())
      ->addCacheContexts([
        'url.query_args:offset',
        'url.query_args:limit',
        'user.permissions',
      ])
      // The set of sources changes when templates are created or (un)made
      // full-view; each source's membership is covered by its list tag below.
      ->addCacheTags(['config:content_template_list']);

    // Each source is an entity type plus an optional bundle condition. Canvas
    // pages are always Canvas-rendered; other bundles are Canvas-rendered
    // when an enabled content template targets their full view mode.
    $sources = [[Page::ENTITY_TYPE_ID, NULL]];
    $template_storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    foreach ($template_storage->loadMultiple() as $template) {
      \assert($template instanceof ContentTemplate);
      if ($template->status() && $template->getMode() === 'full') {
        $sources[] = [$template->getTargetEntityTypeId(), $template->getTargetBundle()];
        $cacheability->addCacheableDependency($template);
      }
    }

    // Collect ids per source in a stable order, then slice one page out of
    // the merged list and load only that page's entities.
    $ids_by_source = [];
    $total = 0;
    foreach ($sources as [$entity_type_id, $bundle]) {
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $query = $storage->getQuery()->accessCheck(TRUE);
      $published_key = $entity_type->getKey('published');
      if (\is_string($published_key)) {
        $query->condition($published_key, 1);
      }
      if ($bundle !== NULL && \is_string($bundle_key = $entity_type->getKey('bundle'))) {
        $query->condition($bundle_key, $bundle);
      }
      $id_key = $entity_type->getKey('id');
      \assert(\is_string($id_key));
      $ids = $query->sort($id_key)->execute();
      \assert(\is_array($ids));
      $ids_by_source[$entity_type_id] = \array_merge(
        $ids_by_source[$entity_type_id] ?? [],
        \array_values($ids),
      );
      $total += \count($ids);
      $cacheability->addCacheTags([$entity_type_id . '_list']);
    }

    $flat = [];
    foreach ($ids_by_source as $entity_type_id => $ids) {
      foreach ($ids as $id) {
        $flat[] = [$entity_type_id, $id];
      }
    }
    $page_refs = \array_slice($flat, $offset, $limit);

    $paths = [];
    foreach ($page_refs as [$entity_type_id, $id]) {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
      if (!$entity instanceof ContentEntityInterface || !$entity->hasLinkTemplate('canonical')) {
        continue;
      }
      $url = $entity->toUrl()->toString(TRUE);
      $cacheability->addCacheableDependency($url);
      $cacheability->addCacheableDependency($entity);
      $paths[] = [
        'path' => $url->getGeneratedUrl(),
        'entityType' => $entity_type_id,
        'id' => (string) $entity->id(),
        'uuid' => $entity->uuid(),
        'langcode' => $entity->language()->getId(),
        'changed' => $entity instanceof EntityChangedInterface
          ? \date(\DATE_ATOM, (int) $entity->getChangedTime())
          : NULL,
      ];
    }

    $response = new CacheableJsonResponse([
      'paths' => $paths,
      'total' => $total,
      'offset' => $offset,
      'limit' => $limit,
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
