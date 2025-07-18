<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;

/**
 * @group experience_builder
 */
class ClientServerConversionTraitTest extends KernelTestBase {

  private const TOP_LEVEL_SLOT_COMPONENT_UUID = '8caf6e23-8fb4-4524-bdb6-f57a2a6e7858';

  private const NESTED_SLOT_COMPONENT_UUID = '8caf6e23-8fb4-4524-bdb6-f57a2a6e7859';


  use XBFieldTrait {
    getValidClientJson as traitGetValidClientJson;
    getValidConvertedInputs as traitGetValidConvertedInputs;
  }

  use ClientServerConversionTrait;
  use ContribStrictConfigSchemaTestTrait;
  use ConstraintViolationsTestTrait;

  /**
   * {@inheritdoc}
   */
  private function getValidClientJson(bool $dynamic_image = TRUE): array {
    $json = $this->traitGetValidClientJson(NULL, $dynamic_image);
    // @see \Drupal\experience_builder\ClientDataToEntityConverter::convert()
    $content_region = \array_values(\array_filter($json['layout'], static fn(array $region) => $region['id'] === 'content'))[0];
    assert(count(array_intersect(['nodeType', 'id', 'name', 'components'], array_keys($content_region))) === 4);
    assert($content_region['nodeType'] === 'region');
    assert($content_region['id'] === 'content');
    assert(is_array($content_region['components']));
    $createComponentWithSlots = fn(string $uuid, array $body_component = []) => [
      'nodeType' => 'component',
      'uuid' => $uuid,
      'type' => 'sdc.xb_test_sdc.props-slots@ab4d3ddce315cf64',
      'slots' => [
        [
          'id' => "$uuid/the_body",
          'name' => 'the_body',
          'nodeType' => 'slot',
          'components' => $body_component ? [$body_component] : [],
        ],
        [
          'id' => "$uuid/the_footer",
          'name' => 'the_footer',
          'nodeType' => 'slot',
          'components' => [],
        ],
        [
          'id' => "$uuid/the_colophon",
          'name' => 'the_colophon',
          'nodeType' => 'slot',
          'components' => [],
        ],
      ],
    ];
    // Add a component with 3 slots.
    // - 'the_body' slot has a nested component of the same type that has 3 empty slots
    // - 'the_footer' slot is empty
    // - 'the_colophon' slot is empty
    $content_region['components'][] = $createComponentWithSlots(self::TOP_LEVEL_SLOT_COMPONENT_UUID, $createComponentWithSlots(self::NESTED_SLOT_COMPONENT_UUID));
    $json['model'][self::TOP_LEVEL_SLOT_COMPONENT_UUID] = [
      'resolved' => [
        'heading' => 'Is anything really random?',
      ],
      'source' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
      ],
    ];
    $json['model'][self::NESTED_SLOT_COMPONENT_UUID] = [
      'resolved' => [
        'heading' => 'Maybe?',
      ],
      'source' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'expression' => 'ℹ︎string␟value',
        ],
      ],
    ];
    return [
      'layout' => $content_region['components'],
      'model' => $json['model'],
    ];
  }

  protected function getValidConvertedInputs(bool $dynamic_image = TRUE): array {
    $valid_inputs = $this->traitGetValidConvertedInputs($dynamic_image);
    // Add the input the for component with nested slots.
    // @see ::getValidClientJson()
    $valid_inputs[self::TOP_LEVEL_SLOT_COMPONENT_UUID]['heading'] = 'Is anything really random?';
    $valid_inputs[self::NESTED_SLOT_COMPONENT_UUID]['heading'] = 'Maybe?';
    return $valid_inputs;
  }

  public function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
    $this->setUpImages();
  }

  public function testConvertClientToServer(): void {
    ['layout' => $layout, 'model' => $model] = $this->getValidClientJson(FALSE);
    $converted_items = self::convertClientToServer($layout, $model);
    $expected_inputs = $this->getValidConvertedInputs(FALSE);
    self::assertEqualsCanonicalizing($expected_inputs, \array_combine(\array_column($converted_items, 'uuid'), \array_column($converted_items, 'inputs')));
    $this->assertSame([
      [
        'uuid' => self::TEST_HEADING_UUID,
        'component_id' => 'sdc.xb_test_sdc.heading',
        'component_version' => '9616e3c4ab9b4fce',
      ],
      [
        'uuid' => self::TEST_IMAGE_UUID,
        'component_id' => 'sdc.xb_test_sdc.image',
        'component_version' => 'c06e0be7dd131740',
      ],
      [
        'uuid' => self::TEST_BLOCK,
        'component_id' => 'block.system_branding_block',
        'component_version' => '247a23298360adb2',
      ],
      [
        'uuid' => self::TOP_LEVEL_SLOT_COMPONENT_UUID,
        'component_id' => 'sdc.xb_test_sdc.props-slots',
        'component_version' => 'ab4d3ddce315cf64',
      ],
      [
        'uuid' => self::NESTED_SLOT_COMPONENT_UUID,
        'component_id' => 'sdc.xb_test_sdc.props-slots',
        'component_version' => 'ab4d3ddce315cf64',
        'slot' => 'the_body',
        'parent_uuid' => self::TOP_LEVEL_SLOT_COMPONENT_UUID,
      ],
    ], \array_map(static fn (array $item) => \array_filter(\array_diff_key($item, \array_flip(['inputs']))), $converted_items));

    $node1 = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'field_xb_demo' => $converted_items,
    ]);
    $node1->validate();
    $node1->save();
    // Ensure the field has been updated.
    $this->assertNodeValues(
      $node1,
      [
        'sdc.xb_test_sdc.heading',
        'sdc.xb_test_sdc.image',
        'block.system_branding_block',
        'sdc.xb_test_sdc.props-slots',
      ],
      $expected_inputs,
      ['title' => '5 amazing uses for old toothbrushes']
    );

    ['layout' => $layout, 'model' => $model] = $this->getValidPatternJson();
    $converted_items = self::convertClientToServer($layout, $model);
    self::assertEqualsCanonicalizing($this->traitGetValidConvertedInputs(FALSE), \array_combine(\array_column($converted_items, 'uuid'), \array_column($converted_items, 'inputs')));
    $this->assertSame([
      [
        'uuid' => self::TEST_HEADING_UUID,
        'component_id' => 'sdc.xb_test_sdc.heading',
        'component_version' => '9616e3c4ab9b4fce',
      ],
      [
        'uuid' => self::TEST_IMAGE_UUID,
        'component_id' => 'sdc.xb_test_sdc.image',
        'component_version' => 'c06e0be7dd131740',
      ],
      [
        'uuid' => self::TEST_BLOCK,
        'component_id' => 'block.system_branding_block',
        'component_version' => '247a23298360adb2',
      ],
    ], \array_map(static fn (array $item) => \array_filter(\array_diff_key($item, \array_flip(['inputs']))), $converted_items));

    Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test Pattern',
      'component_tree' => $converted_items,
    ])->save();

  }

  public function testConvertClientToServerErrors(): void {
    $valid_client_json = $this->getValidClientJson(FALSE);

    $invalid_image_client_json = $valid_client_json;
    unset($invalid_image_client_json['model'][self::TEST_IMAGE_UUID]['source']['image']['value']);

    $this->assertConversionErrors(
      $invalid_image_client_json,
      [
        // The failed transformation above results in an empty value for the
        // entire SDC prop. Which then fails SDC validation.
        // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
        'model.' . self::TEST_IMAGE_UUID . '.image' => 'The property image is required.',
      ],
    );

    $invalid_tree_client_json = $valid_client_json;
    $invalid_tree_client_json['layout'][1]['type'] = 'sdc.experience_builder.missing_component@no_such_thing';
    $this->assertConversionErrors(
      $invalid_tree_client_json,
      ['layout.children.1.component_id' => "The 'experience_builder.component.sdc.experience_builder.missing_component' config does not exist."]
    );

    $invalid_block_settings = $valid_client_json;
    $invalid_block_settings['model'][self::TEST_BLOCK]['resolved']['use_site_slogan'] = ['this is not a boolean'];
    $this->assertConversionErrors(
      $invalid_block_settings,
      ['model.' . self::TEST_BLOCK . '.use_site_slogan' => 'This value should be of the correct primitive type.'],
    );
  }

  private function assertConversionErrors(array $client_json, array $errors): void {
    try {
      self::convertClientToServer($client_json['layout'], $client_json['model']);
      $this->fail();
    }
    catch (ConstraintViolationException $e) {
      $this->assertSame($errors, $this->violationsToArray($e->getConstraintViolationList()));
    }
  }

  protected function getValidPatternJson(): array {
    return [
      'layout' => [
        [
          'nodeType' => 'component',
          'uuid' => self::TEST_HEADING_UUID,
          'type' => 'sdc.xb_test_sdc.heading@9616e3c4ab9b4fce',
          'slots' => [],
        ],
        [
          'nodeType' => 'component',
          'uuid' => self::TEST_IMAGE_UUID,
          'type' => 'sdc.xb_test_sdc.image@c06e0be7dd131740',
          'slots' => [],
        ],
        [
          'nodeType' => 'component',
          'uuid' => self::TEST_BLOCK,
          'type' => 'block.system_branding_block@247a23298360adb2',
          'slots' => [],
        ],
      ],
      'model' => [
        self::TEST_HEADING_UUID => [
          'resolved' => [
            'text' => 'This is a random heading.',
            'style' => 'primary',
            'element' => 'h1',
          ],
          'source' => [
            'text' => [
              'sourceType' => 'static:field_item:string',
              'expression' => 'ℹ︎string␟value',
            ],
            'style' => [
              'sourceType' => 'static:field_item:list_string',
              'expression' => 'ℹ︎list_string␟value',
              'sourceTypeSettings' => [
                'storage' => [
                  'allowed_values_function' => 'experience_builder_load_allowed_values_for_component_prop',
                ],
              ],
            ],
            'element' => [
              'sourceType' => 'static:field_item:list_string',
              'expression' => 'ℹ︎list_string␟value',
              'sourceTypeSettings' => [
                'storage' => [
                  'allowed_values_function' => 'experience_builder_load_allowed_values_for_component_prop',
                ],
              ],
            ],
          ],
        ],
        self::TEST_IMAGE_UUID => [
          'resolved' => [
            'image' => [
              'src' => $this->getSrcPropertyFromFile($this->referencedImage),
              'alt' => 'This is a random image.',
              'width' => 100,
              'height' => 100,
            ],
          ],
          'source' => [
            'image' => [
              'value' => $this->mediaEntity->id(),
              'sourceType' => 'static:field_item:entity_reference',
              'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟src_with_alternate_widths,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
              'sourceTypeSettings' => [
                'storage' => ['target_type' => 'media'],
                'instance' => [
                  'handler' => 'default:media',
                  'handler_settings' => [
                    'target_bundles' => ['image' => 'image'],
                  ],
                ],
              ],
            ],
          ],
        ],
        self::TEST_BLOCK => [
          'resolved' => [
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => FALSE,
            'label' => '',
            'label_display' => FALSE,
          ],
        ],
      ],
    ];
  }

}
