<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Translation;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\field\Entity\FieldConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\DataProviderWithComponentTreeTrait;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;

/**
 * Base class for symmetrical translation kernel tests.
 *
 * Provides shared module list, setUp(), and field configuration helpers for
 * tests that exercise content-defined component tree symmetrical translation
 * behavior.
 */
abstract class ContentComponentTreeSymmetricalTranslationTestBase extends CanvasKernelTestBase {

  protected const string CTA_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

  use CanvasFieldCreationTrait;
  use ContentTranslationTestTrait;
  use DataProviderWithComponentTreeTrait;
  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_sdc',
    'content_translation',
    'field',
    'language',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'language', 'node']);

    $this->generateComponentConfig();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  protected function createEntityWithDefaultTranslation(
    string $entity_type_id,
    string $bundle,
    string $field_name,
    mixed $entity_storage,
    array $initial_tree = [
    [
      'uuid' => self::CTA_UUID,
      'component_id' => 'sdc.canvas_test_sdc.my-cta',
      'component_version' => '::ACTIVE_VERSION_IN_SUT::',
      'inputs' => [
        'text' => 'Click here',
        'href' => 'https://drupal.org',
        'target' => '_self',
      ],
    ],
    ],
  ): ContentEntityInterface {
    $entity = $entity_storage->create(match ($entity_type_id) {
      'node' => [
        'type' => $bundle,
        'title' => 'Test entity',
        $field_name => self::populateActiveComponentVersionPlaceholders($initial_tree),
      ],
      Page::ENTITY_TYPE_ID => [
        'title' => 'Test entity',
        $field_name => self::populateActiveComponentVersionPlaceholders($initial_tree),
      ],
      default => throw new \OutOfRangeException(),
    });
    \assert($entity instanceof ContentEntityInterface);
    return $entity;
  }

  /**
   * Sets up translation sync for the given entity type + field.
   *
   * For configurable fields (node): uses FieldConfig third-party settings.
   * For base fields (canvas_page): uses BaseFieldOverride third-party settings.
   * BaseFieldOverride implements ThirdPartySettingsInterface, which is what
   * FieldTranslationSynchronizer::getFieldSynchronizationSettings() checks.
   */
  protected function setUpSymmetricalContentTranslation(string $entity_type_id, string $bundle, string $field_name): void {
    if ($entity_type_id === 'node') {
      $this->createComponentTreeField('node', 'article', $field_name);
      $this->enableContentTranslation('node', 'article');
      $field_config = FieldConfig::loadByName('node', 'article', $field_name);
      \assert($field_config instanceof FieldConfig);
      $field_config->setTranslatable(TRUE);
      $field_config->setThirdPartySetting('content_translation', 'translation_sync', [
        'inputs' => 'inputs',
        'tree' => 0,
      ]);
      self::assertEntityIsValid($field_config);
      $field_config->save();
    }
    else {
      // canvas_page uses the 'components' base field (already translatable).
      // Create a BaseFieldOverride to store the translation_sync setting.
      // BaseFieldOverride implements ThirdPartySettingsInterface, which is what
      // FieldTranslationSynchronizer::getFieldSynchronizationSettings() checks.
      $this->enableContentTranslation($entity_type_id, $bundle);
      $override = BaseFieldOverride::createFromBaseFieldDefinition(
        // @todo Remove this ignore once core's getBaseFieldDefinitions() return type is fixed.
        // @phpstan-ignore-next-line argument.type
        $this->container->get('entity_field.manager')
          ->getBaseFieldDefinitions($entity_type_id)[$field_name],
        $bundle,
      );
      $override->setThirdPartySetting('content_translation', 'translation_sync', [
        'inputs' => 'inputs',
        'tree' => 0,
      ]);
      self::assertEntityIsValid($override);
      $override->save();
    }
  }

}
