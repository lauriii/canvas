<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\UserInterface;

/**
 * Defines a controller to get the pending entity changes.
 */
final class ApiPendingChangesController {

  public const AVATAR_IMAGE_STYLE = 'xb_avatar';

  public function __construct(
    protected readonly AutoSaveManager $autoSaveManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
  }

  public function __invoke(): CacheableJsonResponse {
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
