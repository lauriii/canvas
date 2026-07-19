<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasUriDefinitions;
use Drupal\canvas\ClientDataToEntityConverter;
use Drupal\canvas\EditableContentDiscovery;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Resource\CanvasResourceLink;
use Drupal\canvas\Resource\CanvasResourceLinkCollection;
use Drupal\canvas\Resource\OffsetPage;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\canvas\Utility\HomePageHelper;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * HTTP API for interacting with Canvas-eligible Content entity types.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 *
 * @todo https://www.drupal.org/i/3498525 should generalize this to all eligible content entity types
 */
final class ApiContentControllers extends ApiControllerBase {

  /**
   * The maximum number of entity search results to return.
   */
  private const int MAX_SEARCH_RESULTS = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly SelectionPluginManagerInterface $selectionManager,
    private readonly RouteProviderInterface $routeProvider,
    private readonly LanguageManagerInterface $languageManager,
    private readonly AccountProxyInterface $currentUser,
    #[Autowire(service: 'transliteration')]
    private readonly TransliterationInterface $transliteration,
    private readonly HomePageHelper $homePageHelper,
    private readonly ComponentTreeLoader $componentTreeLoader,
    private readonly EditableContentDiscovery $editableContentDiscovery,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly ClientDataToEntityConverter $converter,
  ) {}

  /**
   * Returns a single Canvas page with its component tree field.
   */
  public function get(Page $canvas_page): CacheableJsonResponse {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($canvas_page);

    $generated_url = $canvas_page->toUrl()->toString(TRUE);
    $cacheability->addCacheableDependency($generated_url);
    $data = $this->normalizeWithMetadataAndComponents($canvas_page, $cacheability);

    $json_response = new CacheableJsonResponse($data);
    $json_response->addCacheableDependency($cacheability);
    return $json_response;
  }

  public function patch(Request $request, ContentEntityInterface $canvas_page): JsonResponse {
    // Get the request body content
    $content = $request->getContent();
    $body = \json_decode($content, TRUE);

    // Try to load the entity instance.
    if (!$canvas_page->access('update')) {
      return new JsonResponse(['error' => 'Cannot find entity to update.'], Response::HTTP_NOT_FOUND);
    }
    \assert($canvas_page instanceof Page);

    // Note: this intentionally does not catch content entity type storage
    // handler exceptions: the generic Canvas API exception subscriber handles
    // them.
    // @see \Drupal\canvas\EventSubscriber\ApiExceptionSubscriber

    // Ensure the path field carries the existing PID so Drupal updates the
    // alias in place rather than creating a duplicate.
    if (isset($body['path'])) {
      $existing_pid = $canvas_page->get('path')->first()?->getValue()['pid'] ?? NULL;
      $body['path'] = ['alias' => $body['path'], 'pid' => $existing_pid];
    }

    foreach (['title', 'status', 'path', 'components', 'description'] as $field_name) {
      if (!\array_key_exists($field_name, $body)) {
        continue;
      }
      $field_access = $canvas_page->get($field_name)->access(operation: 'edit', return_as_object: TRUE);
      if ($field_access->isForbidden()) {
        throw new CacheableAccessDeniedHttpException(
          (new CacheableMetadata())->addCacheableDependency($field_access),
          \sprintf('Unable to update field %s for entity "%s".', $field_name, $canvas_page->id()),
        );
      }
      $canvas_page->set($field_name, $body[$field_name]);
    }
    $violations = $canvas_page->validate();
    if ($violations->count() > 0) {
      if ($validation_errors_response = self::createJsonResponseFromViolationSets($violations)) {
        return $validation_errors_response;
      }
    }
    $canvas_page->save();

    // The response is never cacheable, so it will be discarded.
    $data = $this->normalizeWithMetadataAndComponents($canvas_page, new CacheableMetadata());

    return new JsonResponse($data, Response::HTTP_OK);
  }

  public function post(Request $request, string $entity_type): JsonResponse {
    // Get the request body content
    $content = $request->getContent();
    $body = json_decode($content, TRUE);
    $entity = NULL;

    // Try to load the entity instance.
    if (isset($body['entity_id'])) {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($body['entity_id']);
      if (!$entity instanceof ContentEntityInterface || !$entity->access('view')) {
        return new JsonResponse(['error' => 'Cannot find entity to duplicate.'], Response::HTTP_NOT_FOUND);
      }
    }

    // If entity is provided, duplicate it, otherwise create a new entity.
    if ($entity) {
      self::checkCreateAccess($this->entityTypeManager, $entity_type, $entity->bundle());
      $new = $this->duplicate($entity);
    }
    elseif ($entity_type === Page::ENTITY_TYPE_ID) {
      // Note: this intentionally does not catch content entity type storage
      // handler exceptions: the generic Canvas API exception subscriber handles
      // them.
      // @see \Drupal\canvas\EventSubscriber\ApiExceptionSubscriber
      $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
      $requestBodyTitle = $body['title'] ?? static::defaultTitle($entity_type_definition);
      $requestStatus = $body['status'] ?? FALSE;
      $requestComponents = $body['components'] ?? [];
      // @todo This won't check if the alias is already used, potentially
      //   creating duplicated aliases.
      $requestPath = $body['path'] ?? NULL;
      $requestDescription = $body['description'] ?? NULL;
      $values = [
        'title' => $requestBodyTitle,
        'status' => $requestStatus,
        'components' => $requestComponents,
        'path' => $requestPath,
        'description' => $requestDescription,
      ];
      if (isset($body['uuid'])) {
        $values['uuid'] = $body['uuid'];
        $existing = $this->entityTypeManager->getStorage($entity_type)->loadByProperties(['uuid' => $body['uuid']]);
        if ($existing !== []) {
          throw new ConflictHttpException(\sprintf('An entity with UUID "%s" already exists.', $body['uuid']));
        }
      }
      $new = $this->entityTypeManager->getStorage($entity_type)->create($values);
      $violations = $new->getTypedData()->validate();
      if ($violations->count() > 0) {
        if ($validation_errors_response = self::createJsonResponseFromViolationSets($violations)) {
          return $validation_errors_response;
        }
      }
      $new->save();
    }
    else {
      // Create a real unpublished draft entity for a templated bundle: a
      // placeholder label plus field defaults, with constraint validation
      // intentionally deferred to publish, because the editing lifecycle
      // allows drafts to be invalid while they are being worked on.
      // @see docs/adr/0021-content-entity-field-editing-generalizes-the-semi-coupled-form-pipeline.md
      $bundle = $body['bundle'] ?? NULL;
      if (!\is_string($bundle) || !$this->editableContentDiscovery->isEditable($entity_type, $bundle)) {
        throw new BadRequestHttpException('The `bundle` request body property must name a Canvas-editable bundle of this entity type.');
      }
      self::checkCreateAccess($this->entityTypeManager, $entity_type, $bundle);
      $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
      $values = [];
      $bundle_key = $entity_type_definition->getKey('bundle');
      if (\is_string($bundle_key) && $bundle_key !== '') {
        $values[$bundle_key] = $bundle;
      }
      $label_key = $entity_type_definition->getKey('label');
      if (\is_string($label_key) && $label_key !== '') {
        $bundle_label = $this->entityTypeBundleInfo->getBundleInfo($entity_type)[$bundle]['label'] ?? $bundle;
        $values[$label_key] = (string) ($body['title'] ?? static::defaultDraftTitle($bundle_label));
      }
      $new = $this->entityTypeManager->getStorage($entity_type)->create($values);
      if ($new instanceof EntityPublishedInterface) {
        $new->setUnpublished();
      }
      $new->save();
    }
    \assert($new instanceof ContentEntityInterface && $new instanceof EntityPublishedInterface);
    $data = $this->normalizeWithMetadataAndComponents($new, new CacheableMetadata());
    // This was the app client uses instead of `id`, added for BC compatibility.
    // @todo Remove this in a follow-up, along with the anyOf added to
    //   openapi.yml file
    $data['entity_id'] = $new->id();
    $data['entity_type'] = $entity_type;
    return new JsonResponse($data, RESPONSE::HTTP_CREATED);
  }

  /**
   * Throws unless the current user may create entities of the given bundle.
   *
   * The create route cannot check bundle-level create access itself because
   * the bundle is in the request body.
   *
   * @see \Drupal\canvas\Access\EditableContentAccessCheck
   */
  private static function checkCreateAccess(EntityTypeManagerInterface $entity_type_manager, string $entity_type, string $bundle): void {
    $access = $entity_type_manager->getAccessControlHandler($entity_type)
      ->createAccess($bundle, return_as_object: TRUE);
    \assert($access instanceof AccessResult);
    if (!$access->isAllowed()) {
      throw new CacheableAccessDeniedHttpException(CacheableMetadata::createFromObject($access), 'You do not have permission to create this content.');
    }
  }

  /**
   * Auto-saves entity field values without touching any component tree.
   *
   * The write path for editing a referenced entity's fields in a stacked form
   * panel: the same `entity_form_fields` form replay the layout POST uses
   * (one write path for entity field data), minus the layout/model, because
   * the stacked panel edits the referenced entity's fields only. The edit is
   * captured as that entity's own pending change, so the review UI lists it
   * alongside the host entity's and both publish atomically together.
   *
   * @see \Drupal\canvas\ClientDataToEntityConverter::applyEntityFormFields()
   * @see docs/adr/0021-content-entity-field-editing-generalizes-the-semi-coupled-form-pipeline.md
   */
  public function patchEntityFormFields(Request $request, string $entity_type, FieldableEntityInterface $entity): JsonResponse {
    $body = self::decode($request);
    $entity_form_fields = $body['entity_form_fields'] ?? NULL;
    if (!\is_array($entity_form_fields) || $entity_form_fields === []) {
      throw new BadRequestHttpException('Missing entity_form_fields.');
    }
    // Work on the auto-saved draft when one exists so successive stacked
    // edits accumulate instead of resetting to the stored entity.
    // @todo Add auto-save conflict detection (the hash validation the layout
    //   POST performs) once the stacked panel tracks auto-save hashes;
    //   concurrent stacked editors currently last-write-win.
    $auto_save_entity = $this->autoSaveManager->getAutoSaveEntity($entity)->entity;
    if ($auto_save_entity instanceof FieldableEntityInterface) {
      $entity = $auto_save_entity;
    }
    // Validation is deferred to publish: violations are stored, not returned,
    // matching the layout POST's auto-save semantics.
    $this->converter->applyEntityFormFields($entity, $entity_form_fields, validate: FALSE);
    $this->autoSaveManager->saveEntity($entity, isset($body['clientInstanceId']) && \is_string($body['clientInstanceId']) ? $body['clientInstanceId'] : NULL);
    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

  /**
   * Deletes entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $canvas_page
   *   Entity to delete.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Response.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public static function delete(ContentEntityInterface $canvas_page): JsonResponse {
    $canvas_page->delete();
    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

  /**
   * Returns a list of content entities, with only high-level metadata.
   *
   * TRICKY: there are reasons Canvas has its own internal HTTP API rather than
   * using Drupal core's JSON:API. As soon as this method is updated to return
   * all fields instead of just high-level metadata, those reasons may start to
   * outweigh the downsides of adding a dependency on JSON:API.
   *
   * @see https://www.drupal.org/project/canvas/issues/3500052#comment-15966496
   */
  public function list(string $entity_type, Request $request): CacheableJsonResponse {
    // Beyond Canvas pages, every Canvas-editable bundle (an enabled `full`
    // view mode content template) is listable so the content browser can
    // browse, search, filter, and open all supported content. Other bundles
    // of the same entity type are excluded. Entity access is enforced by the
    // queries below (and, for templated types, by the update-access row
    // filter), never by permission-string heuristics.
    $editable_bundles = $this->editableContentDiscovery->getEditableBundles($entity_type);
    $active_bundles = $entity_type === Page::ENTITY_TYPE_ID ? [] : $editable_bundles;
    if ($entity_type !== Page::ENTITY_TYPE_ID && $active_bundles === []) {
      throw new BadRequestHttpException('Only the `canvas_page` content entity type and bundles with an enabled full view mode content template are supported right now, will be generalized further in a child issue of https://www.drupal.org/project/canvas/issues/3498525.');
    }
    $storage = $this->entityTypeManager->getStorage($entity_type);

    $query_cacheability = (new CacheableMetadata())
      ->addCacheContexts($storage->getEntityType()->getListCacheContexts())
      ->addCacheTags($storage->getEntityType()->getListCacheTags());
    // The listable set changes when templates are created, deleted, enabled,
    // or disabled.
    if ($entity_type !== Page::ENTITY_TYPE_ID) {
      $query_cacheability->addCacheTags([ContentTemplate::ENTITY_TYPE_ID . '_list']);
    }
    $bundle_key = $storage->getEntityType()->getKey('bundle');

    // An optional bundle filter narrows the list to one editable bundle
    // ("Content type" filter in the content browser).
    $query_cacheability->addCacheContexts(['url.query_args:filter']);
    $filter = $request->query->all('filter');
    $bundle_filter = $filter['bundle'] ?? NULL;
    if ($bundle_filter !== NULL) {
      if (!\is_string($bundle_filter) || !\in_array($bundle_filter, $editable_bundles, TRUE)) {
        throw new BadRequestHttpException('The `filter[bundle]` query parameter must name a Canvas-editable bundle of this entity type.');
      }
      if ($entity_type !== Page::ENTITY_TYPE_ID) {
        $active_bundles = [$bundle_filter];
      }
    }

    // Prepare search term and determine if we're performing a search
    $search = $request->query->get('search', default: NULL);
    $query_cacheability->addCacheContexts(['url.query_args:search']);

    // Get the (ordered) list of content entity IDs to load, either:
    // - without a search term: paginate the newest content entities
    $offset = OffsetPage::DEFAULT_OFFSET;
    $limit = OffsetPage::MAX_SIZE;
    if ($search === NULL) {
      $query_cacheability->addCacheContexts(['url.query_args:page']);

      $page = OffsetPage::createFromRequest($request);
      $offset = $page->getOffset();
      $limit = $page->getLimit();

      $content_entity_type = $this->entityTypeManager->getDefinition($entity_type);
      \assert($content_entity_type instanceof ContentEntityTypeInterface);
      $revision_created_field_name = $content_entity_type->getRevisionMetadataKey('revision_created');
      // @todo Ensure this is one of the required characteristics in https://www.drupal.org/project/canvas/issues/3498525.
      \assert(\is_string($revision_created_field_name));
      $id_key = $content_entity_type->getKey('id');
      \assert(\is_string($id_key));

      $count_query = $storage->getQuery()->accessCheck(TRUE)->count();
      if ($active_bundles !== [] && \is_string($bundle_key)) {
        $count_query->condition($bundle_key, $active_bundles, 'IN');
      }
      $total_count = (int) $this->executeQueryInRenderContext(
        $count_query,
        $query_cacheability
      );

      // An optional JSON:API-style sort (`sort=title`, `sort=-created`, ...)
      // backs the content browser's sortable columns; without it, the newest
      // content comes first (the historical order).
      $query_cacheability->addCacheContexts(['url.query_args:sort']);
      $sort_param = $request->query->get('sort');
      $entity_query = $storage->getQuery()
        ->accessCheck(TRUE);
      if ($sort_param !== NULL) {
        \assert(\is_string($sort_param));
        $sort_key = \ltrim($sort_param, '-');
        $sortable_fields = $this->getSortableFields($entity_type);
        if (!\array_key_exists($sort_key, $sortable_fields)) {
          throw new BadRequestHttpException(\sprintf('The `sort` query parameter must be one of: %s, each optionally prefixed with `-` for descending order.', \implode(', ', \array_keys($sortable_fields))));
        }
        $entity_query->sort($sortable_fields[$sort_key], \str_starts_with($sort_param, '-') ? 'DESC' : 'ASC');
      }
      else {
        $entity_query->sort($revision_created_field_name, direction: 'DESC');
      }
      $entity_query
        // Add a secondary sort by entity ID to ensure stable pagination when
        // multiple entities have the same revision_created timestamp. Without
        // this, the database may return items in different orders across
        // paginated requests, causing duplicates or missing items.
        ->sort($id_key)
        ->range($offset, $limit);
      if ($active_bundles !== [] && \is_string($bundle_key)) {
        $entity_query->condition($bundle_key, $active_bundles, 'IN');
      }

      $ids = $this->executeQueryInRenderContext($entity_query, $query_cacheability);
      \assert(\is_array($ids));
    }
    // - with a search term: get the N best matches using the entity reference
    //   selection plugin, get all auto-save matches, and combine both.
    //   Pagination is not supported for search, because it's not only
    //   searching stored pages with a query, but also including matches
    //   in auto-save results.
    else {
      \assert(\is_string($search));
      $search = trim($search);
      $ids = $this->filterAndMergeIds(
        // TRICKY: covered by the "list cacheability" at the top.
        $this->getMatchingStoredEntityIds($entity_type, $search, $active_bundles),
        $this->getMatchingAutoSavedEntityIds($entity_type, $search, $query_cacheability, $active_bundles)
      );
      $total_count = NULL;
    }

    /** @var \Drupal\Core\Entity\EntityPublishedInterface[] $content_entities */
    $content_entities = $storage->loadMultiple($ids);
    $data = [];
    foreach ($content_entities as $content_entity) {
      // For templated types the list is an editorial surface (rows carry
      // auto-save labels and paths of unpublished work and link into the
      // editor), so rows are restricted to entities the user may update.
      // canvas_page keeps its historical view-filtered listing, whose route
      // requires the `edit canvas_page` permission. `meta.count` stays
      // query-based, so it may exceed the visible rows for users with
      // partial update access.
      if ($entity_type !== Page::ENTITY_TYPE_ID) {
        $update_access = $content_entity->access('update', return_as_object: TRUE);
        $query_cacheability->addCacheableDependency($update_access);
        if (!$update_access->isAllowed()) {
          continue;
        }
      }
      $data[] = $this->normalize($content_entity, $query_cacheability);
    }

    $response_body = ['data' => $data];
    if ($total_count !== NULL) {
      $response_body['meta'] = ['count' => $total_count];
      $response_body['links'] = $this->buildPaginationLinks($request, $offset, $limit, $total_count);
    }

    $json_response = new CacheableJsonResponse($response_body);
    $json_response->addCacheableDependency($query_cacheability);

    return $json_response;
  }

  /**
   * Maps public sort keys to entity base field names for an entity type.
   *
   * @param string $entity_type_id
   *   The content entity type ID.
   *
   * @return array<string, string>
   *   Base field names keyed by the public sort key. Only fields the entity
   *   type actually has are included, so unsupported sorts are rejected with
   *   a helpful message.
   */
  private function getSortableFields(string $entity_type_id): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    \assert($entity_type instanceof ContentEntityTypeInterface);
    $base_fields = $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);
    $map = [];
    $label_key = $entity_type->getKey('label');
    if (\is_string($label_key) && $label_key !== '') {
      $map['title'] = $label_key;
    }
    foreach (['created', 'changed'] as $timestamp_field) {
      if (isset($base_fields[$timestamp_field])) {
        $map[$timestamp_field] = $timestamp_field;
      }
    }
    return $map;
  }

  /**
   * Builds JSON:API-style pagination links for a collection response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param int $offset
   *   The current page offset.
   * @param int $limit
   *   The current page size.
   * @param int $total
   *   The total number of items in the collection.
   *
   * @return array<string, array{href: string}>
   *   An associative array of link objects keyed by relation type.
   *
   * @see https://jsonapi.org/format/#fetching-pagination
   */
  private static function buildPaginationLinks(Request $request, int $offset, int $limit, int $total): array {
    $uri = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo();
    $existing_query = $request->query->all();
    unset($existing_query['page']);

    $make_link = static function (int $link_offset) use ($uri, $existing_query, $limit): array {
      $query = $existing_query + ['page' => ['offset' => $link_offset, 'limit' => $limit]];
      return ['href' => Url::fromUri($uri)->setOption('query', $query)->toString()];
    };

    $last_offset = $total > 0 ? (int) (floor(($total - 1) / $limit) * $limit) : 0;

    $links = ['self' => $make_link($offset)];

    if ($offset > 0) {
      $links['first'] = $make_link(0);
      $links['prev'] = $make_link(max(0, $offset - $limit));
    }
    if ($offset + $limit < $total) {
      $links['next'] = $make_link($offset + $limit);
      $links['last'] = $make_link($last_offset);
    }

    return $links;
  }

  /**
   * Normalizes content entity.
   *
   * @param \Drupal\Core\Entity\EntityPublishedInterface $content_entity
   *   The content entity to prepare data for.
   * @param \Drupal\Core\Cache\CacheableMetadata $url_cacheability
   *   The cacheability metadata object to add URL dependencies to.
   *
   * @return array
   *   An associative array containing the normalized entity.
   */
  private function normalize(EntityPublishedInterface $content_entity, CacheableMetadata $url_cacheability): array {
    $generated_url = $content_entity->toUrl()->toString(TRUE);

    $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($content_entity);
    $autoSaveEntity = $autoSaveData->isEmpty() ? NULL : $autoSaveData->entity;

    $publishableAutoSaveEntity = ($autoSaveEntity instanceof EntityPublishedInterface)
      ? $autoSaveEntity
      : NULL;
    // Expose available entity operations. Pass both original and auto-save
    // entities to allow reverting unpublish/publish actions when auto-save has
    // opposite status.
    $linkCollection = $this->getEntityOperations($content_entity, $publishableAutoSaveEntity);

    // Determine the effective published status: use auto-save status if
    // available, otherwise use original.
    $effective_status = ($autoSaveEntity instanceof EntityPublishedInterface)
      ? $autoSaveEntity->isPublished()
      : $content_entity->isPublished();

    // @todo Dynamically use the entity 'path' key to determine which field is
    //   the path in https://drupal.org/i/3503446.
    $autoSavePath = NULL;
    if ($autoSaveEntity instanceof FieldableEntityInterface && $autoSaveEntity->hasField('path')) {
      $autoSavePath = $autoSaveEntity->get('path')->first()?->getValue()['alias'] ?? \sprintf('/%s', \ltrim($autoSaveEntity->toUrl()->getInternalPath(), '/'));
    }

    // Determine if there's an unsaved status change.
    // This happens when auto-save exists with a different published status
    // than the original entity.
    $has_unsaved_status_change = FALSE;
    if ($autoSaveEntity instanceof EntityPublishedInterface) {
      $has_unsaved_status_change = $autoSaveEntity->isPublished() !== $content_entity->isPublished();
    }

    $url_cacheability->addCacheableDependency($generated_url)
      ->addCacheableDependency($linkCollection)
      ->addCacheableDependency($autoSaveData);

    \assert($content_entity instanceof ContentEntityInterface);
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($content_entity->getEntityTypeId());
    $owner = $content_entity instanceof EntityOwnerInterface ? $content_entity->getOwner() : NULL;
    if ($owner !== NULL) {
      // `authorName` must refresh when the owner's display name changes.
      $url_cacheability->addCacheableDependency($owner);
    }
    return [
      'id' => (int) $content_entity->id(),
      'uuid' => $content_entity->uuid(),
      'title' => $content_entity->label(),
      // Browser columns: the entity type and bundle ("Type"), the author, and
      // the created/changed timestamps. NULL when the entity type does not
      // have the concept.
      'entityType' => $content_entity->getEntityTypeId(),
      'bundle' => $content_entity->bundle(),
      'bundleLabel' => (string) ($bundle_info[$content_entity->bundle()]['label'] ?? $content_entity->getEntityType()->getSingularLabel()),
      'authorName' => $owner?->getDisplayName(),
      'created' => $content_entity->hasField('created') ? (int) $content_entity->get('created')->getString() : NULL,
      'changed' => $content_entity instanceof EntityChangedInterface ? (int) $content_entity->getChangedTime() : NULL,
      // Return the effective status (autosaved if exists, otherwise original).
      'status' => $effective_status,
      // Indicates if this is a new (draft) page that has never been published.
      'isNew' => AutoSaveManager::entityIsConsideredNew($content_entity),
      // Indicates if there's an unsaved status change
      // (unpublish/publish in auto-save).
      'hasUnsavedStatusChange' => $has_unsaved_status_change,
      // The processed path, which has gone through outbound path processors. It
      // may not be the same as the entity's canonical link template.
      'path' => $generated_url->getGeneratedUrl(),
      // The internal path, which has not been processed and is the entity's
      // canonical link template.
      'internalPath' => '/' . $content_entity->toUrl()->getInternalPath(),
      'autoSaveLabel' => $autoSaveEntity?->label(),
      'autoSavePath' => $autoSavePath,
      // @see https://jsonapi.org/format/#document-links
      'links' => $linkCollection->asArray(),
    ];
  }

  /**
   * Normalizes content entity including metadata and component tree fields.
   *
   * @param \Drupal\Core\Entity\EntityPublishedInterface&\Drupal\Core\Entity\ContentEntityInterface $content_entity
   *   The content entity to prepare data for.
   * @param \Drupal\Core\Cache\CacheableMetadata $url_cacheability
   *   The cacheability metadata object to add URL dependencies to.
   *
   * @return array
   *   An associative array containing the normalized entity.
   */
  private function normalizeWithMetadataAndComponents(EntityPublishedInterface&ContentEntityInterface $content_entity, CacheableMetadata $url_cacheability): array {
    $data = $this->normalizeWithComponents($content_entity, $url_cacheability);

    if ($content_entity->hasField('description')) {
      $field_access = $content_entity->get('description')->access('view', return_as_object: TRUE);
      if (!$field_access->isForbidden()) {
        $data['description'] = $content_entity->get('description')->getString();
      }
      $url_cacheability->addCacheableDependency($field_access);
    }

    // Move links last for legibility.
    if (isset($data['links'])) {
      $links = $data['links'];
      unset($data['links']);
      $data['links'] = $links;
    }
    return $data;
  }

  /**
   * Normalizes content entity including component tree fields.
   *
   * @param \Drupal\Core\Entity\EntityPublishedInterface&\Drupal\Core\Entity\ContentEntityInterface $content_entity
   *   The content entity to prepare data for.
   * @param \Drupal\Core\Cache\CacheableMetadata $url_cacheability
   *   The cacheability metadata object to add URL dependencies to.
   *
   * @return array
   *   An associative array containing the normalized entity.
   */
  private function normalizeWithComponents(EntityPublishedInterface&ContentEntityInterface $content_entity, CacheableMetadata $url_cacheability): array {
    $data = $this->normalize($content_entity, $url_cacheability);
    foreach ($content_entity->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition->getType() !== ComponentTreeItem::PLUGIN_ID) {
        continue;
      }
      $components = [];
      foreach ($content_entity->get($field_name) as $item) {
        \assert($item instanceof ComponentTreeItem);
        $values = \array_map(fn($property) => $property->getValue(), $item->getProperties(TRUE));

        // Ensure `parent_item` is not included, as that would be repetitive
        // information the consumer of this API can already access.
        // Component is also irrelevant, as will be empty.
        unset($values['parent_item'], $values['component']);
        // Ensure `inputs` is not a JSON string, but a structured value,
        // so API consumers receive a proper object rather than a JSON string.
        $values['inputs'] = $item->getInputs();
        $components[] = $values;
      }
      $field_access = $content_entity->get($field_name)->access('view', return_as_object: TRUE);
      if (!$field_access->isForbidden()) {
        $data[$field_name] = $components;
      }
      $url_cacheability->addCacheableDependency($field_access);
    }
    // Move links last for legibility.
    $links = $data['links'];
    unset($data['links']);
    $data['links'] = $links;
    return $data;
  }

  /**
   * Gets N first saved ("live") entity IDs matching the search term.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $search
   *   The (transliterated) search term to match against entities.
   * @param string[] $bundles
   *   Restrict matches to these bundles. Empty means all bundles.
   *
   * @return array
   *   An array of entity IDs that match the search term.
   */
  private function getMatchingStoredEntityIds(string $entity_type_id, string $search, array $bundles = []): array {
    $configuration = [
      'target_type' => $entity_type_id,
      'handler' => 'default',
    ];
    if ($bundles !== []) {
      $configuration['target_bundles'] = \array_combine($bundles, $bundles);
    }
    /** @var \Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface $selection_handler */
    $selection_handler = $this->selectionManager->getInstance($configuration);
    \assert($selection_handler instanceof SelectionInterface);
    $matching_data = $selection_handler->getReferenceableEntities(
      $search,
      'CONTAINS',
      self::MAX_SEARCH_RESULTS
    );

    return \array_keys(NestedArray::mergeDeepArray($matching_data, TRUE));
  }

  /**
   * Gets N first auto-saved ("draft") entity IDs matching the search term.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $search
   *   The search term to match against entities.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   The cacheability of the given query, to be refined to match the
   *   refinements made to the query.
   * @param string[] $bundles
   *   Restrict matches to these bundles. Empty means all bundles.
   *
   * @return array
   *   An array of entity IDs that match the search criteria.
   */
  private function getMatchingAutoSavedEntityIds(string $entity_type_id, string $search, RefinableCacheableDependencyInterface $cacheability, array $bundles = []): array {
    $cacheability->addCacheTags([AutoSaveManager::CACHE_TAG]);
    $auto_saved_entities_of_type = \array_filter($this->autoSaveManager->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE), static fn (array $entry): bool => $entry['entity_type'] === $entity_type_id);

    // Transliterate the search term using the negotiated content language.
    $cacheability->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT]);
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $transliterated_search = $this->transliteration->transliterate(mb_strtolower($search), $langcode);

    // Check if the transliterated search term is contained by any of the auto-
    // saved entities of this type.
    $matching_unsaved_ids = [];
    foreach ($auto_saved_entities_of_type as ['entity' => $entity]) {
      \assert($entity instanceof EntityInterface);
      if ($bundles !== [] && !\in_array($entity->bundle(), $bundles, TRUE)) {
        continue;
      }
      // Respect entity access: an auto-saved draft must not leak to a user who
      // cannot view the entity. The browse path applies accessCheck(TRUE);
      // the search path must filter these auto-save matches equivalently.
      $access = $entity->access('view', return_as_object: TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $transliterated_label = $this->transliteration->transliterate(mb_strtolower((string) $entity->label()), $langcode);
      if (str_contains($transliterated_label, $transliterated_search)) {
        $matching_unsaved_ids[] = $entity->id();
      }
    }

    return $matching_unsaved_ids;
  }

  /**
   * Filters and merges entity IDs based on search results.
   *
   * @param array $matching_ids
   *   The array of entity IDs that match the search term.
   * @param array $matching_unsaved_ids
   *   The array of unsaved entity IDs that match the search term.
   *
   * @return array
   *   The filtered and merged array of entity IDs.
   */
  private static function filterAndMergeIds(array $matching_ids, array $matching_unsaved_ids): array {
    // Sort by newest first (keys will be numeric IDs) and limit to max results
    $ids = array_unique(array_merge($matching_ids, $matching_unsaved_ids));
    arsort($ids);
    $ids = array_slice($ids, 0, self::MAX_SEARCH_RESULTS, TRUE);
    return $ids;
  }

  /**
   * Duplicates entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to duplicate.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Newly created entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function duplicate(ContentEntityInterface $entity): ContentEntityInterface {
    $duplicate = $entity->createDuplicate();

    // Get temp data of original entity.
    if ($entity = $this->autoSaveManager->getAutoSaveEntity($entity)->entity) {
      // Before merging temp data remove path value to avoid collision.
      // @todo Remove hardcoded field name when https://www.drupal.org/project/canvas/issues/3503446 lands.
      $duplicate = $entity->createDuplicate();
      \assert($duplicate instanceof ContentEntityInterface);
    }

    // Update title and status.
    $entity_type = $duplicate->getEntityType();
    $entity_key = $entity_type->getKey('label') ?? 'title';
    // @phpstan-ignore-next-line
    $duplicate->set($entity_key, $duplicate->label() . AutoSaveManager::ENTITY_DUPLICATE_SUFFIX);
    \assert($duplicate instanceof EntityPublishedInterface);
    $duplicate->setUnpublished();
    $duplicate->save();

    // Delete temp data for the duplicate, it should not have it at this point.
    // Everything is saved.
    $this->autoSaveManager->delete($duplicate);

    return $duplicate;
  }

  /**
   * Executes the query in a render context, to catch bubbled cacheability.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The query to execute to get the return results.
   * @param \Drupal\Core\Cache\CacheableMetadata $query_cacheability
   *   The value object to carry the query cacheability.
   *
   * @return array|int
   *   Returns IDs of entities, or a count when a count query is passed.
   *
   * @see \Drupal\jsonapi\Controller\EntityResource::executeQueryInRenderContext()
   */
  private function executeQueryInRenderContext(QueryInterface $query, CacheableMetadata $query_cacheability) : array|int {
    $context = new RenderContext();
    $results = $this->renderer->executeInRenderContext($context, function () use ($query) {
      return $query->execute();
    });
    if (!$context->isEmpty()) {
      $query_cacheability->addCacheableDependency($context->pop());
    }
    return $results;
  }

  public static function defaultTitle(EntityTypeInterface $entity_type): TranslatableMarkup {
    return new TranslatableMarkup('Untitled @singular_entity_type_label', ['@singular_entity_type_label' => $entity_type->getSingularLabel()]);
  }

  /**
   * The placeholder label for a newly created draft of a templated bundle.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::entityIsConsideredNew()
   */
  public static function defaultDraftTitle(string|TranslatableMarkup $bundle_label): TranslatableMarkup {
    return new TranslatableMarkup('Untitled @bundle', ['@bundle' => $bundle_label]);
  }

  public function getEntityOperations(EntityPublishedInterface $content_entity, ?EntityPublishedInterface $autoSaveEntity = NULL): CanvasResourceLinkCollection {
    $links = new CanvasResourceLinkCollection([]);

    // Add auto-save entity as cache dependency if it exists, so cache
    // invalidates when auto-save changes.
    if ($autoSaveEntity) {
      $links->addCacheableDependency($autoSaveEntity);
    }

    // For templated content entities (everything but Canvas pages) the only
    // operation is opening the entity in the Canvas editor. Publish, unpublish,
    // set-as-homepage, duplicate and delete rely on canvas_page-only routes.
    // @todo Standardize these routes across entity types in https://www.drupal.org/i/3498525.
    if (!$content_entity instanceof Page) {
      \assert($content_entity instanceof ContentEntityInterface);
      $edit_access = $content_entity->access(operation: 'update', account: $this->currentUser, return_as_object: TRUE);
      \assert($edit_access instanceof AccessResult);
      if ($edit_access->isAllowed()) {
        $links = $links->withLink(
          CanvasUriDefinitions::LINK_REL_EDIT,
          new CanvasResourceLink($edit_access, $this->getUrlFromRoute($content_entity, 'canvas.boot.entity'), CanvasUriDefinitions::LINK_REL_EDIT)
        );
      }
      else {
        $links->addCacheableDependency($edit_access);
      }
      return $links;
    }

    // Helper to create forbidden access result with auto-save cache dependency.
    $createForbiddenAccess = function (string $reason) use ($autoSaveEntity): AccessResult {
      $access = AccessResult::forbidden($reason);
      if ($autoSaveEntity) {
        $access->addCacheableDependency($autoSaveEntity);
      }
      return $access;
    };

    // Link relation type => route name.
    $possible_operations = [
      CanvasUriDefinitions::LINK_REL_DELETE => ['route_name' => 'canvas.api.content.delete', 'op' => 'delete'],
      CanvasUriDefinitions::LINK_REL_EDIT => ['route_name' => 'canvas.boot.entity', 'op' => 'update'],
      // Setting the homepage is a staged configuration update, the UI will
      // call `canvas.api.config.post` but for the access check
      // use the content entity's access.
      // Conceptually, this is an operation on the content entity, so expose it
      // as a non-standard link operation.
      CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => ['route_name' => 'canvas.boot.entity', 'op' => 'update'],
      CanvasUriDefinitions::LINK_REL_DUPLICATE => [
        'route_name' => 'canvas.api.content.create',
        'op' => 'create',
      ],
      CanvasUriDefinitions::LINK_REL_UNPUBLISH => [
        'route_name' => 'canvas.api.content.auto-save.patch',
        'op' => 'update',
      ],
      CanvasUriDefinitions::LINK_REL_PUBLISH => [
        'route_name' => 'canvas.api.content.auto-save.patch',
        'op' => 'update',
      ],
    ];
    // Determine which status-based action (unpublish/publish) should be shown.
    // Only one should be shown at a time, based on the effective status
    // and revert capability.
    $original_is_published = $content_entity->isPublished();
    $auto_save_is_published = $autoSaveEntity?->isPublished() ?? NULL;

    // Determine effective status: use auto-save if it exists,
    // otherwise use original.
    $effective_is_published = $auto_save_is_published ?? $original_is_published;

    // Check if this is a draft (never been published). $content_entity is a
    // Page here (the non-Page branch returned above).
    $is_draft = AutoSaveManager::entityIsConsideredNew($content_entity);

    // Determine which actions to show based on state.
    // Drafts use the main Publish button, not unpublish/publish actions.
    if ($is_draft) {
      $should_show_unpublish = FALSE;
      $should_show_publish = FALSE;
    }
    // Auto-save has opposite status: show revert action.
    elseif ($auto_save_is_published !== NULL && $auto_save_is_published !== $original_is_published) {
      $should_show_unpublish = $auto_save_is_published;
      $should_show_publish = !$auto_save_is_published;
    }
    // No auto-save or auto-save matches original: show normal action.
    else {
      $should_show_unpublish = $effective_is_published;
      $should_show_publish = !$effective_is_published;
    }

    // Don't show unpublish link if this page is the homepage (current or
    // staged).
    $should_show_unpublish = $should_show_unpublish && !$this->homePageHelper->isHomepage($content_entity);

    foreach ($possible_operations as $link_rel => ['route_name' => $route_name, 'op' => $entity_operation]) {
      // Special handling for set as homepage operation: don't show for
      // unpublished pages (but allow for draft pages).
      if ($link_rel === CanvasUriDefinitions::LINK_REL_SET_AS_HOMEPAGE) {
        $access = (!$effective_is_published && !$is_draft)
          ? $createForbiddenAccess('Set as homepage action not available for unpublished pages.')
          : $content_entity->access(operation: $entity_operation, return_as_object: TRUE);
      }
      // Special handling for unpublish operation: only show if determined
      // above.
      elseif ($link_rel === CanvasUriDefinitions::LINK_REL_UNPUBLISH) {
        $access = !$should_show_unpublish
          ? $createForbiddenAccess('Unpublish action not available for this page state.')
          : $content_entity->access(operation: 'update', account: $this->currentUser, return_as_object: TRUE);
      }
      // Special handling for publish operation: only show if determined above.
      elseif ($link_rel === CanvasUriDefinitions::LINK_REL_PUBLISH) {
        $access = !$should_show_publish
          ? $createForbiddenAccess('Publish action not available for this page state.')
          : $content_entity->access(operation: 'update', account: $this->currentUser, return_as_object: TRUE);
      }
      else {
        $access = $content_entity->access(operation: $entity_operation, return_as_object: TRUE);
        if ($entity_operation === 'create') {
          $access = $this->entityTypeManager->getAccessControlHandler($content_entity->getEntityTypeId())
            ->createAccess(entity_bundle: $content_entity->bundle(), return_as_object: TRUE);
        }
      }
      \assert($access instanceof AccessResult);
      if ($access->isAllowed()) {
        $links = $links->withLink(
          $link_rel,
          new CanvasResourceLink($access, $this->getUrlFromRoute($content_entity, $route_name), $link_rel)
        );
      }
      else {
        $links->addCacheableDependency($access);
      }
    }
    return $links;
  }

  /**
   * Gets the url for an operation route given the content entity.
   *
   * Ideally, we would have standardized routes, and we wouldn't need a helper,
   * nor to compile the routes.
   * This might be achievable when we complete https://www.drupal.org/i/3498525.
   */
  private function getUrlFromRoute(EntityInterface $content_entity, string $route_name): Url {
    // @todo https://www.drupal.org/i/3498525 should standardize the
    //   route params. We need this helper for now.
    $match = fn($param) => match($param) {
      'entity_type' => $content_entity->getEntityTypeId(),
      'entity' => $content_entity->id(),
      $content_entity->getEntityTypeId() => $content_entity->id(),
      default => throw new \InvalidArgumentException('We cannot map this route parameter'),
    };
    $route = $this->routeProvider->getRouteByName($route_name);
    $route_parameters = $route->compile()->getVariables();
    $params = [];
    foreach ($route_parameters as $param) {
      $params[$param] = $match($param);
    }
    return Url::fromRoute($route_name, $params);
  }

}
