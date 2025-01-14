<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\TestSite;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\file\Entity\File;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\RandomGeneratorTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\TestSite\TestSetupInterface;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;

class XBTestSetup implements TestSetupInterface {

  use MediaTypeCreationTrait;
  use RandomGeneratorTrait;
  use TestFileCreationTrait;
  use ImageFieldCreationTrait;
  use BlockCreationTrait;

  public function setup(): void {
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('system.logging');
    $config->set('error_level', ERROR_REPORTING_DISPLAY_VERBOSE);
    $config->save(TRUE);

    $config = \Drupal::service('config.factory')->getEditable('system.performance');
    $config->set('js.preprocess', FALSE);
    $config->save();

    $module_installer = \Drupal::service('module_installer');
    assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install(['node', 'media', 'block']);
    $theme = 'stark';
    \Drupal::service('theme_installer')->install([$theme]);
    \Drupal::service('config.factory')->getEditable('system.theme')->set('default', $theme)->save();
    \Drupal::service('theme.manager')->resetActiveTheme();
    // Place the page title block.
    $this->placeBlock('page_title_block', ['region' => 'highlighted']);
    $this->placeBlock('system_messages_block');
    $this->placeBlock('system_main_block');

    $type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $type->save();
    $this->createImageField('field_hero', 'node', 'article');

    // The `image` media type must be installed before
    // media_library_storage_prop_shape_alter() is invoked, which it is after
    // installing new modules.
    // @see media_library_storage_prop_shape_alter()
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $test_image_files = $this->getTestFiles('image');
    $first_image_file = $test_image_files[0];
    File::create([
      // @phpstan-ignore-next-line
      'uri' => $first_image_file->uri,
    ])->save();
    Media::create([
      'bundle' => 'image',
      'name' => 'The bones are their money',
      'field_media_image' => [
        [
          'target_id' => 1,
          'alt' => 'The bones equal dollars',
          'title' => 'Bones are the skeletons money',
        ],
      ],
    ])->save();
    Media::create([
      'bundle' => 'image',
      'name' => 'Sorry I resemble a dog',
      'field_media_image' => [
        [
          'target_id' => 1,
          'alt' => 'My barber may have been looking at a picture of a dog',
          'title' => 'When he gave me this haircut',
        ],
      ],
    ])->save();
    $module_installer->install([
      'experience_builder',
      'xb_dev_standard',
      'xb_e2e_support',
    ]);

    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
    $image_field_sample_value = ImageItem::generateSampleValue($field_definitions['field_hero']);
    \assert(\is_array($image_field_sample_value) && \array_key_exists('target_id', $image_field_sample_value));
    $hero_reference = Media::create([
      'bundle' => 'image',
      'name' => 'Hero image',
      'field_media_image' => $image_field_sample_value,
    ]);
    $hero_reference->save();
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [
        [
          'uuid' => 'two-column-uuid',
          'component' => 'sdc.experience_builder.two_column',
        ],
      ],
      'two-column-uuid' => [
        'column_one' => [
          [
            'uuid' => 'static-image-udf7d',
            'component' => 'sdc.experience_builder.image',
          ],
          [
            'uuid' => 'static-static-card1ab',
            'component' => 'sdc.experience_builder.my-hero',
          ],
        ],
        'column_two' => [
          [
            'uuid' => 'static-static-card2df',
            'component' => 'sdc.experience_builder.my-hero',
          ],
          [
            'uuid' => 'static-static-card3rr',
            'component' => 'sdc.experience_builder.my-hero',
          ],
          [
            'uuid' => 'static-image-static-imageStyle-something7d',
            'component' => 'sdc.experience_builder.image',
          ],
        ],
      ],
    ];

    // @phpstan-ignore-next-line
    $fileUrl = File::load($image_field_sample_value['target_id'])->createFileUrl(FALSE);
    $static_image_prop_source = [
      'sourceType' => 'static:field_item:entity_reference',
      'value' => [
        'alt' => 'This is a random image.',
        'width' => 100,
        'height' => 100,
        'target_id' => (int) $hero_reference->id(),
      ],
      // This expression resolves `src` to the image's public URL.
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
    ];
    $props = [
      'two-column-uuid' => [
        'width' => [
          'sourceType' => 'static:field_item:list_integer',
          'value' => 50,
          'expression' => 'ℹ︎list_integer␟value',
          'sourceTypeSettings' => [
            'storage' => [
              'allowed_values' => [
                [
                  'value' => 25,
                  'label' => '25',
                ],
                [
                  'value' => 33,
                  'label' => '33',
                ],
                [
                  'value' => 50,
                  'label' => '50',
                ],
                [
                  'value' => 66,
                  'label' => '66',
                ],
                [
                  'value' => 75,
                  'label' => '75',
                ],
              ],
            ],
          ],
        ],
      ],
      'static-static-card2df' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'XB Needs This For The Time Being',
          'expression' => 'ℹ︎string␟value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:uri',
          'value' => 'https://drupal.org',
          'expression' => 'ℹ︎uri␟value',
        ],
      ],
      'static-static-card1ab' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'hello, world!',
          'expression' => 'ℹ︎string␟value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:uri',
          'value' => 'https://drupal.org',
          'expression' => 'ℹ︎uri␟value',
        ],
      ],
      'static-static-card3rr' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'XB Needs This For The Time Being',
          'expression' => 'ℹ︎string␟value',
        ],
        'cta1href' => [
          'sourceType' => 'static:field_item:uri',
          'value' => $fileUrl,
          'expression' => 'ℹ︎uri␟value',
        ],
      ],
      'static-image-udf7d' => [
        'image' => $static_image_prop_source,
      ],
      'static-image-static-imageStyle-something7d' => [
        'image' => [
          'sourceType' => 'adapter:image_apply_style',
          'adapterInputs' => [
            // This expression resolves `src` to the image's stream wrapper URI.
            'image' => [
              'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
            ] + $static_image_prop_source,
            'imageStyle' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'thumbnail',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
        ],
      ],
      'cea4c5b3-7921-4c6f-b388-da921bd1496d' => [],
    ];
    $node = Node::create([
      'type' => 'article',
      'title' => 'XB Needs This For The Time Being',
      'field_hero' => $image_field_sample_value,
      // @todo Add E2E test coverage for starting with an empty canvas in
      //   https://drupal.org/i/3474257.
      'field_xb_demo' => [
        'tree' => json_encode($tree),
        'props' => json_encode($props),
      ],
    ]);

    $node->save();
    $empty_node = Node::create([
      'type' => 'article',
      'title' => 'I am an empty node',
    ]);
    $empty_node->save();

    $tree['two-column-uuid']['column_one'][] = [
      'uuid' => 'cea4c5b3-7921-4c6f-b388-da921bd1496d',
      'component' => 'block.system_menu_block.admin',
    ];
    $props['cea4c5b3-7921-4c6f-b388-da921bd1496d'] = [];
    $node = Node::create([
      'type' => 'article',
      'title' => 'XB With a block in the layout',
      'field_hero' => $image_field_sample_value,
      // @todo Add E2E test coverage for starting with an empty canvas in
      //   https://drupal.org/i/3474257.
      'field_xb_demo' => [
        'tree' => json_encode($tree),
        'props' => json_encode($props),
      ],
    ]);
    $node->save();

    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'This is the homepage',
      'path' => ['alias' => '/homepage'],
      'components' => [
        'tree' => \json_encode([
          ComponentTreeStructure::ROOT_UUID => [
            [
              'uuid' => 'component-sdc',
              'component' => 'sdc.xb_test_sdc.props-slots',
            ],
            [
              'uuid' => 'component-block',
              'component' => 'block.system_branding_block',
            ],
          ],
        ]),
        'props' => \json_encode([
          'component-sdc' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Welcome to the site!',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'component-block' => [],
        ]),
      ],
    ]);
    $page->save();

    $empty_page = Page::create([
      'title' => 'Empty Page',
      'description' => 'This is an empty page',
      'path' => ['alias' => '/test-page'],
    ]);
    $empty_page->save();

    $xb_role = Role::create([
      'id' => 'xb',
      'label' => 'xb',
    ]);
    $xb_role->grantPermission('access administration pages');
    $xb_role->grantPermission('access content');
    $xb_role->grantPermission('administer media');
    $xb_role->grantPermission('access media overview');
    $xb_role->grantPermission('view media');
    $xb_role->grantPermission('create media');
    $xb_role->grantPermission('create article content');
    $xb_role->grantPermission('administer xb_page');
    $xb_role->save();

    $xb_user = User::create();
    $xb_user->setUsername('xbUser');
    $xb_user->setPassword('xbUser');
    $xb_user->setEmail('xb@test.com');
    $xb_user->addRole((string) $xb_role->id());
    $xb_user->enforceIsNew();
    $xb_user->activate();
    $xb_user->save();
  }

  /**
   * TRICKY: to allow reusing MediaTypeCreationTrait, simulate `::assertSame()`.
   *
   * @see \Drupal\Tests\media\Traits\MediaTypeCreationTrait
   */
  public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void {
    // Intentionally empty;
  }

}
