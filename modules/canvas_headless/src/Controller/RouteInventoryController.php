<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Enumerates the site-relative paths Canvas renders.
 *
 * The inventory lists published Canvas pages plus published content entities
 * whose bundle is rendered by an enabled full-view content template, one
 * entry per published translation, with the site front page emitted as an
 * additional "/" entry when Canvas renders it. It exists for clients that
 * must walk the site without crawling it: static site builds, sitemap
 * generators, cache warmers, and search indexers.
 *
 * Access mirrors the content endpoint: results reflect the requesting
 * account's entity access, so an anonymous request sees exactly what an
 * anonymous visitor could reach. Pagination is a keyset cursor over the
 * ordered source list and each source's entity id, which stays stable while
 * content is published mid-walk; `limit` bounds the source entities scanned
 * per page, not the emitted path count, so the cursor always resumes on an
 * entity boundary. Offsets are deliberately not offered.
 */
final class RouteInventoryController {

  public const int DEFAULT_LIMIT = 50;

  public const int MAX_LIMIT = 100;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    // path_alias is not a hard dependency of Canvas, so the alias manager is
    // NULL when it is absent; the front-page path is then used unresolved,
    // which is correct because a site without path_alias has no aliases.
    private readonly ?AliasManagerInterface $aliasManager = NULL,
  ) {}

  /**
   * Lists Canvas-rendered paths with change timestamps.
   */
  public function get(Request $request): CacheableJsonResponse {
    $limit = min(self::MAX_LIMIT, max(1, (int) $request->query->get('limit', self::DEFAULT_LIMIT)));
    $cursor = self::decodeCursor($request->query->get('cursor'));

    $cacheability = (new CacheableMetadata())
      ->addCacheContexts([
        'url.query_args:limit',
        'url.query_args:cursor',
        'user.permissions',
        // Delivery tokens can narrow entity access below the account's
        // permissions, exactly as on the content endpoint.
        'oauth2_scopes',
      ])
      // The source list changes when templates are created, deleted, or
      // toggled; each source's membership is covered by its list tag below.
      ->addCacheTags(['config:content_template_list']);

    // The ordered source list; the cursor's index refers into it, so the
    // resume order is exactly the iteration order (canvas_page first, then
    // sorted templates) rather than a lexicographic assumption about keys.
    $sources = \array_values($this->discoverSources($cacheability));

    // The front page duplicates one entity's path at "/". Resolve the
    // configured target once, through alias resolution because the setting
    // may hold an alias, so matching entities can emit both entries.
    $site_config = $this->configFactory->get('system.site');
    $cacheability->addCacheableDependency($site_config);
    $front_path = $site_config->get('page.front');
    if (!\is_string($front_path) || $front_path === '') {
      $front_path = NULL;
    }
    elseif ($this->aliasManager !== NULL) {
      $front_path = $this->aliasManager->getPathByAlias($front_path);
    }

    // `limit` bounds the number of source entities scanned per page. One
    // entity can emit several path entries (a translation each, plus the
    // extra "/" for the front page), so a page's `paths` count can exceed
    // `limit`; the cursor always resumes on an entity boundary.
    $start_index = $cursor['i'] ?? 0;
    $start_after_id = $cursor['id'] ?? NULL;

    $paths = [];
    $scanned = 0;
    $next_cursor = NULL;
    for ($i = $start_index; $i < \count($sources); $i++) {
      [$entity_type_id, $bundle] = $sources[$i];
      $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $id_key = $entity_type->getKey('id');
      $published_key = $entity_type->getKey('published');
      \assert(\is_string($id_key) && \is_string($published_key));
      $cacheability->addCacheTags([$entity_type_id . '_list']);
      if ($entity_type_id === 'node') {
        // Node access grants vary per user beyond permissions.
        $cacheability->addCacheContexts(['user.node_grants:view']);
      }

      $page_query = $storage->getQuery()->accessCheck(TRUE)
        ->condition($published_key, 1)
        ->sort($id_key);
      if ($bundle !== NULL && \is_string($bundle_key = $entity_type->getKey('bundle'))) {
        $page_query->condition($bundle_key, $bundle);
      }
      // Only the cursor's own source resumes after an id; later sources start
      // from the beginning.
      if ($i === $start_index && $start_after_id !== NULL) {
        $page_query->condition($id_key, $start_after_id, '>');
      }

      // Fetch one id beyond the remaining budget to learn whether this source
      // continues past this page.
      $remaining = $limit - $scanned;
      $ids = $page_query->range(0, $remaining + 1)->execute();
      \assert(\is_array($ids));
      $ids = \array_values($ids);
      $source_continues = \count($ids) > $remaining;
      if ($source_continues) {
        \array_pop($ids);
      }

      $last_id = NULL;
      foreach ($storage->loadMultiple($ids) as $entity) {
        \assert($entity instanceof ContentEntityInterface);
        // Count every loaded entity toward the budget and advance the cursor
        // past it, so a page of only non-canonical entities still makes
        // progress instead of looping.
        $last_id = $entity->id();
        $scanned++;
        if (!$entity->hasLinkTemplate('canonical')) {
          continue;
        }
        foreach (self::buildEntries($entity, $front_path, $cacheability) as $entry) {
          $paths[] = $entry;
        }
      }

      if ($source_continues) {
        \assert($last_id !== NULL);
        $next_cursor = ['i' => $i, 'id' => (int) $last_id];
        break;
      }
      // This source is exhausted. Stop only once the budget is full, resuming
      // at the next source; otherwise keep filling the page from it.
      if ($scanned >= $limit && $i + 1 < \count($sources)) {
        $next_cursor = ['i' => $i + 1, 'id' => NULL];
        break;
      }
    }

    $response = new CacheableJsonResponse([
      'paths' => $paths,
      'limit' => $limit,
      'cursor' => [
        'next' => $next_cursor === NULL ? NULL : self::encodeCursor($next_cursor),
      ],
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

  /**
   * Discovers the entity sources Canvas renders, in canonical walk order.
   *
   * Canvas pages are always Canvas-rendered. Other bundles are included when
   * an enabled content template targets their full view mode, and only when
   * the entity type has both a published entity key (the inventory promises
   * published-only, so unpublishable types are excluded rather than listed
   * unfiltered) and an id key to order the keyset walk by.
   *
   * @return array<string, array{string, ?string}>
   *   Source entries keyed by their canonical source key, each holding the
   *   entity type ID and an optional bundle.
   */
  private function discoverSources(CacheableMetadata $cacheability): array {
    $sources = [Page::ENTITY_TYPE_ID => [Page::ENTITY_TYPE_ID, NULL]];
    $template_storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    $templated = [];
    foreach ($template_storage->loadMultiple() as $template) {
      \assert($template instanceof ContentTemplate);
      if (!$template->status() || $template->getMode() !== 'full') {
        continue;
      }
      $cacheability->addCacheableDependency($template);
      $entity_type = $this->entityTypeManager->getDefinition($template->getTargetEntityTypeId());
      if (!\is_string($entity_type->getKey('published')) || !\is_string($entity_type->getKey('id'))) {
        continue;
      }
      $source_key = $template->getTargetEntityTypeId() . ':' . $template->getTargetBundle();
      $templated[$source_key] = [$template->getTargetEntityTypeId(), $template->getTargetBundle()];
    }
    // Canvas pages walk first; templated sources follow in key order so the
    // cursor's source comparison is total and stable across requests.
    \ksort($templated);
    return $sources + $templated;
  }

  /**
   * Builds one inventory entry per published, viewable translation.
   *
   * @return list<array<string, mixed>>
   *   The entries.
   */
  private static function buildEntries(
    ContentEntityInterface $entity,
    ?string $front_path,
    CacheableMetadata $cacheability,
  ): array {
    $entries = [];
    $is_front = $front_path !== NULL
      && $front_path === '/' . $entity->toUrl()->getInternalPath();
    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      $translation = $entity->getTranslation($langcode);
      if ($translation instanceof EntityPublishedInterface && !$translation->isPublished()) {
        continue;
      }
      $access = $translation->access('view', return_as_object: TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $url = $translation->toUrl('canonical', ['language' => $language])->toString(TRUE);
      $cacheability->addCacheableDependency($url);
      $cacheability->addCacheableDependency($translation);
      $entry = [
        'path' => $url->getGeneratedUrl(),
        'entityType' => $entity->getEntityTypeId(),
        'id' => (string) $entity->id(),
        'uuid' => $entity->uuid(),
        'langcode' => $langcode,
        'changed' => $translation instanceof EntityChangedInterface
          ? \date(\DATE_ATOM, (int) $translation->getChangedTime())
          : NULL,
      ];
      $entries[] = $entry;
      if ($is_front && $langcode === $entity->getUntranslated()->language()->getId()) {
        $entries[] = ['path' => '/'] + $entry;
      }
    }
    return $entries;
  }

  /**
   * Decodes and validates an opaque keyset cursor.
   *
   * @return array{i: int, id: int|null}|null
   *   The cursor (source index and the last id scanned in that source, or
   *   NULL id to resume at the start of the source), or NULL when none was
   *   supplied.
   */
  private static function decodeCursor(mixed $value): ?array {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (\is_string($value)) {
      $decoded = \base64_decode($value, TRUE);
      if ($decoded !== FALSE) {
        $cursor = \json_decode($decoded, TRUE);
        if (
          \is_array($cursor)
          && \array_key_exists('i', $cursor)
          && \array_key_exists('id', $cursor)
          && \is_int($cursor['i'])
          && $cursor['i'] >= 0
          && ($cursor['id'] === NULL || \is_int($cursor['id']))
        ) {
          return ['i' => $cursor['i'], 'id' => $cursor['id']];
        }
      }
    }
    throw new BadRequestHttpException('The cursor query parameter is not a cursor this endpoint issued.');
  }

  /**
   * Encodes a keyset cursor opaquely.
   *
   * @param array{i: int, id: int|null} $cursor
   *   The cursor.
   */
  private static function encodeCursor(array $cursor): string {
    return \base64_encode((string) \json_encode($cursor));
  }

}
