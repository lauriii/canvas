<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Validation\ConstraintPropertyPathTranslatorTrait;
use Drupal\canvas\Workspace\CanvasWorkspacePublisher;
use Drupal\canvas\Workspace\WorkspacePublishValidationException;
use Drupal\canvas\Workspace\WorkspaceReview;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
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
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Handles retrieval and publication of auto-saved changes.
 *
 * @phpstan-import-type AutoSaveEntry from AutoSaveManager
 */
final class ApiAutoSaveController extends ApiControllerBase {

  use ConstraintPropertyPathTranslatorTrait;

  public const AUTO_SAVE_KEY = 'api_auto_save_key';
  public const AVATAR_IMAGE_STYLE = 'canvas_avatar';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly AutoSaveManager $autoSaveManager,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
    private readonly AccountInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly WorkspaceAutoSave $workspaceAutoSave,
    private readonly WorkspaceReview $workspaceReview,
    private readonly CanvasWorkspacePublisher $canvasWorkspacePublisher,
  ) {}

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
      return $access->isAllowed() && $item['is_default_translation'];
    });
  }

  /**
   * Gets the auto-saved changes.
   */
  public function get(): CacheableJsonResponse {
    $cache = new CacheableMetadata();
    // @todo Remove the use of 'canvas_dev_cd' flag in https://git.drupalcode.org/project/canvas/-/work_items/3591732
    $conflict_detection_dev_mode = $this->moduleHandler->moduleExists('canvas_dev_cd');

    $filtered = $this->getPublishableAutoSaves(with_conflicts: $conflict_detection_dev_mode, cache: $cache);

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
        if (isset($entry[AutoSaveManager::AUTO_SAVE_CONFLICT_KEY])) {
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
              AutoSaveManager::AUTO_SAVE_CONFLICT_KEY => $entry[AutoSaveManager::AUTO_SAVE_CONFLICT_KEY],
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
   * Publishes the active workspace.
   *
   * BREAKING (Phase 2): the workspace, not the item, is the unit of publish.
   * The request body carries no item selection; every item tracked in the
   * active workspace is validated and access checked, and the whole
   * workspace goes live atomically via core workspace publish — or nothing
   * does.
   *
   * @see \Drupal\canvas\Workspace\CanvasWorkspacePublisher
   */
  public function post(Request $request): JsonResponse {
    $workspace_id = $this->workspaceAutoSave->getStagingWorkspaceId();
    /** @var \Drupal\workspaces\WorkspaceInterface|null $workspace */
    $workspace = $this->entityTypeManager->getStorage('workspace')->load($workspace_id);
    if ($workspace === NULL) {
      return new JsonResponse(data: ['errors' => [['detail' => \sprintf('The workspace "%s" no longer exists.', $workspace_id), 'source' => ['pointer' => 'workspace']]]], status: Response::HTTP_CONFLICT);
    }

    $publish_access = $workspace->access('publish', $this->currentUser, return_as_object: TRUE);
    if (!$publish_access->isAllowed()) {
      throw new CacheableAccessDeniedHttpException(
        CacheableMetadata::createFromObject($publish_access),
        \sprintf('You do not have permission to publish the "%s" workspace.', (string) $workspace->label()),
      );
    }

    // Fail fast on the review gate with an actionable message; the
    // pre-publish subscriber enforces it authoritatively inside the publish.
    if ($this->workspaceReview->isPublishBlocked($workspace)) {
      return new JsonResponse(data: [
        'errors' => [
          [
            'detail' => \sprintf('The "%s" workspace requires review: it must be approved before it can be published. Its current review state is "%s".', (string) $workspace->label(), $this->workspaceReview->getStatus($workspace)),
            'source' => ['pointer' => 'workspace'],
            'code' => ErrorCodesEnum::WorkspaceNotApproved->value,
          ],
        ],
      ], status: Response::HTTP_CONFLICT);
    }

    try {
      $published_count = $this->canvasWorkspacePublisher->publish($workspace_id, $this->currentUser);
    }
    catch (WorkspacePublishValidationException $e) {
      $violations_response = self::createJsonResponseFromViolationSets(...$e->getViolationSets());
      \assert($violations_response instanceof JsonResponse);
      return $violations_response;
    }
    catch (\Exception $e) {
      Error::logException($this->logger, $e);
      return new JsonResponse(data: [
        'errors' => [
          [
            'detail' => $e->getMessage(),
            'source' => ['pointer' => 'error'],
          ],
        ],
      ], status: 500);
    }

    if ($published_count === 0) {
      return new JsonResponse(data: ['message' => 'No items to publish.'], status: Response::HTTP_NO_CONTENT);
    }
    return new JsonResponse(data: ['message' => new PluralTranslatableMarkup($published_count, 'Successfully published 1 item.', 'Successfully published @count items.')], status: 200);
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
    return !empty(\array_column($auto_save_entries, AutoSaveManager::AUTO_SAVE_CONFLICT_KEY));
  }

}
