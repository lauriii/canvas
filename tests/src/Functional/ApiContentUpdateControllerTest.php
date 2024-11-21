<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Functional;

use Drupal\Core\Url;
use Drupal\experience_builder\Plugin\DataType\ComponentPropsValues;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use GuzzleHttp\RequestOptions;

final class ApiContentUpdateControllerTest extends FunctionalTestBase {

  use ApiRequestTrait;
  use TestDataUtilitiesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['experience_builder'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  private File $referencedImage;

  private File $unreferencedImage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $test_image_files = $this->getTestFiles('image');
    $this->referencedImage = $this->createFileEntity($test_image_files[0]);
    Media::create([
      'bundle' => 'image',
      'name' => 'The bones are their money',
      'field_media_image' => [
        [
          'target_id' => $this->referencedImage->id(),
          'alt' => 'The bones equal dollars',
          'title' => 'Bones are the skeletons money',
        ],
      ],
    ])->save();
    $this->unreferencedImage = $this->createFileEntity($test_image_files[1]);
  }

  private static function createFileEntity(object $test_image): File {
    // @phpstan-ignore-next-line
    $uri = $test_image->uri;
    $file = File::create(['uri' => $uri]);
    $file->save();
    assert($file instanceof File);
    return $file;
  }

  public function testSave(): void {
    $node1 = $this->createTestNode();
    $this->assertNodeXbField($node1, [], []);
    $heading_uuid = '8f1971f7-68e0-442f-98f2-c541bb071046';
    $image_uuid = '13ad853b-7a5a-4bd7-a33e-559d7a07579d';
    $valid_client_json = [
      'layout' => [
        'uuid' => 'root',
        'nodeType' => 'root',
        'name' => 'root',
        'children' => [
          [
            'children' => [],
            'nodeType' => 'component',
            'type' => 'sdc.experience_builder.heading',
            'uuid' => $heading_uuid,
          ],
          [
            'children' => [],
            'nodeType' => 'component',
            'type' => 'sdc.experience_builder.image',
            'uuid' => $image_uuid,
          ],
        ],
      ],
      'model' => [
        $heading_uuid => [
          'name' => 'Heading',
          'text' => 'This is a random heading.',
          'style' => 'primary',
          'element' => 'h1',
        ],
        $image_uuid => [
          'name' => 'Image',
          'image' => [
            'src' => $this->getSrcPropertyFromFile($this->referencedImage),
            'alt' => 'This is a random image.',
            'width' => '100',
            'height' => '100',
          ],
        ],
      ],
    ];
    // Make a valid client request.
    $this->assertClientRequest(
      $node1,
      $valid_client_json,
      200,
      ['message' => 'Saved successfully.']
    );
    // Ensure the field has been updated.
    $this->assertNodeXbField(
      $node1,
      [
        'sdc.experience_builder.heading',
        'sdc.experience_builder.image',
      ],
      [
        $heading_uuid => [
          'text' => [
            'sourceType' => 'static:field_item:string',
            'value' => 'This is a random heading.',
            'expression' => 'ℹ︎string␟value',
            'sourceTypeSettings' => [
              'storage' => [],
              'instance' => [],
            ],
          ],
          'style' => [
            'sourceType' => 'static:field_item:list_string',
            'value' => 'primary',
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
              'instance' => [],
            ],
          ],
          'element' => [
            'sourceType' => 'static:field_item:list_string',
            'value' => 'h1',
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
              'instance' => [],
            ],
          ],
        ],
        $image_uuid => [
          'image' => [
            'sourceType' => 'static:field_item:entity_reference',
            'value' => [
              'alt' => 'This is a random image.',
              'width' => '100',
              'height' => '100',
              'target_id' => 1,
            ],
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
      ]
    );
    $node2 = $this->createTestNode();
    $this->assertNodeXbField($node2, [], []);

    // Make a request with invalid heading properties.
    $invalid_heading_client_json = $valid_client_json;
    $invalid_heading_client_json['model'][$heading_uuid]['style'] = 'not-a-style';
    $this->assertClientRequest(
      $node2,
      $invalid_heading_client_json,
      422,
      [
        'errors' => [
          [
            'detail' => 'Does not have a value in the enumeration ["primary","secondary"]',
            'source' => [
              'pointer' => "model.$heading_uuid.style",
            ],
          ],
        ],
      ]
    );
    // Ensure the field has not been updated.
    $this->assertNodeXbField($node2, [], []);

    // Make a client request with a non-existent file.
    $invalid_image_client_json = $valid_client_json;
    $invalid_image_client_json['model'][$image_uuid]['image']['src'] = '/not/a/real/url';
    $this->assertClientRequest(
      $node2,
      $invalid_image_client_json,
      422,
      [
        'errors' => [
            [
              'detail' => "File '/not/a/real/url' not found.",
              'source' => [
                'pointer' => "model.$image_uuid.image.src",
              ],
            ],
        ],
      ]
    );
    // Ensure the field has not been updated.
    $this->assertNodeXbField($node2, [], []);

    // Make a client request with a file that is not referenced by any media entity.
    $unreferenced_file_client_json = $valid_client_json;
    $unreferenced_src = $this->getSrcPropertyFromFile($this->unreferencedImage);
    $unreferenced_file_client_json['model'][$image_uuid]['image']['src'] = $unreferenced_src;
    $this->assertClientRequest(
      $node2,
      $unreferenced_file_client_json,
      422,
      [
        'errors' => [
          [
            'detail' => "No media entity found that uses file '$unreferenced_src'.",
            'source' => [
              'pointer' => "model.$image_uuid.image.src",
            ],
          ],
        ],
      ]
    );
    // Ensure the field has not been updated.
    $this->assertNodeXbField($node2, [], []);

    // Make a client request with layout using a missing component.
    $invalid_tree_client_json = $valid_client_json;
    $invalid_tree_client_json['layout']['children'][1]['type'] = 'sdc.experience_builder.missing_component';
    $this->assertClientRequest(
      $node2,
      $invalid_tree_client_json,
      422,
      [
        'errors' => [
          [
            'detail' => 'The component <em class="placeholder">sdc.experience_builder.missing_component</em> does not exist.',
            'source' => [
              'pointer' => "layout.children[1]",
            ],
          ],
        ],
      ]
    );
    // Ensure the field has not been updated.
    $this->assertNodeXbField($node2, [], []);

    // Make request with all properties missing for the heading component.
    $invalid_missing_heading_props_client_json = $valid_client_json;
    unset($invalid_missing_heading_props_client_json['model'][$heading_uuid]);
    $this->assertClientRequest(
      $node2,
      $invalid_missing_heading_props_client_json,
      422,
      [
        'errors' => [
          [
            'detail' => 'The required properties are missing.',
            'source' => [
              'pointer' => "model.$heading_uuid",
            ],
          ],
        ],
      ]
    );
  }

  private function assertNodeXbField(Node $node, array $expected_component_ids, array $expected_props): void {
    $nid = $node->id();
    // Reset the node to ensure we're not getting a cached version.
    $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->resetCache([$nid]);
    $node = Node::load($nid);
    $this->assertInstanceOf(Node::class, $node);
    $item = $node->get('field_xb_demo')[0];
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    $tree = $item->get('tree');
    $this->assertInstanceOf(ComponentTreeStructure::class, $tree);
    $this->assertSame($expected_component_ids, $tree->getComponentIdList());
    $props = $item->get('props');
    $this->assertInstanceOf(ComponentPropsValues::class, $props);
    $props = json_decode((string) $props, TRUE);
    // @todo Replace with a single call to
    //   `\PHPUnit\Framework\Assert::assertEqualsCanonicalizing` in
    //  https://drupal.org/i/3486414. Currently that does not work in all
    //  databases.
    self::recursiveKsort($props);
    self::recursiveKsort($expected_props);
    $this->assertSame($expected_props, $props);
  }

  private static function recursiveKsort(array &$array): void {
    ksort($array);
    foreach ($array as &$value) {
      if (is_array($value)) {
        self::recursiveKsort($value);
      }
    }
  }

  private function assertClientRequest(Node $node, array $client_json, int $expected_status, array $expected_json): void {
    $response = $this->makeApiRequest(
      'PATCH',
      Url::fromUri('base:/xb/api/content-update/node/' . $node->id()),
      [RequestOptions::JSON => $client_json]
    );
    $contents = (string) $response->getBody();
    $this->assertJson($contents, "Response is not valid JSON: $contents");
    $this->assertSame(
      $expected_json,
      json_decode($contents, TRUE)
    );
    self::assertSame($expected_status, $response->getStatusCode());
  }

  private static function getSrcPropertyFromFile(File $file): string {
    $src = str_replace(base_path(), '/', $file->createFileUrl());
    assert(is_string($src));
    return $src;
  }

}
