<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Controller\ClientServerConversionTrait;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldTrait;

/**
 * @group canvas
 */
class ClientServerConversionTraitTest extends KernelTestBase {

  private const TOP_LEVEL_SLOT_COMPONENT_UUID = '8caf6e23-8fb4-4524-bdb6-f57a2a6e7858';

  private const NESTED_SLOT_COMPONENT_UUID = '8caf6e23-8fb4-4524-bdb6-f57a2a6e7859';

  use CanvasFieldTrait {
    getValidClientJson as traitGetValidClientJson;
    getValidConvertedInputs as traitGetValidConvertedInputs;
  }
  use CanvasFieldCreationTrait;
  use ClientServerConversionTrait;
  use ContribStrictConfigSchemaTestTrait;
  use ConstraintViolationsTestTrait;
  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  private function getValidClientJson(bool $dynamic_image = TRUE): array {
    $json = $this->traitGetValidClientJson(NULL, $dynamic_image);
    // @see \Drupal\canvas\ClientDataToEntityConverter::convert()
    $content_region = \array_values(\array_filter($json['layout'], static fn(array $region) => $region['id'] === 'content'))[0];
    \assert(count(array_intersect(['nodeType', 'id', 'name', 'components'], array_keys($content_region))) === 4);
    \assert($content_region['nodeType'] === 'region');
    \assert($content_region['id'] === 'content');
    \assert(is_array($content_region['components']));
    $createComponentWithSlots = fn(string $uuid, array $body_component = []) => [
      'nodeType' => 'component',
      'uuid' => $uuid,
      'type' => 'sdc.canvas_test_sdc.props-slots@85a5c0c7dd53e0bb',
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
    (new CanvasTestSetup())->setup();
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
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '8c01a2bdb897a810',
      ],
      [
        'uuid' => self::TEST_IMAGE_UUID,
        'component_id' => 'sdc.canvas_test_sdc.image',
        'component_version' => 'fb40be57bd7e0973',
      ],
      [
        'uuid' => self::TEST_BLOCK,
        'component_id' => 'block.system_branding_block',
        'component_version' => '247a23298360adb2',
      ],
      [
        'uuid' => self::TOP_LEVEL_SLOT_COMPONENT_UUID,
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
      ],
      [
        'uuid' => self::NESTED_SLOT_COMPONENT_UUID,
        'component_id' => 'sdc.canvas_test_sdc.props-slots',
        'component_version' => '85a5c0c7dd53e0bb',
        'slot' => 'the_body',
        'parent_uuid' => self::TOP_LEVEL_SLOT_COMPONENT_UUID,
      ],
    ], \array_map(static fn (array $item) => \array_filter(\array_diff_key($item, \array_flip(['inputs']))), $converted_items));

    $node1 = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'field_canvas_demo' => $converted_items,
    ]);
    $node1->validate();
    $node1->save();
    // Ensure the field has been updated.
    $this->assertNodeValues(
      $node1,
      [
        'sdc.canvas_test_sdc.heading',
        'sdc.canvas_test_sdc.image',
        'block.system_branding_block',
        'sdc.canvas_test_sdc.props-slots',
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
        'component_id' => 'sdc.canvas_test_sdc.heading',
        'component_version' => '8c01a2bdb897a810',
      ],
      [
        'uuid' => self::TEST_IMAGE_UUID,
        'component_id' => 'sdc.canvas_test_sdc.image',
        'component_version' => 'fb40be57bd7e0973',
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
    $invalid_tree_client_json['layout'][1]['type'] = 'sdc.canvas.missing_component@no_such_thing';
    $this->assertConversionErrors(
      $invalid_tree_client_json,
      ['layout.children.1.component_id' => "The 'canvas.component.sdc.canvas.missing_component' config does not exist."]
    );
  }

  /**
   * Test conversion with broken components.
   *
   * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::isBroken()
   */
  public function testConvertClientToServerWithBrokenComponents(): void {
    $this->container->get(ModuleInstallerInterface::class)->install([
      'canvas_test_block',
      'canvas_test_code_components',
    ]);
    $layout = [
      [
        'nodeType' => 'component',
        'uuid' => '8b4f9376-879f-4a6c-9b10-a12991736995',
        'type' => 'js.canvas_test_code_components_with_no_props@8fe3be948e0194e1',
        'slots' => [],
      ],
      [
        'nodeType' => 'component',
        'uuid' => '587334b5-8a05-48f5-9f48-02b5a84e7267',
        'type' => 'block.canvas_test_block_input_none@f91f8d4aff4aba7c',
        'slots' => [],
      ],
    ];
    $model = [
      '8b4f9376-879f-4a6c-9b10-a12991736995' => [
        'resolved' => [],
        'source' => [],
      ],
      '587334b5-8a05-48f5-9f48-02b5a84e7267' => [
        'resolved' => [],
        'source' => [],
      ],
    ];
    // @phpstan-ignore-next-line
    $converted_items = self::convertClientToServer($layout, $model);
    self::assertEqualsCanonicalizing([
      '8b4f9376-879f-4a6c-9b10-a12991736995' => [],
      '587334b5-8a05-48f5-9f48-02b5a84e7267' => [
        'label_display' => '0',
        'label' => 'Test block with no settings.',
      ],
    ], \array_combine(\array_column($converted_items, 'uuid'), \array_column($converted_items, 'inputs')));

    // Now render both of these broken and assert we can still convert.
    $this->container->get(StateInterface::class)->set('canvas_test_block.remove_definitions', ['canvas_test_block_input_none']);
    $this->container->get(BlockManagerInterface::class)->clearCachedDefinitions();
    // Delete the code component through the config factory to avoid normal
    // dependency cleanup that would also remove the Component entity.
    $this->container->get(ConfigFactoryInterface::class)
      ->getEditable('canvas.js_component.canvas_test_code_components_with_no_props')
      ->delete();
    $this->container->get(EntityTypeManagerInterface::class)
      ->getStorage(JavaScriptComponent::ENTITY_TYPE_ID)
      ->resetCache();
    $js_component = Component::load('js.canvas_test_code_components_with_no_props');
    self::assertTrue($js_component?->getComponentSource()->isBroken());
    $block_component = Component::load('block.canvas_test_block_input_none');
    self::assertTrue($block_component?->getComponentSource()->isBroken());

    // Now simulate the front-end POSTing a component model/layout when both
    // components are now broken on the backend.
    // @phpstan-ignore-next-line
    $converted_items = self::convertClientToServer($layout, $model);
    self::assertEqualsCanonicalizing([
      '8b4f9376-879f-4a6c-9b10-a12991736995' => [],
      '587334b5-8a05-48f5-9f48-02b5a84e7267' => [],
    ], \array_combine(\array_column($converted_items, 'uuid'), \array_column($converted_items, 'inputs')));
    $node = Node::load(1);
    \assert($node instanceof NodeInterface);
    $item_list = $this->container->get(ComponentTreeLoader::class)->load($node);
    \assert($item_list instanceof ComponentTreeItemList);
    $item_list->setValue($converted_items);
    self::assertCount(0, $item_list->validate());
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
          'type' => 'sdc.canvas_test_sdc.heading@8c01a2bdb897a810',
          'slots' => [],
        ],
        [
          'nodeType' => 'component',
          'uuid' => self::TEST_IMAGE_UUID,
          'type' => 'sdc.canvas_test_sdc.image@fb40be57bd7e0973',
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
                  'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
                ],
              ],
            ],
            'element' => [
              'sourceType' => 'static:field_item:list_string',
              'expression' => 'ℹ︎list_string␟value',
              'sourceTypeSettings' => [
                'storage' => [
                  'allowed_values_function' => 'canvas_load_allowed_values_for_component_prop',
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
              'expression' => 'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
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
            'block_branding' => [
              'use_site_logo' => TRUE,
              'use_site_name' => TRUE,
              'use_site_slogan' => FALSE,
            ],
            'label' => '',
            'label_display' => FALSE,
          ],
        ],
      ],
    ];
  }

}
