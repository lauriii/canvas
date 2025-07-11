<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\AutoSave;

use Drupal\experience_builder\Entity\AssetLibrary;

/**
 * Tests auto-save conflict handling for asset libraries.
 *
 * @see \Drupal\experience_builder\Entity\AssetLibrary
 */
final class AutoSaveConflictAssetLibraryTest extends AutoSaveConflictConfigTestBase {

  protected string $updateKey = 'label';

  protected function setUpEntity(): void {
    $globalAssetLibrary = AssetLibrary::load('global');
    \assert($globalAssetLibrary instanceof AssetLibrary);
    $this->entity = $globalAssetLibrary;
  }

}
