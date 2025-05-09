<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\DataType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\DataType\ComponentInputs;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;

/**
 * @covers \Drupal\experience_builder\Plugin\DataType\ComponentInputs::calculateDependencies()
 * @see \Drupal\Tests\experience_builder\Unit\DataType\ComponentInputsTest
 * @group experience_builder
 */
class ComponentInputsDependenciesTest extends KernelTestBase {

  use ContribStrictConfigSchemaTestTrait;
  use ImageFieldCreationTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'filter',
    'text',
    'file',
    'image',
    'media',
    'user',
    'system',
    'path',
    'experience_builder',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('filter');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node_type');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installSchema('file', ['file_usage']);
  }

  public function testCalculateDependencies(): void {
    $type = NodeType::create([
      'type' => 'alpha',
      'name' => 'Alpha',
    ]);
    $type->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'type' => 'text_with_summary',
      'entity_type' => 'node',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'alpha',
      'label' => 'Body',
    ])->save();
    $this->createImageField('field_hero', 'node', 'alpha', storage_settings: [
      // @todo Remove once https://drupal.org/i/3513317 is fixed.
      // We cannot rely on the override because experience_builder module is not
      // yet installed so need to manually specify it here for testing sake.
      // @see \Drupal\experience_builder\Plugin\Field\FieldTypeOverride\ImageItemOverride::defaultStorageSettings
      'display_default' => TRUE,
    ]);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);

    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'alpha');
    $image_field_sample_value = ImageItem::generateSampleValue($field_definitions['field_hero']);
    \assert(\is_array($image_field_sample_value) && \array_key_exists('target_id', $image_field_sample_value));
    $hero_reference = Media::create([
      'bundle' => 'image',
      'name' => 'Hero image',
      'field_media_image' => $image_field_sample_value,
    ]);
    $hero_reference->save();

    $node = Node::create([
      'type' => 'alpha',
      'title' => 'Test title',
      'body' => [['value' => 'My test node body', 'summary' => 'Body Summary', 'format' => 'plain_text']],
      'field_hero' => $image_field_sample_value,
    ]);
    $node->save();

    $existing_component_uuid = 'test-component-uuid';

    // Create test data.
    $test_inputs = [
      $existing_component_uuid => [
        'title' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'Test Title',
          'expression' => 'ℹ︎string␟value',
        ],
        'body' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:alpha␝body␞␟value',
        ],
        'dynamic-image-udf7d' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:node:alpha␝field_hero␞␟{src↝entity␜␜entity:file␝uri␞␟value,alt↠alt,width↠width,height↠height}',
        ],
      ],
    ];
    $component_inputs = new ComponentInputs(
      DataDefinition::create('component_inputs'),
      NULL,
    );
    $component_inputs->setValue(Json::encode($test_inputs));

    $expected_dependencies = [
      'plugin' => [
        'field_type:string',
        'entity_type:node',
        'field_type:text_with_summary',
        'entity_type:node',
        'field_type:image',
        'entity_type:node',
        'field_type:image',
        'entity_type:node',
        'field_type:image',
        'entity_type:node',
        'field_type:image',
      ],
      'module' => [
        'node',
        'node',
        'node',
        'node',
        'node',
      ],
      'config' => [
        'node.type.alpha',
        'field.field.node.alpha.body',
        'node.type.alpha',
        'field.field.node.alpha.field_hero',
        'node.type.alpha',
        'field.field.node.alpha.field_hero',
        'node.type.alpha',
        'field.field.node.alpha.field_hero',
        'node.type.alpha',
        'field.field.node.alpha.field_hero',
      ],
    ];

    $deps = $component_inputs->calculateDependencies(NULL);
    $this->assertSame($deps, $expected_dependencies);

    // Verify content dependencies if we have a valid entity.
    $file_entity = $hero_reference->get('field_media_image')->entity;
    assert($file_entity instanceof File);
    $file_uuid = $file_entity->get('uuid')->value;
    $deps = $component_inputs->calculateDependencies($node);
    $this->assertSame($deps, array_merge($expected_dependencies, [
      'content' => [
        'file:file:' . $file_uuid,
      ],
    ]));
  }

}
