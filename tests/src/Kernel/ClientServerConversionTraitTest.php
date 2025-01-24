<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\Exception\ConstraintViolationException;
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
    $converted_item = $this->convertClientToServer($layout, $model);
    $this->assertSame($this->getValidConvertedInputs(), json_decode($converted_item['inputs'], TRUE));
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
        [
          'uuid' => self::TEST_BLOCK,
          'component' => 'block.system_branding_block',
        ],
      ],
    ], json_decode($converted_item['tree'], TRUE));

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
        'block.system_branding_block',
      ],
      $this->getValidConvertedInputs(),
      '5 amazing uses for old toothbrushes'
    );

    ['layout' => $layout, 'model' => $model] = $this->getValidPatternJson();
    $converted_item = $this->convertClientToServer($layout, $model);
    $this->assertSame($this->getValidConvertedInputs(), json_decode($converted_item['inputs'], TRUE));
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
        [
          'uuid' => self::TEST_BLOCK,
          'component' => 'block.system_branding_block',
        ],
      ],
    ], json_decode($converted_item['tree'], TRUE));

    Pattern::create([
      'id' => 'test_pattern',
      'label' => 'Test Pattern',
      'component_tree' => $converted_item,
    ])->save();

  }

  public function testConvertClientToServerErrors(): void {
    $valid_client_json = $this->getValidClientJson();

    $invalid_image_client_json = $valid_client_json;
    $invalid_image_client_json['model'][self::TEST_IMAGE_UUID]['image']['src'] = '/not/a/real/url';
    $this->assertConversionErrors(
      $invalid_image_client_json,
      [
        // Transformation from client model to input failed.
        // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent::findTargetForProps()
        'model.' . self::TEST_IMAGE_UUID . '.image.src' => "File '/not/a/real/url' not found.",
        // The failed transformation above results in an empty value for the
        // entire SDC prop. Which then fails SDC validation.
        // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
        'model.' . self::TEST_IMAGE_UUID . '.image' => 'The property image is required',
      ],
    );

    $unreferenced_file_client_json = $valid_client_json;
    $unreferenced_src = $this->getSrcPropertyFromFile($this->unreferencedImage);
    $unreferenced_file_client_json['model'][self::TEST_IMAGE_UUID]['image']['src'] = $unreferenced_src;
    $this->assertConversionErrors(
      $unreferenced_file_client_json,
      [
        // Transformation from client model to input failed.
        // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent::findTargetForProps()
        'model.' . self::TEST_IMAGE_UUID . '.image.src' => "No media entity found that uses file '$unreferenced_src'.",
        // The failed transformation above results in an empty value for the
        // entire SDC prop. Which then fails SDC validation.
        // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
        'model.' . self::TEST_IMAGE_UUID . '.image' => 'The property image is required',
      ]
    );

    $invalid_tree_client_json = $valid_client_json;
    $invalid_tree_client_json['layout']['components'][1]['type'] = 'sdc.experience_builder.missing_component';
    $this->assertConversionErrors(
      $invalid_tree_client_json,
      ['layout.children[1]' => 'The component <em class="placeholder">sdc.experience_builder.missing_component</em> does not exist.']
    );

    $invalid_block_settings = $valid_client_json;
    $invalid_block_settings['model'][self::TEST_BLOCK]['use_site_slogan'] = ['this is not a boolean'];
    $this->assertConversionErrors(
      $invalid_block_settings,
      ['model.' . self::TEST_BLOCK . '.use_site_slogan' => 'This value should be of the correct primitive type.'],
    );
  }

  private function assertConversionErrors(array $client_json, array $errors): void {
    try {
      $this->convertClientToServer($client_json['layout'], $client_json['model']);
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
          'type' => 'sdc.experience_builder.heading',
          'slots' => [],
        ],
        [
          'nodeType' => 'component',
          'uuid' => self::TEST_IMAGE_UUID,
          'type' => 'sdc.experience_builder.image',
          'slots' => [],
        ],
        [
          'nodeType' => 'component',
          'uuid' => self::TEST_BLOCK,
          'type' => 'block.system_branding_block',
          'slots' => [],
        ],
      ],
      'model' => [
        self::TEST_HEADING_UUID => [
          'text' => 'This is a random heading.',
          'style' => 'primary',
          'element' => 'h1',
        ],
        self::TEST_IMAGE_UUID => [
          'image' => [
            'src' => $this->getSrcPropertyFromFile($this->referencedImage),
            'alt' => 'This is a random image.',
            'width' => 100,
            'height' => 100,
          ],
        ],
        self::TEST_BLOCK => [
          'use_site_logo' => TRUE,
          'use_site_name' => TRUE,
          'use_site_slogan' => FALSE,
          'label' => '',
          'label_display' => FALSE,
        ],
      ],
    ];
  }

}
