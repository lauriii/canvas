<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Drupal\experience_builder\Entity\EntityConstraintViolationList;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles retrieving and publishing of auto saved changes.
 */
final class ApiAutoSaveController extends ApiControllerBase {

  public const AVATAR_IMAGE_STYLE = 'xb_avatar';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly ClientDataToEntityConverter $clientDataToEntityConverter,
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  private static function validateExpectedAutoSaves(array $expected_auto_saves, array $all_auto_saves): ?JsonResponse {
    $unexpected_keys = \array_diff_key($expected_auto_saves, $all_auto_saves);
    $missing_keys = \array_diff_key($all_auto_saves, $expected_auto_saves);
    if ($unexpected_keys || $missing_keys) {
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
      foreach ($missing_keys as $key => $item) {
        $errors[] = [
          'detail' => ErrorCodesEnum::MissingItemInPublishRequest->getMessage(),
          'source' => [
            'pointer' => $key,
          ],
          'code' => ErrorCodesEnum::MissingItemInPublishRequest->value,
          'meta' => \array_intersect_key($item, \array_flip([
            'entity_type',
            'entity_id',
            'label',
          ])) + [
            'autosave_key' => $key,
          ],
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
            'autosave_key' => $key,
          ],
        ], $unmatched_keys),
      ], status: Response::HTTP_CONFLICT);
    }
    return NULL;
  }

  /**
   * Get the auto saved changes.
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
   * Publish the auto saved changes.
   */
  public function post(Request $request): JsonResponse {
    $expected_auto_saves = \json_decode($request->getContent(), TRUE);
    \assert(\is_array($expected_auto_saves));
    $all_auto_saves = $this->autoSaveManager->getAllAutoSaveList();
    if ($difference_response = self::validateExpectedAutoSaves($expected_auto_saves, $all_auto_saves)) {
      return $difference_response;
    }

    if (\count($all_auto_saves) === 0) {
      return new JsonResponse(data: ['message' => 'No items to publish.'], status: Response::HTTP_NO_CONTENT);
    }

    // We keep these in an array instead of making use of a collection like
    // ConstraintViolationList so we can keep violations grouped by each entity.
    $violationSets = [];
    $entities = [];
    foreach ($all_auto_saves as $auto_save) {
      $entity = $this->entityTypeManager->getStorage($auto_save['entity_type'])
        ->load($auto_save['entity_id']);

      try {
        if ($entity instanceof PageRegion) {
          $entity = $entity->forAutoSaveData($auto_save['data']);
          $entity->enforceIsNew(FALSE);
          $this->validatePageRegion($entity);
        }
        elseif ($entity instanceof XbHttpApiEligibleConfigEntityInterface) {
          $original_entity = clone $entity;
          $denormalized = $entity::denormalizeFromClientSide($auto_save['data']);
          foreach ($denormalized as $property_name => $property_value) {
            $entity->set($property_name, $property_value);
          }
          $violations = $entity->getTypedData()->validate();
          if ($violations->count() > 0) {
            throw new ConstraintViolationException(new EntityConstraintViolationList($original_entity, iterator_to_array($violations)));
          }
        }
        else {
          assert($entity instanceof FieldableEntityInterface);

          if ($entity instanceof EntityPublishedInterface) {
            $entity->setPublished();
          }

          // Pluck out only the content region.
          $content_region = \array_values(\array_filter($auto_save['data']['layout'], static fn(array $region) => $region['id'] === XbPageVariant::MAIN_CONTENT_REGION));
          $this->clientDataToEntityConverter->convert([
            'layout' => reset($content_region),
            'model' => $auto_save['data']['model'],
            'entity_form_fields' => $auto_save['data']['entity_form_fields'],
          ], $entity);
        }

        $entities[] = $entity;
      }
      catch (ConstraintViolationException $e) {
        $violationSets[] = $e->getConstraintViolationList();
      }
    }
    if ($validation_errors_response = self::createJsonResponseFromViolationSets(...$violationSets)) {
      return $validation_errors_response;
    }
    foreach ($entities as $entity) {
      $entity->save();
      $this->autoSaveManager->delete($entity);
    }
    return new JsonResponse(data: ['message' => new PluralTranslatableMarkup(\count($all_auto_saves), 'Successfully published 1 item.', 'Successfully published @count items.')], status: 200);
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

  private function validatePageRegion(PageRegion $entity): void {
    // @todo Use a violation list that allows keeping track of the entity
    // context.
    // @see https://www.drupal.org/project/drupal/issues/3495599
    $violations = $this->typedConfigManager->createFromNameAndData($entity->getConfigDependencyName(), $entity->toArray())->validate();
    if ($violations->count() > 0) {
      throw new ConstraintViolationException($violations);
    }
  }

}
