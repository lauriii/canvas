<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the symmetrical translation settings set on module install.
 *
 * @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
 */
#[Group('canvas')]
#[Group('canvas_translation')]
#[RunTestsInSeparateProcesses]
final class SymmetricalTranslationsModulesInstalledTest extends CanvasKernelTestBase {

  use CanvasFieldCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
  }

  /**
   * Installing content_translation after canvas must create the override.
   */
  public function testInstallingContentTranslationCreatesOverride(): void {
    self::assertNull(BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components'));

    $this->container->get(ModuleInstallerInterface::class)->install(['language', 'content_translation']);

    $override = $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage('base_field_override')
      ->load(\sprintf('%s.%s.components', Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID));
    self::assertInstanceOf(BaseFieldOverride::class, $override);
    self::assertSame([
      'inputs' => 'inputs',
      'tree' => '0',
    ], $override->getThirdPartySetting('content_translation', 'translation_sync'));
    self::assertEntityIsValid($override);
  }

  /**
   * Installing content_translation later makes existing slot fields symmetric.
   *
   * @legacy-covers \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalSlotFields
   */
  public function testInstallingContentTranslationSetsSlotFieldTranslationSync(): void {
    $this->enableModules(['field', 'node', 'text']);
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    // Slot fields on two bundles, and a component_tree field that is not a
    // slot field.
    $this->createComponentTreeField('node', 'article', 'canvas_slot_sidebar');
    $field_storage = FieldStorageConfig::loadByName('node', 'canvas_slot_sidebar');
    self::assertInstanceOf(FieldStorageConfig::class, $field_storage);
    FieldConfig::create(['field_storage' => $field_storage, 'bundle' => 'page'])->save();
    $this->createComponentTreeField('node', 'article', 'legacy_tree');

    $this->container->get(ModuleInstallerInterface::class)->install(['language', 'content_translation']);

    $symmetrical = ['inputs' => 'inputs', 'tree' => '0'];
    foreach (['article', 'page'] as $bundle) {
      $slot_field = FieldConfig::loadByName('node', $bundle, 'canvas_slot_sidebar');
      self::assertInstanceOf(FieldConfig::class, $slot_field);
      self::assertSame($symmetrical, $slot_field->getThirdPartySetting('content_translation', 'translation_sync'));
      self::assertEntityIsValid($slot_field);
    }
    // Other component_tree fields are not changed.
    self::assertNull(FieldConfig::loadByName('node', 'article', 'legacy_tree')?->getThirdPartySetting('content_translation', 'translation_sync'));

    // An explicit setting is kept.
    $slot_field = FieldConfig::loadByName('node', 'page', 'canvas_slot_sidebar');
    self::assertInstanceOf(FieldConfig::class, $slot_field);
    $explicit = ['inputs' => 'inputs', 'tree' => 'tree'];
    $slot_field->setThirdPartySetting('content_translation', 'translation_sync', $explicit)->save();
    ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalSlotFields();
    self::assertSame($explicit, FieldConfig::loadByName('node', 'page', 'canvas_slot_sidebar')?->getThirdPartySetting('content_translation', 'translation_sync'));
  }

}
