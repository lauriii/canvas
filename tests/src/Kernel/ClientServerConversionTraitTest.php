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
  private function getValidClientJson(bool $dynamic_image = TRUE): array {
    $json = $this->traitGetValidClientJson($dynamic_image);
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
    ['layout' => $layout, 'model' => $model] = $this->getValidClientJson(FALSE);
    $converted_item = $this->convertClientToServer($layout, $model);
    $expected_inputs = $this->getValidConvertedInputs(FALSE);
    self::assertEqualsCanonicalizing($expected_inputs, json_decode($converted_item['inputs'], TRUE));
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
      $expected_inputs,
      '5 amazing uses for old toothbrushes'
    );

    ['layout' => $layout, 'model' => $model] = $this->getValidPatternJson();
    $converted_item = $this->convertClientToServer($layout, $model);
    self::assertEqualsCanonicalizing($this->getValidConvertedInputs(FALSE), json_decode($converted_item['inputs'], TRUE));
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
    $invalid_image_client_json['model'][self::TEST_IMAGE_UUID]['resolved']['image']['src'] = '/not/a/real/url';
    // The sample json sends this as a dynamic prop based on the hero field,
    // let's switch it to a static source.
    $invalid_image_client_json['model'][self::TEST_IMAGE_UUID]['source']['image'] = [
      'sourceType' => 'static:field_item:entity_reference',
      'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
      'sourceTypeSettings' =>
        [
          'storage' => [
            'target_type' => 'media',
          ],
          'instance' => [
            'handler' => 'default:media',
            'handler_settings' => [
              'target_bundles' => [
                'image' => 'image',
              ],
            ],
          ],
        ],
    ];
    $suffix = '';
    if (\version_compare(\Drupal::VERSION, '11.2', '>=') || \version_compare(\Drupal::VERSION, '11.2-dev', '>=')) {
      // The format of component violation messages changed in Drupal 11.2.
      // @see https://drupal.org/i/3462700
      $suffix = '.';
    }
    $this->assertConversionErrors(
      $invalid_image_client_json,
      [
        // Transformation from client model to input failed.
        // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent::findTargetForProps()
        'model.' . self::TEST_IMAGE_UUID . '.image.src' => "File '/not/a/real/url' not found.",
        // The failed transformation above results in an empty value for the
        // entire SDC prop. Which then fails SDC validation.
        // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
        'model.' . self::TEST_IMAGE_UUID . '.image' => 'The property image is required' . $suffix,
      ],
    );

    $unreferenced_file_client_json = $valid_client_json;
    $unreferenced_src = $this->getSrcPropertyFromFile($this->unreferencedImage);
    // Copy the static source settings from the previous invalid json.
    $unreferenced_file_client_json['model'][self::TEST_IMAGE_UUID] = $invalid_image_client_json['model'][self::TEST_IMAGE_UUID];
    $unreferenced_file_client_json['model'][self::TEST_IMAGE_UUID]['resolved']['image']['src'] = $unreferenced_src;
    $suffix = '';
    if (\version_compare(\Drupal::VERSION, '11.2', '>=') || \version_compare(\Drupal::VERSION, '11.2-dev', '>=')) {
      // The format of component violation messages changed in Drupal 11.2.
      // @see https://drupal.org/i/3462700
      $suffix = '.';
    }
    $this->assertConversionErrors(
      $unreferenced_file_client_json,
      [
        // Transformation from client model to input failed.
        // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\SingleDirectoryComponent::findTargetForProps()
        'model.' . self::TEST_IMAGE_UUID . '.image.src' => "No media entity found that uses file '$unreferenced_src'.",
        // The failed transformation above results in an empty value for the
        // entire SDC prop. Which then fails SDC validation.
        // @see \Drupal\Core\Theme\Component\ComponentValidator::validateProps()
        'model.' . self::TEST_IMAGE_UUID . '.image' => 'The property image is required' . $suffix,
      ]
    );

    $invalid_tree_client_json = $valid_client_json;
    $invalid_tree_client_json['layout']['components'][1]['type'] = 'sdc.experience_builder.missing_component';
    $this->assertConversionErrors(
      $invalid_tree_client_json,
      ['layout.children[1]' => 'The component <em class="placeholder">sdc.experience_builder.missing_component</em> does not exist.']
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
                  'allowed_values' => [
                    [
                      'value' => 'primary',
                      'label' => 'primary',
                    ],
                    [
                      'value' => 'secondary',
                      'label' => 'secondary',
                    ],
                  ],
                ],
              ],
            ],
            'element' => [
              'sourceType' => 'static:field_item:list_string',
              'expression' => 'ℹ︎list_string␟value',
              'sourceTypeSettings' => [
                'storage' => [
                  'allowed_values' => [
                    [
                      'value' => 'div',
                      'label' => 'div',
                    ],
                    [
                      'value' => 'h1',
                      'label' => 'h1',
                    ],
                    [
                      'value' => 'h2',
                      'label' => 'h2',
                    ],
                    [
                      'value' => 'h3',
                      'label' => 'h3',
                    ],
                    [
                      'value' => 'h4',
                      'label' => 'h4',
                    ],
                    [
                      'value' => 'h5',
                      'label' => 'h5',
                    ],
                    [
                      'value' => 'h6',
                      'label' => 'h6',
                    ],
                  ],
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
              'sourceType' => 'static:field_item:entity_reference',
              'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟url,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
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
