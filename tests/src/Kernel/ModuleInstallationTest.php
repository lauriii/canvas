<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests module installation.
 *
 * Note this cannot use CanvasKernelTestBase because it needs to test
 * installation and uninstallation of the module, which is not possible when the
 * module is already installed for the test class.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class ModuleInstallationTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'entity_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('entity_test');
  }

  public function testModuleInstallation(): void {
    self::assertFalse($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas'));
    self::assertFalse($this->container->get(ThemeHandlerInterface::class)->themeExists('canvas_stark'));

    $this->container->get(ModuleInstallerInterface::class)->install(['canvas']);
    self::assertTrue($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas'));
    $this->assertTCanvasStarkThemeExists();

    $test_entity = EntityTest::create([
      'name' => 'Test entity',
    ]);
    $test_entity->save();

    /** @var \Drupal\canvas\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);
    // Update a value to allow auto-save to be stored.
    $test_entity->set('name', 'I can haz auto save');
    $autoSave->saveEntity($test_entity);
    self::assertCount(1, $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE));

    // Core's content uninstall validator prevents uninstalling a module that
    // provides a content entity type while content of that type exists.
    // Auto-save snapshots are content entities, so all auto-save data must be
    // deleted before Canvas can be uninstalled.
    // @see \Drupal\Core\Entity\ContentUninstallValidator
    $autoSave->deleteAll();

    $this->container->get(ModuleInstallerInterface::class)->uninstall(['canvas']);
    self::assertFalse($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas'));
    $this->assertTCanvasStarkThemeExists();
    self::assertCount(0, $autoSave->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE), 'Auto-save items are removed after uninstallation.');

    // Installing the module after uninstallation does not lead to errors.
    $this->container->get(ModuleInstallerInterface::class)->install(['canvas']);
    self::assertTrue($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas'));
    $this->assertTCanvasStarkThemeExists();
  }

  private function assertTCanvasStarkThemeExists(): void {
    $this->container->get(ThemeHandlerInterface::class)->reset();
    self::assertTrue($this->container->get(ThemeHandlerInterface::class)->themeExists('canvas_stark'));
  }

}
