<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests module installation.
 *
 * @group experience_builder
 */
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
    self::assertFalse($this->container->get('module_handler')->moduleExists('experience_builder'));
    self::assertFalse($this->container->get('theme_handler')->themeExists('xb_stark'));

    $this->container->get('module_installer')->install(['experience_builder']);
    self::assertTrue($this->container->get('module_handler')->moduleExists('experience_builder'));
    $this->assertTXbStarkThemeExists();

    $test_entity = EntityTest::create([
      'name' => 'Test entity',
    ]);
    $test_entity->save();

    /** @var \Drupal\experience_builder\AutoSave\AutoSaveManager $autoSave */
    $autoSave = \Drupal::service(AutoSaveManager::class);
    // Update a value to allow auto-save to be stored.
    $test_entity->set('name', 'I can haz auto save');
    $autoSave->saveEntity($test_entity);
    self::assertCount(1, $autoSave->getAllAutoSaveList());

    $this->container->get('module_installer')->uninstall(['experience_builder']);
    self::assertFalse($this->container->get('module_handler')->moduleExists('experience_builder'));
    $this->assertTXbStarkThemeExists();
    self::assertCount(0, $autoSave->getAllAutoSaveList(), 'Auto-save items are removed after uninstallation.');

    // Installing the module after uninstallation does not lead to errors.
    $this->container->get('module_installer')->install(['experience_builder']);
    self::assertTrue($this->container->get('module_handler')->moduleExists('experience_builder'));
    $this->assertTXbStarkThemeExists();
  }

  private function assertTXbStarkThemeExists(): void {
    $this->container->get('theme_handler')->reset();
    self::assertTrue($this->container->get('theme_handler')->themeExists('xb_stark'));
  }

}
