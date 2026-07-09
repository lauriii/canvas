<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests the config schema forbidding asymmetrical Canvas Page translations.
 *
 * The `components` base field of the Canvas Page content entity type must use
 * symmetrical translations: the input values of component instances may be
 * translated (`inputs`), the component tree itself (`tree`) may not.
 *
 * The `translation_sync` keys are optional: content_translation omits them
 * when translation is disabled for the field or bundle. Only when present
 * must they match the symmetrical combination.
 *
 * On a real site, config schema constraints only run when something
 * explicitly validates the config (tests, config inspector, …) — they are a
 * guardrail, not runtime enforcement. Runtime enforcement happens in the
 * content language settings form (which forces the symmetrical combination),
 * in the update path (which repairs pre-existing config) and at config import
 * time (which rejects staged config violating these constraints); module
 * install creates the override with the correct defaults.
 *
 * @see config/schema/canvas_symmetrical_translations_only.schema.yml
 * @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
 * @see \Drupal\canvas\Hook\ModuleHooks::validateCanvasPageLanguageSettings()
 * @see canvas_post_update_0022_enforce_symmetrical_canvas_page_components_translation()
 * @see \Drupal\canvas\EventSubscriber\SymmetricalTranslationsConfigImportValidator
 */
#[Group('canvas')]
#[Group('canvas_translation')]
#[RunTestsInSeparateProcesses]
final class CanvasPageComponentsTranslationSyncConfigSchemaTest extends CanvasKernelTestBase {

  use ConstraintViolationsTestTrait;

  private const string CONFIG_NAME = 'core.base_field_override.canvas_page.canvas_page.components';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...CanvasKernelTestBase::CANVAS_KERNEL_TEST_MINIMAL_MODULES,
    'language',
    'content_translation',
  ];

  #[TestWith([NULL, []], 'absent — translation disabled for the field or bundle')]
  #[TestWith([['inputs' => 'inputs', 'tree' => '0'], []], 'symmetrical')]
  #[TestWith([
    ['inputs' => 'inputs', 'tree' => 'tree'],
    ['third_party_settings.content_translation.translation_sync.tree' => 'The value you selected is not a valid choice.'],
  ], 'asymmetrical — translatable tree')]
  #[TestWith([
    ['inputs' => '0', 'tree' => '0'],
    ['third_party_settings.content_translation.translation_sync.inputs' => 'The value you selected is not a valid choice.'],
  ], 'non-translatable inputs')]
  public function testTranslationSync(?array $translation_sync, array $expected_violations): void {
    // Kernel tests enable modules without invoking install hooks, so create
    // the override the same way module install does.
    // @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
    ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalCanvasPageComponents();
    $override = BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components');
    self::assertNotNull($override);

    if ($translation_sync === NULL) {
      // This is what content_translation's "Content language" admin form does
      // when the field or the bundle is not translatable.
      // @see _content_translation_update_field_translatability()
      $override->unsetThirdPartySetting('content_translation', 'translation_sync');
    }
    else {
      $override->setThirdPartySetting('content_translation', 'translation_sync', $translation_sync);
    }

    self::assertSame($expected_violations, self::violationsToArray($override->getTypedData()->validate()));
  }

  /**
   * Importing config marking the component tree translatable must fail.
   *
   * @see \Drupal\canvas\EventSubscriber\SymmetricalTranslationsConfigImportValidator
   */
  public function testConfigImportRejectsAsymmetricalTranslationSync(): void {
    $sync_storage = $this->prepareSyncStorageWithTranslationSync(['inputs' => 'inputs', 'tree' => 'tree']);

    $config_importer = $this->configImporter();
    try {
      $config_importer->import();
      $this->fail('The config import must be rejected.');
    }
    catch (ConfigImporterException) {
      $errors = \array_map(strval(...), $config_importer->getErrors());
      self::assertCount(1, $errors);
      self::assertStringContainsString('third_party_settings.content_translation.translation_sync.tree', $errors[0]);
      self::assertStringContainsString('Canvas Pages support only symmetrical translations', $errors[0]);
    }

    // The same import with the symmetrical combination succeeds.
    $data = $sync_storage->read(self::CONFIG_NAME);
    self::assertIsArray($data);
    $data['third_party_settings']['content_translation']['translation_sync']['tree'] = '0';
    $sync_storage->write(self::CONFIG_NAME, $data);
    $this->configImporter()->import();
    $override = BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components');
    self::assertNotNull($override);
    self::assertSame(
      ['inputs' => 'inputs', 'tree' => '0'],
      $override->getThirdPartySetting('content_translation', 'translation_sync'),
    );
  }

  /**
   * Importing config without any `translation_sync` keys must succeed.
   *
   * This is what content_translation persists when translation is disabled
   * for the field or bundle.
   */
  public function testConfigImportAllowsAbsentTranslationSync(): void {
    $this->prepareSyncStorageWithTranslationSync(NULL);

    $this->configImporter()->import();

    $override = BaseFieldOverride::loadByName(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID, 'components');
    self::assertNotNull($override);
    self::assertNull($override->getThirdPartySetting('content_translation', 'translation_sync'));
  }

  /**
   * Copies the active config to sync storage, with a modified override.
   *
   * @param array<string, string>|null $translation_sync
   *   The `translation_sync` setting to stage, or NULL to stage the override
   *   without it.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The sync storage.
   */
  private function prepareSyncStorageWithTranslationSync(?array $translation_sync): StorageInterface {
    // Create the override in the active storage first, the same way module
    // install does.
    // @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
    ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalCanvasPageComponents();
    $sync_storage = $this->container->get('config.storage.sync');
    $this->copyConfig($this->container->get('config.storage'), $sync_storage);

    $data = $sync_storage->read(self::CONFIG_NAME);
    self::assertIsArray($data);
    if ($translation_sync === NULL) {
      unset($data['third_party_settings']);
    }
    else {
      $data['third_party_settings']['content_translation']['translation_sync'] = $translation_sync;
    }
    $sync_storage->write(self::CONFIG_NAME, $data);
    return $sync_storage;
  }

}
