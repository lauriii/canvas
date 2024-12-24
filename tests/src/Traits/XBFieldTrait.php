<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\experience_builder\Plugin\DataType\ComponentPropsValues;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\TestFileCreationTrait;

trait XBFieldTrait {

  use TestFileCreationTrait;

  private const TEST_HEADING_UUID = '8f1971f7-68e0-442f-98f2-c541bb071046';
  private const TEST_IMAGE_UUID = '13ad853b-7a5a-4bd7-a33e-559d7a07579d';

  private File $referencedImage;
  private File $unreferencedImage;
  private Media $mediaEntity;

  protected function getValidConvertedProps(): array {
    return [
      self::TEST_HEADING_UUID => [
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
      self::TEST_IMAGE_UUID => [
        'image' => [
          'sourceType' => 'static:field_item:entity_reference',
          'value' => [
            'alt' => 'This is a random image.',
            'width' => 100,
            'height' => 100,
            'target_id' => (int) $this->mediaEntity->id(),
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
    ];
  }

  private function setUpImages(): void {
    $test_image_files = $this->getTestFiles('image');
    // Start with the second image because
    // \Drupal\Tests\experience_builder\TestSite\XBTestSetup::setup() already
    // creates a media image that references the first image.
    $this->referencedImage = $this->createFileEntity($test_image_files[1]);
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'The bones are their money',
      'field_media_image' => [
        [
          'target_id' => (string) $this->referencedImage->id(),
          'alt' => 'The bones equal dollars',
          'title' => 'Bones are the skeletons money',
        ],
      ],
    ]);
    $media->save();
    assert($media instanceof Media);
    $this->mediaEntity = $media;
    $this->unreferencedImage = $this->createFileEntity($test_image_files[2]);
  }

  private static function createFileEntity(object $test_image): File {
    // @phpstan-ignore-next-line
    $uri = $test_image->uri;
    $file = File::create(['uri' => $uri]);
    $file->save();
    assert($file instanceof File);
    return $file;
  }

  private function assertNodeValues(Node $node, array $expected_component_ids, array $expected_props, string $title): void {
    $nid = $node->id();
    // Reset the node to ensure we're not getting a cached version.
    $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->resetCache([$nid]);
    $node = Node::load($nid);
    $this->assertInstanceOf(Node::class, $node);
    $this->assertSame($title, (string) $node->getTitle());
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

  private function getValidClientJson(): array {
    return [
      'layout' => [
        [
          'nodeType' => 'region',
          'name' => 'Content',
          'id' => 'content',
          'components' => [
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
          ],
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
      ],
      'entity_form_fields' => [
        'title' => [
            [
              'value' => 'The updated title.',
            ],
        ],
      ],
    ];
  }

  private static function getSrcPropertyFromFile(File $file): string {
    $src = str_replace(base_path(), '/', $file->createFileUrl());
    assert(is_string($src));
    return $src;
  }

  private function assertValidJsonUpdateNode(Node $node): void {
    // Ensure the field has been updated.
    $this->assertNodeValues(
      $node,
      [
        'sdc.experience_builder.heading',
        'sdc.experience_builder.image',
      ],
      $this->getValidConvertedProps(),
      'The updated title.'
    );

  }

}
