<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

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

}
