<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\experience_builder\Controller\ApiAutoSaveController;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\file\Entity\File;
use Drupal\image\ImageStyleInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;

trait AutoSaveManagerTestTrait {

  use UserCreationTrait;

  protected static function generateAutoSaveHash(array $data): string {
    // Use reflection access private \Drupal\experience_builder\AutoSave\AutoSaveManager::generateHash
    $autoSaveManager = new \ReflectionClass('Drupal\experience_builder\AutoSave\AutoSaveManager');
    $generateHash = $autoSaveManager->getMethod('generateHash');
    $generateHash->setAccessible(TRUE);
    $hash = $generateHash->invokeArgs(NULL, [$data]);
    self::assertIsString($hash);
    return $hash;
  }

  protected function getClientAutoSaves(array $entities, bool $addRegions = TRUE): array {
    $autoSaves = [];
    $autoSaveManager = \Drupal::service(AutoSaveManager::class);
    assert($autoSaveManager instanceof AutoSaveManager);
    if ($addRegions) {
      $entities += PageRegion::loadForActiveTheme();
    }
    foreach ($entities as $entity) {
      assert($entity instanceof EntityInterface);
      $autoSaves[AutoSaveManager::getAutoSaveKey($entity)] = $this->getClientAutoSaveData($entity);
    }
    return ['autoSaves' => $autoSaves];
  }

  /**
   * @see \Drupal\experience_builder\Controller\ApiLayoutController::getClientAutoSaveData()
   * @todo Remove this method in in https://www.drupal.org/project/experience_builder/issues/3535458
   */
  protected function getClientAutoSaveData(EntityInterface $entity): array {
    $autoSaveManager = \Drupal::service(AutoSaveManager::class);
    assert($autoSaveManager instanceof AutoSaveManager);
    $autoSaveStartRevision = $entity instanceof RevisionableInterface ?
      $entity->getRevisionId() :
      \hash('xxh64', \json_encode($entity->toArray(), JSON_THROW_ON_ERROR));
    if ($entity instanceof EntityChangedInterface) {
      $autoSaveStartRevision .= '-' . $entity->getChangedTime();
    }
    return [
      'autoSaveStartingPoint' => $autoSaveStartRevision,
      'hash' => $autoSaveManager->getAutoSaveEntity($entity)->hash,
    ];
  }

  /**
   * Adds a user with picture field and sets as current.
   *
   * @return array
   *   The user, and the picture image style url.
   */
  protected function setUserWithPictureField(array $permissions): array {
    $fileUri = 'public://image-2.jpg';
    \Drupal::service(FileSystemInterface::class)->copy(\Drupal::root() . '/core/tests/fixtures/files/image-2.jpg', PublicStream::basePath(), FileExists::Replace);
    $picture = File::create([
      'uri' => $fileUri,
      'status' => TRUE,
    ]);
    $imageStyle = \Drupal::entityTypeManager()->getStorage('image_style')->load(ApiAutoSaveController::AVATAR_IMAGE_STYLE);
    self::assertInstanceOf(ImageStyleInterface::class, $imageStyle);
    $avatarUrl = $imageStyle->buildUrl($fileUri);

    $account1 = $this->createUser($permissions, values: ['user_picture' => $picture]);
    self::assertInstanceOf(AccountInterface::class, $account1);
    $this->setCurrentUser($account1);

    return [$account1, $avatarUrl];
  }

}
