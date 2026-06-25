<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Update;

use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversFunction('canvas_post_update_0020_install_staged_language_config_override_entity_type')]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_data_model')]
#[Group('canvas_translation')]
final class StagedLanguageConfigOverrideEntityTypeInstallUpdateTest extends CanvasUpdatePathTestBase {

  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles(): void {
    $this->databaseDumpFiles[] = \dirname(__DIR__, 3) . '/fixtures/update/drupal-11.2.10-with-canvas-1.2.0.bare.php.gz';
  }

  /**
   * Tests that the entity type is absent before and installed after the update.
   */
  public function testEntityTypeIsInstalled(): void {
    $entity_definition_update_manager = \Drupal::service('entity.definition_update_manager');
    \assert($entity_definition_update_manager instanceof EntityDefinitionUpdateManagerInterface);

    $change_list = $entity_definition_update_manager->getChangeList();
    self::assertSame(
      EntityDefinitionUpdateManagerInterface::DEFINITION_CREATED,
      $change_list[StagedLanguageConfigOverride::ENTITY_TYPE_ID]['entity_type'] ?? NULL,
      'Before the update, the entity type must be pending installation.',
    );

    $this->runUpdates();

    // Re-fetch the service: the pre-update instance holds stale in-memory state.
    $entity_definition_update_manager = \Drupal::service('entity.definition_update_manager');
    \assert($entity_definition_update_manager instanceof EntityDefinitionUpdateManagerInterface);
    self::assertArrayNotHasKey(
      StagedLanguageConfigOverride::ENTITY_TYPE_ID,
      $entity_definition_update_manager->getChangeList(),
      'After the update, the entity type must no longer appear in the change list.',
    );
    self::assertSame([], StagedLanguageConfigOverride::loadMultiple(), 'Entity type storage must be queryable after installation.');
  }

}
