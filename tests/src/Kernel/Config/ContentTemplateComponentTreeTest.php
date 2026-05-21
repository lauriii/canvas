<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\PageRegion;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the component tree aspects of the ContentTemplate config entity type.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(PageRegion::class)]
#[Group('canvas')]
#[Group('canvas_config_management')]
final class ContentTemplateComponentTreeTest extends ConfigWithComponentTreeTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installConfig('node');
    $this->createContentType(['type' => 'alpha']);
    FieldStorageConfig::create([
      'field_name' => 'field_test',
      'type' => 'text',
      'entity_type' => 'node',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_test',
      'entity_type' => 'node',
      'bundle' => 'alpha',
      'label' => 'Test field',
    ])->save();
    $this->generateComponentConfig();
    $this->entity = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => 'alpha',
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
    ]);
  }

}
