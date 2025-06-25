<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\Core\Entity\EntityInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\experience_builder\Controller\ApiAutoSaveController;
use Drupal\file\Entity\File;
use Drupal\image\ImageStyleInterface;

trait AutoSaveManagerTestTrait {

  protected static function generateAutoSaveHash(array $data): string {
    // Use reflection access private \Drupal\experience_builder\AutoSave\AutoSaveManager::generateHash
    $autoSaveManager = new \ReflectionClass('Drupal\experience_builder\AutoSave\AutoSaveManager');
    $generateHash = $autoSaveManager->getMethod('generateHash');
    $generateHash->setAccessible(TRUE);
    $hash = $generateHash->invokeArgs(NULL, [$data]);
    self::assertIsString($hash);
    return $hash;
  }

  protected function addClientAutoSaves(array &$clientData, array $entities): void {
    $clientData['autoSaves'] ??= [];
    $autoSaveManager = $this->container->get(AutoSaveManager::class);
    assert($autoSaveManager instanceof AutoSaveManager);
    foreach ($entities as $entity) {
      assert($entity instanceof EntityInterface);
      $autoSaveData = $autoSaveManager->getAutoSaveEntity($entity);
      if ($autoSaveData->hash) {
        $clientData['autoSaves'][AutoSaveManager::getAutoSaveKey($entity)] = $autoSaveData->hash;
      }
    }
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
