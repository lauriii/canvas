<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\Folder;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that Folder config entities get their item config dependencies updated.
 */
#[CoversFunction('canvas_post_update_0018_folder_component_dependencies')]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class FolderComponentDependenciesUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/folder_component_dependencies/add-folder-with-missing-dependencies.php';
  }

  /**
   * Tests that Folder dependencies are updated to include their items.
   */
  public function testFolderDependenciesAreUpdated(): void {
    $folder_uuid = 'b2c3d4e5-f6a7-4890-bc12-f12345678901';

    $folder_before = Folder::load($folder_uuid);
    self::assertNotNull($folder_before);
    self::assertSame(['test-folder-update-component'], $folder_before->get('items'));
    self::assertEmpty(
      $folder_before->getDependencies()['config'] ?? [],
      'Before the update, the folder has no config dependencies.',
    );

    $this->runUpdates();

    $folder_after = Folder::load($folder_uuid);
    self::assertNotNull($folder_after);
    self::assertEntityIsValid($folder_after);
    self::assertContains(
      'canvas.js_component.test-folder-update-component',
      $folder_after->getDependencies()['config'] ?? [],
      'After the update, the folder has a config dependency on the code component.',
    );
  }

}
