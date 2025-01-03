<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;
use Drupal\Tests\experience_builder\Traits\ConstraintViolationsTestTrait;
use Drupal\Tests\experience_builder\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\experience_builder\Traits\XBFieldTrait;

class ClientServerConversionTraitTest extends KernelTestBase {

  use XBFieldTrait {
    getValidClientJson as traitGetValidClientJson;
  }

  use ClientServerConversionTrait;
  use TestDataUtilitiesTrait;
  use ContribStrictConfigSchemaTestTrait;
  use ConstraintViolationsTestTrait;

  /**
   * {@inheritdoc}
   */
  private function getValidClientJson(): array {
    $json = $this->traitGetValidClientJson();
    $content_region = \array_values(\array_filter($json['layout'], static fn(array $region) => $region['id'] === 'content'));
    return [
      'layout' => reset($content_region),
      'model' => $json['model'],
    ];
  }

  public function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
    $this->setUpImages();
  }

  public function testConvertClientToServer(): void {
    ['layout' => $layout, 'model' => $model] = $this->getValidClientJson();
    [$tree, $props, $violations] = $this->convertClientToServer($layout, $model);
    $this->assertCount(0, $violations);
    $this->assertSame($this->getValidConvertedProps(), $props);
    $this->assertSame([
      ComponentTreeStructure::ROOT_UUID => [
        [
          'uuid' => self::TEST_HEADING_UUID,
          'component' => 'sdc.experience_builder.heading',
        ],
        [
          'uuid' => self::TEST_IMAGE_UUID,
          'component' => 'sdc.experience_builder.image',
        ],
      ],
    ], $tree);

    $converted_item = [
      'tree' => self::encodeXBData($tree),
      'props' => self::encodeXBData($props),
    ];

    // Ensure convert 'tree' and 'props' can be used both to create both a
    // config entity and a content entity field value.
    Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test Pattern',
      'component_tree' => $converted_item,
    ])->save();

    $node1 = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
      'field_xb_demo' => $converted_item,
    ]);
    $node1->validate();
    $node1->save();
    // Ensure the field has been updated.
    $this->assertNodeValues(
      $node1,
      [
        'sdc.experience_builder.heading',
        'sdc.experience_builder.image',
      ],
      $this->getValidConvertedProps(),
      '5 amazing uses for old toothbrushes'
    );
  }

  public function testConvertClientToServerErrors(): void {
    $valid_client_json = $this->getValidClientJson();

    $invalid_image_client_json = $valid_client_json;
    $invalid_image_client_json['model'][self::TEST_IMAGE_UUID]['image']['src'] = '/not/a/real/url';
    $this->assertConversionErrors(
      $invalid_image_client_json,
      ['model.' . self::TEST_IMAGE_UUID . '.image.src' => "File '/not/a/real/url' not found."],
    );

    $unreferenced_file_client_json = $valid_client_json;
    $unreferenced_src = $this->getSrcPropertyFromFile($this->unreferencedImage);
    $unreferenced_file_client_json['model'][self::TEST_IMAGE_UUID]['image']['src'] = $unreferenced_src;
    $this->assertConversionErrors(
      $unreferenced_file_client_json,
      ['model.' . self::TEST_IMAGE_UUID . '.image.src' => "No media entity found that uses file '$unreferenced_src'."]
    );

    $invalid_tree_client_json = $valid_client_json;
    $invalid_tree_client_json['layout']['components'][1]['type'] = 'sdc.experience_builder.missing_component';
    $this->assertConversionErrors(
      $invalid_tree_client_json,
      ['layout.children[1]' => 'The component <em class="placeholder">sdc.experience_builder.missing_component</em> does not exist.']
    );
  }

  private function assertConversionErrors(array $client_json, array $errors): void {
    [$tree, $props, $violations] = $this->convertClientToServer($client_json['layout'], $client_json['model']);
    $this->assertNull($tree);
    $this->assertNull($props);
    $this->assertSame($errors, $this->violationsToArray($violations));
  }

}
