<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\AutoSavePublishAwareInterface;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Handles retrieval and publication of auto-saved changes.
 *
 * @phpstan-import-type AutoSaveEntry from AutoSaveManager
 */
final class ApiAutoSaveController extends ApiControllerBase {

  use ConstraintPropertyPathTranslatorTrait;

  public const AUTO_SAVE_KEY = 'api_auto_save_key';
  public const AUTO_SAVE_CONFLICT_KEY = 'conflict_id';
  public const AVATAR_IMAGE_STYLE = 'canvas_avatar';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly AutoSaveManager $autoSaveManager,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
    private readonly AccountInterface $currentUser,
    private readonly ComponentSourceManager $componentSourceManager,
    private readonly ComponentTreeLoader $componentTreeLoader,
  ) {}

  private static function validateExpectedAutoSaves(array $expected_auto_saves, array $publishable_auto_saves): ?JsonResponse {
    $unexpected_keys = \array_diff_key($expected_auto_saves, $publishable_auto_saves);
    if ($unexpected_keys) {
      $errors = [];
      foreach (\array_keys($unexpected_keys) as $key) {
        $errors[] = [
          'detail' => ErrorCodesEnum::UnexpectedItemInPublishRequest->getMessage(),
          'source' => [
            'pointer' => $key,
          ],
          'code' => ErrorCodesEnum::UnexpectedItemInPublishRequest->value,
        ];
      }
      return new JsonResponse(data: ['errors' => $errors], status: Response::HTTP_CONFLICT);
    }
    // Check the data hashes.
    $unmatched_keys = \array_values(\array_filter(\array_keys($expected_auto_saves), function ($key) use ($expected_auto_saves, $publishable_auto_saves) {
      return !\hash_equals($expected_auto_saves[$key]['data_hash'], $publishable_auto_saves[$key]['data_hash']);
    }));
    if ($unmatched_keys) {
      return new JsonResponse(data: [
        'errors' => \array_map(static fn(string $key) => [
          'detail' => ErrorCodesEnum::UnmatchedItemInPublishRequest->getMessage(),
          'source' => [
            'pointer' => $key,
          ],
          'code' => ErrorCodesEnum::UnmatchedItemInPublishRequest->value,
          'meta' => \array_intersect_key($publishable_auto_saves[$key], \array_flip([
            'entity_type',
            'entity_id',
            'label',
          ])) + [
            self::AUTO_SAVE_KEY => $key,
          ],
        ], $unmatched_keys),
      ], status: Response::HTTP_CONFLICT);
    }
    // If any JavaScriptComponents are being published ensure dependent global
    // config surfaces are also being published.
    // @todo Improve this in https://www.drupal.org/project/canvas/issues/3535038
    foreach ([AssetLibrary::load(AssetLibrary::GLOBAL_ID), BrandKit::load(BrandKit::GLOBAL_ID)] as $global_dependency) {
      if ($global_dependency === NULL) {
        continue;
      }
      $global_dependency_key = AutoSaveManager::getAutoSaveKey($global_dependency);
      if (\array_key_exists($global_dependency_key, $publishable_auto_saves) && !\array_key_exists($global_dependency_key, $expected_auto_saves)) {
        foreach ($expected_auto_saves as $client_auto_save) {
          if ($client_auto_save['entity_type'] === JavaScriptComponent::ENTITY_TYPE_ID) {
            return new JsonResponse(data: [
              'errors' => [
                [
                  'detail' => ErrorCodesEnum::GlobalAssetNotPublished->getMessage(),
                  'source' => [
                    'pointer' => $global_dependency_key,
                  ],
                  'code' => ErrorCodesEnum::GlobalAssetNotPublished->value,
                  'meta' => \array_intersect_key($publishable_auto_saves[$global_dependency_key], \array_flip([
                    'entity_type',
                    'entity_id',
                    'label',
                  ])) + [
                    self::AUTO_SAVE_KEY => $global_dependency_key,
                  ],
                ],
              ],
            ], status: Response::HTTP_FAILED_DEPENDENCY);
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Adds the sibling translation auto-saves of every selected content entity.
   *
   * Symmetric translation writes the shared component-tree columns for every
   * translation at once, so the translations of one content entity form a
   * single atomic publish unit. Given the entries the client selected, this
   * pulls in every other pending translation auto-save of the same entity so
   * they are validated, access-checked, applied, and consumed together.
   *
   * @param array<string, AutoSaveEntry> $selected
   *   The auto-save entries the client selected to publish, with 'entity'.
   * @param array<string, AutoSaveEntry> $all
   *   Every pending auto-save entry, with 'entity'.
   *
   * @return array<string, AutoSaveEntry>
   *   $selected plus any sibling translation entries of its content entities.
   */
  private static function includeSiblingTranslationAutoSaves(array $selected, array $all): array {
    $expanded = $selected;
    foreach ($selected as $entry) {
      $entity = $entry['entity'] ?? NULL;
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      foreach ($all as $key => $candidate) {
        $candidate_entity = $candidate['entity'] ?? NULL;
        if ($candidate_entity instanceof ContentEntityInterface
          && $candidate_entity->getEntityTypeId() === $entity->getEntityTypeId()
          && (string) $candidate_entity->id() === (string) $entity->id()) {
          $expanded[$key] = $candidate;
        }
      }
    }
    return $expanded;
  }

  /**
   * Returns auto-saves the current user may see and act on.
   *
   * Mirrors both filters ::get() applies before returning data to the client:
   * 'view label' access and is_default_translation. All three endpoints
   * (GET, POST, DELETE) call this so the allowed set stays in sync.
   *
   * Pass $cache to collect cacheability metadata (needed by ::get()); omit it
   * for state-mutating callers (::post(), ::delete()).
   *
   * @param bool $with_conflicts
   *   Whether to populate the 'conflict_id' key on each entry.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cache
   *   Optional metadata collector; receives entity and access dependencies.
   *
   * @return array<string, AutoSaveEntry>
   *   Auto-save entries keyed by auto-save key, filtered to what GET exposes.
   */
  private function getPublishableAutoSaves(bool $with_conflicts, ?CacheableMetadata $cache = NULL): array {
    $all = $this->autoSaveManager->getAllAutoSaveList(with_entities: TRUE, with_conflicts: $with_conflicts);
    return \array_filter($all, function (array $item) use ($cache): bool {
      \assert($item['entity'] instanceof EntityInterface);
      $access = $item['entity']->access('view label', return_as_object: TRUE);
      if ($cache !== NULL) {
        // @todo This will result in the cache tag for this entity being returned
        //   in the response even though the user does not have access to view
        //   the entity. A less privileged user could still be able to determine
        //   that the entity exists and has pending changes. Determine if we
        //   should prevent this in https://drupal.org/i/3535355.
        $cache->addCacheableDependency($item['entity']);
        $cache->addCacheableDependency($access);
      }
      // Hide non-default-translation auto-saves until langcode-aware discard
      // lands and asymmetrical translation is supported.
      // @todo Remove this filtering in https://git.drupalcode.org/project/canvas/-/work_items/3591703.
      return $access->isAllowed() && ($item['is_default_translation'] ?? TRUE);
    });
  }

  /**
   * Gets the auto-saved changes.
   */
  public function get(): CacheableJsonResponse {
    $cache = new CacheableMetadata();
    $filtered = $this->getPublishableAutoSaves(with_conflicts: TRUE, cache: $cache);

    $userIds = \array_column($filtered, 'owner');
    /** @var \Drupal\user\UserInterface[] $users */
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($userIds);
    foreach ($users as $uid => $user) {
      $access = $user->access('view label', return_as_object: TRUE);
      $cache->addCacheableDependency($user);
      $cache->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        unset($users[$uid]);
      }
    }
    // User display names depend on configuration.
    $cache->addCacheableDependency($this->configFactory->get('user.settings'));
    $status = Response::HTTP_OK;

    $body = [];
    if (self::autoSaveListHasConflicts($filtered)) {
      $status = Response::HTTP_CONFLICT;
      foreach ($filtered as $key => $entry) {
        if (isset($entry[self::AUTO_SAVE_CONFLICT_KEY])) {
          $body['errors'][] = [
            'detail' => ErrorCodesEnum::ItemEntityUpdatedExternally->getMessage(),
            'source' => [
              'pointer' => $key,
            ],
            'code' => ErrorCodesEnum::ItemEntityUpdatedExternally->value,
            'meta' => [
              'entity_type' => $entry['entity_type'],
              'entity_id' => $entry['entity_id'],
              'label' => $entry['label'],
              self::AUTO_SAVE_CONFLICT_KEY => $entry[self::AUTO_SAVE_CONFLICT_KEY],
              self::AUTO_SAVE_KEY => $key,
            ],
          ];
        }
      }
    }

    // Remove internal auto-save properties that are not used client side (like
    // 'data', 'client_id', 'entity', etc.). This will reduce the amount of data
    // sent to the client and back to the server.
    $filtered = \array_map(fn (array $item) =>
      \array_diff_key($item, \array_flip(AutoSaveManager::AUTO_SAVE_INTERNAL_PROPERTIES)),
      $filtered
    );

    $body['data'] = \array_map(function (array $item) use ($users): array {
      \assert(\is_int($item['owner']));
      return [
        'owner' => \array_key_exists($item['owner'], $users) ? [
          'name' => $users[$item['owner']]->getDisplayName(),
          'avatar' => $this->buildAvatarUrl($users[$item['owner']]),
          'uri' => $users[$item['owner']]->toUrl()->toString(),
          'id' => $item['owner'],
        ] : [
          'name' => new TranslatableMarkup('User @uid', ['@uid' => $item['owner']]),
          'avatar' => NULL,
          'uri' => NULL,
          'id' => $item['owner'],
        ],
      ] + $item;
    }, $filtered);

    return (new CacheableJsonResponse(data: $body, status: $status))->addCacheableDependency($cache->addCacheTags([AutoSaveManager::CACHE_TAG]));
  }

  /**
   * Publishes the auto-saved changes.
   *
   * @throws \Exception
   */
  public function post(Request $request): JsonResponse {
    $client_auto_saves = \json_decode($request->getContent(), TRUE);
    \assert(\is_array($client_auto_saves));
    $publishable_auto_saves = $this->getPublishableAutoSaves(with_conflicts: FALSE);
    if ($validation_response = self::validateExpectedAutoSaves($client_auto_saves, $publishable_auto_saves)) {
      return $validation_response;
    }

    if (\count($publishable_auto_saves) === 0) {
      return new JsonResponse(data: ['message' => 'No items to publish.'], status: Response::HTTP_NO_CONTENT);
    }

    // We keep these in an array instead of making use of a collection like
    // ConstraintViolationList, so we can keep violations grouped by each
    // entity.
    $violationSets = [];
    $entities = [];
    // The per-translation auto-save snapshots whose stored data was published,
    // collected so each can be deleted afterwards (::delete() keys off the
    // entity language, so grouped translations must be deleted individually).
    $autoSaveEntities = [];
    // The client auto-saves do not contain the 'data' key, so we need to use
    // the versions from the auto-save manager.
    $publish_auto_saves = array_intersect_key($publishable_auto_saves, $client_auto_saves);
    // The number of logical items the client published, for the response
    // message. Sibling translations (added below) and config overlay drafts
    // (filtered from the pending list entirely) are part of one logical item,
    // so they must not inflate this count.
    $published_item_count = \count($publish_auto_saves);

    // Symmetric translations share the component-tree columns (the version and
    // structure), so a content entity's translations cannot be published
    // independently: publishing one must publish every pending translation of
    // the same entity, or a sibling would be left at a stale component version.
    // The non-default translations are also hidden from the client's pending
    // list, so the client can only ever select the default one.
    // @see \Drupal\canvas\Controller\ApiAutoSaveController::get()
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::getTranslationGroupAutoSaves()
    $all_auto_saves = $this->autoSaveManager->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE);
    $publish_auto_saves = self::includeSiblingTranslationAutoSaves($publish_auto_saves, $all_auto_saves);

    // We want to report all access errors at one, so keeping the labels.
    $access_error_labels = [];
    $access_error_cache = new CacheableMetadata();
    $loadedEntities = [];
    foreach ($publish_auto_saves as $autoSaveKey => ['entity' => $entity]) {
      \assert($entity instanceof EntityInterface);
      // Auto-saves always are updates to existing entities. This just used
      // EntityStorageInterface::create() to construct an entity object from
      // just its values, which for some entities would result in it being
      // considered new, when it is not. Ensure it is never considered new.
      // @see \Drupal\Core\Entity\EntityBase::isNew()
      // @see \Drupal\Core\Config\Entity\ConfigEntityBase::isNew()
      $entity->enforceIsNew(FALSE);
      $loadedEntities[$autoSaveKey] = $entity;

      $access = $entity->access(operation: 'update', return_as_object: TRUE);
      if (!$access->isAllowed()) {
        $access_error_cache->addCacheableDependency($entity);
        $access_error_cache->addCacheableDependency($access);
        $access_error_cache->addCacheTags([AutoSaveManager::CACHE_TAG]);
        $access_error_labels[] = $entity->label();
      }
    }
    if (!empty($access_error_labels)) {
      throw new CacheableAccessDeniedHttpException($access_error_cache, \sprintf('Unable to update entities: %s.', implode(', ', \array_map(fn(\Stringable|string|NULL $label) => $label ? "'$label'" : "''", $access_error_labels))));
    }

    // Track which content-entity groups were already processed. Every edited
    // translation of one content entity is published together (see
    // ::applyAutoSaveTranslationSnapshots()), so each group is handled once.
    $processed_content_groups = [];
    foreach ($loadedEntities as $entity) {
      if ($entity instanceof ConfigEntityInterface) {
        $violations = $entity->getTypedData()->validate();
        if ($violations->count() > 0) {
          $violationSets[] = new EntityConstraintViolationList($entity, $violations);
          continue;
        }
        if ($entity instanceof AutoSavePublishAwareInterface) {
          $entity->autoSavePublish();
        }
        $entity->enforceIsNew(FALSE);
        $entities[] = $entity;
        $autoSaveEntities[] = $entity;
        // StagedLanguageConfigOverride entries are filtered from
        // getAllAutoSaveList() so they never reach this loop directly. When
        // their base config entity is published, publish each automatically.
        if ($entity instanceof ComponentTreeConfigEntityBase) {
          foreach ($this->autoSaveManager->groupConfigEntityAutoSaves($entity) as $override) {
            $override->autoSavePublish();
            $override->enforceIsNew(FALSE);
            $entities[] = $override;
            $autoSaveEntities[] = $override;
          }
        }
        continue;
      }

      \assert($entity instanceof ContentEntityInterface);
      $group_key = $entity->getEntityTypeId() . ':' . $entity->id();
      if (isset($processed_content_groups[$group_key])) {
        continue;
      }
      $processed_content_groups[$group_key] = TRUE;

      $snapshots = AutoSaveManager::groupContentEntityAutoSaves($publish_auto_saves)[$group_key];
      $entity = $this->applyAutoSaveTranslationSnapshots($snapshots);
      // Even though we will validate each entity individually before it is
      // saved to ensure the data is still valid after other entities have
      // been saved, we should still validate here before we save any entities
      // to avoid saving any entities if any are invalid. This is to avoid,
      // when possible, any side effects of saving entities that cannot be
      // undone by rolling back the database transaction, such as sending
      // emails.
      $violations = $entity->validate();
      $form_violations = $this->autoSaveManager->getEntityFormViolations($entity);
      foreach ($form_violations as $form_violation) {
        // Add any form violations at this point.
        // @todo Remove this in https://drupal.org/i/3505018
        $violations->add($form_violation);
      }
      if ($violations->count() > 0) {
        $violationSets[] = self::getViolationSetsFromPropertyPathsAndRoot($entity, $violations);
        continue;
      }
      $entity->enforceIsNew(FALSE);
      $entities[] = $entity;
      \array_push($autoSaveEntities, ...$snapshots);
    }
    if ($validation_errors_response = self::createJsonResponseFromViolationSets(...$violationSets)) {
      return $validation_errors_response;
    }

    // Either everything must be published, or nothing at all.
    $lastEntityEvaluated = NULL;
    try {
      $transaction = $this->database->startTransaction();
      foreach ($entities as $entity) {
        $lastEntityEvaluated = $entity;
        // Even though the entities are being validated before, there is a
        // possibility where, when multiple entities are being saved together,
        // the first entity collides with some of the following entities. So
        // we need to validate right before saving the entity.
        self::ensureEntityIsValid($entity);
        $entity->save();
      }
      foreach ($autoSaveEntities as $entity) {
        $this->autoSaveManager->delete($entity);
      }
    }
    catch (ConstraintViolationException $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $violationList = $e->getConstraintViolationList();
      \assert(count($violationList) > 0);
      $violationList = self::getViolationSetsFromPropertyPathsAndRoot($lastEntityEvaluated, $violationList);
      $violationsResponse = self::createJsonResponseFromViolationSets($violationList);
      \assert($violationsResponse instanceof JsonResponse);
      return $violationsResponse;
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      Error::logException($this->logger, $e);
      return new JsonResponse(data: [
        'errors' => [
          [
            'detail' => $e->getMessage(),
            'source' => [
              'pointer' => 'error',
            ],
            'meta' => [
              'entity_type' => $lastEntityEvaluated->getEntityTypeId(),
              'entity_id' => $lastEntityEvaluated->id(),
              'label' => $lastEntityEvaluated->label(),
              self::AUTO_SAVE_KEY => AutoSaveManager::getAutoSaveKey($lastEntityEvaluated),
            ],
          ],
        ],
      ], status: 500);
    }

    return new JsonResponse(data: ['message' => new PluralTranslatableMarkup($published_item_count, 'Successfully published 1 item.', 'Successfully published @count items.')], status: 200);
  }

  public function delete(EntityInterface $entity): JsonResponse {
    // Discarding any member of an entity's pending-changes set discards the
    // whole set, so no stale — and possibly invalid — sibling draft is left
    // pending. A propagated edit creates an auto-save in every content
    // translation, and a config entity's base draft and its per-language
    // override drafts are separate entities; either way all members go
    // together.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::getTranslationGroupAutoSaves()
    //
    // This cascade lives on the discard endpoint, not in
    // AutoSaveManager::delete(): that low-level delete also runs during publish
    // cleanup (::post() deletes each published auto-save) and on
    // hook_entity_delete, where cascading would discard siblings mid-publish.
    // @see \Drupal\canvas\Hook\AutoSaveHooks::entityDelete()
    // @todo The discard route carries no langcode, so it always upcasts the
    //   default translation and cannot identify which one the editor acted on;
    //   irrelevant while discard is atomic, revisit for asymmetric translation
    //   in https://git.drupalcode.org/project/canvas/-/work_items/3591703
    //
    // Only discard entities whose auto-save is publishable — i.e. default-
    // translation entries. Non-default-translation auto-saves are hidden from
    // GET and must not be directly actionable here either. An entity with no
    // publishable auto-save (either it does not exist or it is a non-default
    // translation) is treated identically as not found.
    $publishable_auto_saves = $this->getPublishableAutoSaves(with_conflicts: FALSE);
    $key = AutoSaveManager::getAutoSaveKey($entity);
    if (!isset($publishable_auto_saves[$key])) {
      return new JsonResponse(data: ['error' => 'No auto-save data found for this entity.'], status: Response::HTTP_NOT_FOUND);
    }
    $group = $this->autoSaveManager->getTranslationGroupAutoSaves($entity);
    foreach ($group as $member) {
      $this->autoSaveManager->delete($member);
    }
    return new JsonResponse(data: ['message' => 'Auto-save data deleted successfully.'], status: Response::HTTP_NO_CONTENT);
  }

  /**
   * Gets URL to avatar.
   *
   * @param \Drupal\user\UserInterface $owner
   *
   * @return string|null
   */
  private function buildAvatarUrl(UserInterface $owner): ?string {
    if (!$owner->hasField('user_picture') || $owner->get('user_picture')->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $owner->get('user_picture')->entity;
    if ($file === NULL) {
      return NULL;
    }
    $uri = $file->getFileUri();
    if ($uri === NULL) {
      return NULL;
    }
    $imageStyle = $this->entityTypeManager->getStorage('image_style')->load(self::AVATAR_IMAGE_STYLE);
    if (!$imageStyle instanceof ImageStyle || !$imageStyle->supportsUri($uri)) {
      return $this->fileUrlGenerator->generateString($uri);
    }
    return $imageStyle->buildUrl($uri);
  }

  /**
   * Validates an entity and throw an exception if there are violations.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to validate.
   *
   * @throws \Drupal\canvas\Exception\ConstraintViolationException
   */
  private static function ensureEntityIsValid(EntityInterface $entity): void {
    $violations = new ConstraintViolationList();
    if ($entity instanceof ConfigEntityInterface) {
      $violations->addAll($entity->getTypedData()->validate());
    }
    elseif ($entity instanceof ContentEntityInterface) {
      $violations->addAll($entity->validate());
    }
    if (count($violations) > 0) {
      throw new ConstraintViolationException($violations);
    }
  }

  public static function getViolationSetsFromPropertyPathsAndRoot(
    FieldableEntityInterface|ConfigEntityInterface $entity,
    ConstraintViolationListInterface|EntityConstraintViolationListInterface $violations,
  ): ConstraintViolationListInterface {
    // Config entities doesn't have fields.
    if ($entity instanceof ConfigEntityInterface) {
      return $violations;
    }
    // Violations for Canvas field inputs should show against the 'model'
    // property.
    $map = \array_reduce(
      \array_keys(
        \array_filter(
          $entity->getFields(),
          static fn(FieldItemListInterface $field
          ): bool => $field->getItemDefinition()->getClass(
            ) === ComponentTreeItem::class
        )
      ),
      // We need our map to have one entry for each delta in the field item
      // list.
      static fn(array $carry, string $field_name): array => [
        ...$carry,
        ...\array_combine(
          // Key the map by the field name for each delta.
          // e.g. field_canvas_demo.0.inputs
          \array_map(static fn (int|string $delta) => \sprintf('%s.%d.inputs', $field_name, (int) $delta), \array_keys($entity->get($field_name)->getValue())),
          // And map this to 'model'.
          \array_fill(0, $entity->get($field_name)->count(), 'model'),
        ),
      ],
      []
    );
    return self::translateConstraintPropertyPathsAndRoot(
      $map,
      ($violations instanceof EntityConstraintViolationListInterface) ? EntityConstraintViolationList::fromCoreConstraintViolationList($violations) : $violations,
    );
  }

  /**
   * Checks if any entries in the list have the 'conflict_id' property.
   *
   * @param array<string, array{data: array, owner: int, updated: int, entity_type: string, entity_id: string|int, label: string, data_hash: string, client_id: ?string, langcode: ?string, entity: ?EntityInterface, conflict_id?: string,}> $auto_save_entries
   *
   * @return bool
   */
  private static function autoSaveListHasConflicts(array $auto_save_entries): bool {
    return !empty(\array_column($auto_save_entries, self::AUTO_SAVE_CONFLICT_KEY));
  }

  /**
   * Applies per-translation auto-save snapshots onto the stored entity.
   *
   * Each snapshot holds a single edited translation. They are applied onto one
   * freshly loaded copy of the stored entity so that all edited translations
   * are saved together, instead of each snapshot's save reloading and
   * clobbering the translations written by the previous one.
   *
   * For symmetric translation this is required: the shared component-tree
   * columns (e.g. component_version) are written once for every translation, so
   * each translation's translatable inputs must be applied in the same save.
   *
   * This runs at publish time in the controller rather than at reconstruction
   * time in AutoSaveManager because it calls loadUnchanged() and applies
   * field-level changes onto the stored entity — an inherently publish-specific
   * operation. Contrast with config entities, where coalescing is
   * non-destructive and therefore happens at load time in
   * ComponentTreeConfigEntityBase::getTranslation(), making the coalesced state
   * available to every caller, not only publishing.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $snapshots
   *   Auto-save snapshots for the same entity, one per edited translation.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The stored entity with every edited translation applied, ready to save.
   */
  private function applyAutoSaveTranslationSnapshots(array $snapshots): ContentEntityInterface {
    $reference = \reset($snapshots);
    \assert($reference instanceof ContentEntityInterface);
    \assert($reference->id() !== NULL);
    // Apply the auto-saved changes onto the stored entity, instead of saving
    // the entity that was reconstructed from the auto-save snapshot. The
    // snapshot only ever contains the translation that was edited, so saving it
    // directly would drop every other translation. Loading the real entity
    // preserves all translations — and keeps a valid loaded revision ID, which
    // content_translation's field synchronizer relies on to pick the correct
    // synchronization source when untranslatable ("symmetric") field columns
    // are involved.
    // @see \Drupal\content_translation\FieldTranslationSynchronizer::synchronizeFields()
    $entity = $this->entityTypeManager->getStorage($reference->getEntityTypeId())->loadUnchanged($reference->id());
    \assert($entity instanceof ContentEntityInterface);
    // The unchanged copy is used both to detect which fields changed and to
    // determine whether the entity is still considered a draft (which keys off
    // the stored, pre-edit title).
    $original_entity = clone $entity;
    $entity_definition = $entity->getEntityType();
    \assert($entity_definition instanceof ContentEntityTypeInterface);
    $is_draft = AutoSaveManager::entityIsConsideredNew($original_entity);

    foreach ($snapshots as $auto_save_entity) {
      \assert($auto_save_entity instanceof ContentEntityInterface);
      // A snapshot may have been taken at an older component version: the
      // editor drafted this translation, then the component evolved and only
      // another translation was re-previewed, leaving this one behind.
      // Reconcile it to the active version before applying it, so a translation
      // is never published with deleted props or an outdated version. The
      // snapshot holds only the edited translation, so reconciling updates that
      // translation's own tree in place.
      $this->componentSourceManager->updateComponentInstances($this->componentTreeLoader->load($auto_save_entity));
      $fields = $auto_save_entity->getFieldDefinitions();
      // The auto-save snapshot belongs to a specific translation. Apply the
      // changes onto that same translation of the stored entity, so editing
      // (and publishing) a non-default translation never clobbers the others.
      // Setting a non-translatable field via a non-default translation writes
      // to the shared (default) value, which is exactly what we want for
      // symmetric columns.
      // @see \Drupal\Core\Entity\ContentEntityBase::getTranslatedField()
      $langcode = $auto_save_entity->language()->getId();
      $target = $entity->hasTranslation($langcode) ? $entity->getTranslation($langcode) : $entity->addTranslation($langcode);
      $original_target = $original_entity->hasTranslation($langcode) ? $original_entity->getTranslation($langcode) : $original_entity;
      foreach ($fields as $field_name => $field) {
        $field_access = $auto_save_entity->get($field_name)->access(operation: 'edit', return_as_object: TRUE);
        $original_field = $original_target->get($field_name);

        // We ignore those fields that didn't change. We also need to ignore
        // field access for computed fields, because there is nothing to set,
        // and some fields that will always deny access. We are protected
        // because the entity validation will trigger errors if those were
        // changed in an unexpected way. Status and published will be TRUE when
        // publishing.
        // TRICKY: some computed fields (`path`, `moderation_state`) are
        // user-editable and persisted on save, so they must still be carried
        // over.
        // @see \Drupal\canvas\AutoSave\AutoSaveManager::isPersistedComputedField()
        $ignore_field = ($field->isComputed() && !AutoSaveManager::isPersistedComputedField($field)) || $original_field->equals($auto_save_entity->get($field_name));
        $keys = ['id', 'revision_id', 'uuid', 'langcode', 'status', 'published'];
        $revision_keys = ['revision_created', 'revision_user'];
        foreach ($keys as $key) {
          $ignore_field |= $field_name === $entity_definition->getKey($key);
        }
        foreach ($revision_keys as $revision_key) {
          $ignore_field |= $field_name === $entity_definition->getRevisionMetadataKey($revision_key);
        }
        if ($ignore_field) {
          continue;
        }
        if ($field_access->isForbidden()) {
          throw new CacheableAccessDeniedHttpException(
            (new CacheableMetadata())->addCacheableDependency($field_access),
            \sprintf('Unable to update field %s for entity "%s".', $field_name, $auto_save_entity->label()),
          );
        }
        // Apply the changed value from the auto-save snapshot onto the edited
        // translation of the stored entity.
        $target->set($field_name, $auto_save_entity->get($field_name)->getValue());
      }

      // The published status is an entity key and therefore excluded from the
      // field copy above, so carry it over explicitly onto the edited
      // translation. For draft entities automatically publish them when
      // publishing changes. For non-draft entities, preserve the published
      // status from the auto-saved entity to allow unpublishing to work
      // correctly.
      if ($target instanceof EntityPublishedInterface) {
        \assert($auto_save_entity instanceof EntityPublishedInterface);
        if ($is_draft || $auto_save_entity->isPublished()) {
          $target->setPublished();
        }
        else {
          $target->setUnpublished();
        }
      }
    }

    // If the entity is new, the auto-saved data is considered to be part of
    // the first revision. Therefore, do not create a new revision for new
    // entities.
    if ($is_draft) {
      $entity->setNewRevision(FALSE);
    }
    else {
      // Reset the revision ID.
      $entity->setNewRevision();
      $revision_id_key = $entity_definition->getKey('revision');
      \assert(\is_string($revision_id_key));
      $entity->set($revision_id_key, NULL);
    }
    $entity->isDefaultRevision(TRUE);
    // Always set the revision user to the current user. Even though we might
    // not be creating a new revision, this would only be in the case where this
    // entity should be considered new, which means it has never published
    // before in Drupal Canvas.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::entityIsConsideredNew()
    if ($revision_user = $entity_definition->getRevisionMetadataKey('revision_user')) {
      \assert(\is_string($revision_user));
      $entity->set($revision_user, $this->currentUser->id());
    }
    return $entity;
  }

}
