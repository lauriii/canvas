<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\EntityConstraintViolationList;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles retrieval and publication of auto-saved changes.
 */
final class ApiAutoSaveController extends ApiControllerBase {

  public const AUTO_SAVE_KEY = 'api_auto_save_key';
  public const AVATAR_IMAGE_STYLE = 'xb_avatar';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly AutoSaveManager $autoSaveManager,
    #[Autowire(service: 'logger.channel.experience_builder')]
    private readonly LoggerInterface $logger,
  ) {}

  private static function validateExpectedAutoSaves(array $expected_auto_saves, array $all_auto_saves): ?JsonResponse {
    $unexpected_keys = \array_diff_key($expected_auto_saves, $all_auto_saves);
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
    $unmatched_keys = \array_values(\array_filter(\array_keys($expected_auto_saves), function ($key) use ($expected_auto_saves, $all_auto_saves) {
      return !\hash_equals($expected_auto_saves[$key]['data_hash'], $all_auto_saves[$key]['data_hash']);
    }));
    if ($unmatched_keys) {
      return new JsonResponse(data: [
        'errors' => \array_map(static fn(string $key) => [
          'detail' => ErrorCodesEnum::UnmatchedItemInPublishRequest->getMessage(),
          'source' => [
            'pointer' => $key,
          ],
          'code' => ErrorCodesEnum::UnmatchedItemInPublishRequest->value,
          'meta' => \array_intersect_key($all_auto_saves[$key], \array_flip([
            'entity_type',
            'entity_id',
            'label',
          ])) + [
            self::AUTO_SAVE_KEY => $key,
          ],
        ], $unmatched_keys),
      ], status: Response::HTTP_CONFLICT);
    }
    return NULL;
  }

  /**
   * Gets the auto-saved changes.
   */
  public function get(): CacheableJsonResponse {
    $all = $this->autoSaveManager->getAllAutoSaveList();
    $userIds = \array_column($all, 'owner');
    $cache = new CacheableMetadata();
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

    // Remove 'data' key because this will reduce the amount of data sent to the
    // client and back to the server.
    $all = \array_map(fn(array $item) => \array_diff_key($item, ['data' => '']), $all);

    $withUserDetails = \array_map(fn(array $item) => [
      // @phpstan-ignore-next-line
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
    ] + $item, $all);
    return (new CacheableJsonResponse($withUserDetails))->addCacheableDependency($cache->addCacheTags([AutoSaveManager::CACHE_TAG]));
  }

  /**
   * Publishes the auto-saved changes.
   */
  public function post(Request $request): JsonResponse {
    $client_auto_saves = \json_decode($request->getContent(), TRUE);
    \assert(\is_array($client_auto_saves));
    $all_auto_saves = $this->autoSaveManager->getAllAutoSaveList();
    if ($validation_response = self::validateExpectedAutoSaves($client_auto_saves, $all_auto_saves)) {
      return $validation_response;
    }

    if (\count($all_auto_saves) === 0) {
      return new JsonResponse(data: ['message' => 'No items to publish.'], status: Response::HTTP_NO_CONTENT);
    }

    // We keep these in an array instead of making use of a collection like
    // ConstraintViolationList, so we can keep violations grouped by each entity.
    $violationSets = [];
    $entities = [];
    // The client auto-saves do not contain the 'data' key, so we need to use
    // the versions from the auto-save manager.
    $publish_auto_saves = array_intersect_key($all_auto_saves, $client_auto_saves);

    // We want to report all access errors at one, so keeping the labels.
    $access_error_labels = [];
    $access_error_cache = new CacheableMetadata();
    $loadedEntities = [];
    foreach ($publish_auto_saves as $autoSaveKey => $auto_save) {
      $entity = $this->entityTypeManager->getStorage($auto_save['entity_type'])->create($auto_save['data']);
      assert($entity instanceof EntityInterface);
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
      throw new CacheableAccessDeniedHttpException($access_error_cache, sprintf('Unable to update entities: %s.', implode(', ', array_map(fn(\Stringable|string|NULL $label) => $label ? "'$label'" : "''", $access_error_labels))));
    }

    foreach ($loadedEntities as $entity) {
      if ($entity instanceof PageRegion) {
        $entity->enforceIsNew(FALSE);
        $violations = $entity->getTypedData()->validate();
        if ($violations->count() > 0) {
          $violationSets[] = new EntityConstraintViolationList($entity, $violations);
          continue;
        }
      }
      elseif ($entity instanceof XbHttpApiEligibleConfigEntityInterface) {
        $violations = $entity->getTypedData()->validate();
        if ($violations->count() > 0) {
          $violationSets[] = new EntityConstraintViolationList($entity, $violations);
          continue;
        }
      }
      else {
        assert($entity instanceof ContentEntityInterface);

        $use_existing_revision_id = AutoSaveManager::contentEntityIsConsideredNew($entity);

        if ($entity instanceof EntityPublishedInterface) {
          $entity->setPublished();
        }
        if ($entity instanceof RevisionableInterface) {
          // If the entity is new, the autosaved data is considered to be part
          // of the first revision. Therefore, do not create a new revision
          // for new entities.
          if ($use_existing_revision_id) {
            $entity->setNewRevision(FALSE);
          }
          else {
            // Reset the revision ID.
            $entity->setNewRevision();
            $revision_id_key = $entity->getEntityType()->getKey('revision');
            \assert(\is_string($revision_id_key));
            $entity->set($revision_id_key, NULL);
          }
        }
        $violations = $entity->validate();
        $form_violations = $this->autoSaveManager->getEntityFormViolation($entity);
        foreach ($form_violations as $form_violation) {
          // Add any form violations at this point.
          // @todo Remove this in https://drupal.org/i/3505018
          $violations->add($form_violation);
        }
        if ($violations->count() > 0) {
          $violationSets[] = EntityConstraintViolationList::fromCoreConstraintViolationList($violations);
          continue;
        }
      }
      $entity->enforceIsNew(FALSE);
      $entities[] = $entity;
    }
    if ($validation_errors_response = self::createJsonResponseFromViolationSets(...$violationSets)) {
      return $validation_errors_response;
    }

    // Either everything must be published, or nothing at all.
    try {
      $transaction = $this->database->startTransaction();
      foreach ($entities as $entity) {
        $entity->save();
        $this->autoSaveManager->delete($entity);
      }
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      Error::logException($this->logger, $e);
      throw $e;
    }

    return new JsonResponse(data: ['message' => new PluralTranslatableMarkup(\count($publish_auto_saves), 'Successfully published 1 item.', 'Successfully published @count items.')], status: 200);
  }

  public function delete(EntityInterface $entity): JsonResponse {
    if ($this->autoSaveManager->getAutoSaveEntity($entity)->isEmpty()) {
      return new JsonResponse(data: ['error' => 'No auto-save data found for this entity.'], status: Response::HTTP_NOT_FOUND);
    }
    $this->autoSaveManager->delete($entity);
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

}
