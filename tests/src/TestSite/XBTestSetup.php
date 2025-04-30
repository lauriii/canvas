<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\TestSite;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\experience_builder\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\RandomGeneratorTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\TestSite\TestSetupInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

class XBTestSetup implements TestSetupInterface {

  use MediaTypeCreationTrait;
  use RandomGeneratorTrait;
  use TestFileCreationTrait;
  use ImageFieldCreationTrait;
  use BlockCreationTrait;
  use CreateTestJsComponentTrait;

  public function setup(): void {
    $module_installer = \Drupal::service('module_installer');
    $module_installer->install(['system', 'user']);
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('system.logging');
    $config->set('error_level', ERROR_REPORTING_DISPLAY_VERBOSE);
    $config->save(TRUE);

    $config = \Drupal::service('config.factory')->getEditable('system.performance');
    $config->set('js.preprocess', FALSE);
    $config->save();

    $module_installer = \Drupal::service('module_installer');
    assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install(['node', 'media', 'block', 'file']);

    $theme = 'stark';
    $admin_theme = "claro";
    \Drupal::service('theme_installer')->install([$theme, $admin_theme]);
    \Drupal::service('config.factory')
      ->getEditable('system.theme')
      ->set('default', $theme)
      ->set('admin', $admin_theme)
      ->save();
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
    $this->createImageField('field_hero', 'node', 'article', storage_settings: [
      // @todo Remove once https://drupal.org/i/3513317 is fixed.
      // We cannot rely on the override because experience_builder module is not
      // yet installed so need to manually specify it here for testing sake.
      // @see \Drupal\experience_builder\Plugin\Field\FieldTypeOverride\ImageItemOverride::defaultStorageSettings
      'display_default' => TRUE,
    ]);

    // The `image` media type must be installed before
    // media_library_storage_prop_shape_alter() is invoked, which it is after
    // installing new modules.
    // @see media_library_storage_prop_shape_alter()
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $test_image_files = $this->getTestFiles('image');
    $first_image_file = $test_image_files[0];
    $file1 = File::create([
      // @phpstan-ignore-next-line
      'uri' => $first_image_file->uri,
    ]);
    $file1->save();
    $second_image_file = $test_image_files[1];
    $file2 = File::create([
      // @phpstan-ignore-next-line
      'uri' => $second_image_file->uri,
    ]);
    $file2->save();
    Media::create([
      'bundle' => 'image',
      'name' => 'The bones are their money',
      'field_media_image' => [
        [
          'target_id' => $file1->id(),
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
          'target_id' => $file2->id(),
          'alt' => 'My barber may have been looking at a picture of a dog',
          'title' => 'When he gave me this haircut',
        ],
      ],
    ])->save();
    $module_installer->install([
      'experience_builder',
      'xb_dev_standard',
      'xb_test_sdc',
      'xb_e2e_support',
      'system',
    ]);

    $this->createMyCtaComponentFromSdc();

    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
    $image_field_sample_value = ImageItem::generateSampleValue($field_definitions['field_hero']);
    \assert(\is_array($image_field_sample_value) && \array_key_exists('target_id', $image_field_sample_value));
    $hero_reference = Media::create([
      'bundle' => 'image',
      'name' => 'Hero image',
      'field_media_image' => $image_field_sample_value,
    ]);
    $hero_reference->save();
    // @todo Add a component without props in https://drupal.org/i/3511447.
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
          // Test edge cases in representations:
          // - server aims to minimize storage
          // - client should be as simple as possible
          // @see docs/data-model.md
          // @see docs/adr/0005-Keep-the-front-end-simple.md
          [
            'uuid' => 'component-instance-with-all-slots-empty',
            'component' => 'sdc.experience_builder.one_column',
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
      'value' => ['target_id' => 3],
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
    $cta1href = [
      'sourceType' => 'static:field_item:uri',
      'value' => 'https://drupal.org',
      'expression' => 'ℹ︎uri␟value',
    ];
    $use_uri = \Drupal::moduleHandler()->moduleExists('xb_test_storage_prop_shape_alter');
    if (!$use_uri) {
      $cta1href = [
        'sourceType' => 'static:field_item:link',
        'sourceTypeSettings' => [
          'instance' => [
            'title' => \DRUPAL_DISABLED,
          ],
        ],
        'value' => ['uri' => 'https://drupal.org'],
        'expression' => 'ℹ︎link␟uri',
      ];
    }
    $inputs = [
      'component-instance-with-all-slots-empty' => [
        'width' => [
          'sourceType' => 'static:field_item:list_string',
          'value' => 'full',
          'expression' => 'ℹ︎list_string␟value',
          'sourceTypeSettings' => [
            'storage' => [
              'allowed_values' => [
                [
                  'value' => 'full',
                  'label' => 'full',
                ],
                [
                  'value' => 'wide',
                  'label' => 'wide',
                ],
                [
                  'value' => 'normal',
                  'label' => 'normal',
                ],
                [
                  'value' => 'narrow',
                  'label' => 'narrow',
                ],
              ],
            ],
          ],
        ],
      ],
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
        'cta1href' => $cta1href,
      ],
      'static-static-card1ab' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'hello, world!',
          'expression' => 'ℹ︎string␟value',
        ],
        'cta1href' => $cta1href,
      ],
      'static-static-card3rr' => [
        'heading' => [
          'sourceType' => 'static:field_item:string',
          'value' => 'XB Needs This For The Time Being',
          'expression' => 'ℹ︎string␟value',
        ],
        'cta1href' => [
          'value' => $use_uri ? $fileUrl : ['uri' => $fileUrl],
        ] + $cta1href,
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

    // Add a Media Library field to the article content type so we can
    // confirm it works in both page data and context forms.
    FieldStorageConfig::create([
      'field_name' => 'media_image_field',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'media',
      ],
    ])->save();
    FieldConfig::create([
      'label' => 'A Media Image Field',
      'field_name' => 'media_image_field',
      'entity_type' => 'node',
      'bundle' => 'article',
      'field_type' => 'entity_reference',
      'required' => FALSE,
      'settings' => [
        'handler' => 'default',
        'handler_settings' => [
          'target_bundles' => ['image'],
        ],
      ],
    ])->save();
    $display_repository = \Drupal::service('entity_display.repository');
    $display_repository->getFormDisplay('node', 'article')
      ->setComponent('media_image_field', [
        'type' => 'media_library_widget',
        'region' => 'content',
        'settings' => [],
      ])
      ->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'XB Needs This For The Time Being',
      'field_hero' => $image_field_sample_value,
      // @todo Add E2E test coverage for starting with an empty canvas in
      //   https://drupal.org/i/3474257.
      'field_xb_demo' => [
        'tree' => json_encode($tree),
        'inputs' => json_encode($inputs),
      ],
    ]);

    $node->save();
    $empty_node = Node::create([
      'type' => 'article',
      'title' => 'I am an empty node',
      'path' => ['alias' => '/i-am-an-empty-node'],
      'field_hero' => $image_field_sample_value,
    ]);
    $empty_node->save();

    $tree['two-column-uuid']['column_one'][] = [
      'uuid' => 'cea4c5b3-7921-4c6f-b388-da921bd1496d',
      'component' => 'block.system_menu_block.admin',
    ];
    $inputs['cea4c5b3-7921-4c6f-b388-da921bd1496d'] = [
      'label' => 'Administration',
      'label_display' => FALSE,
      'level' => 1,
      'depth' => 0,
      'expand_all_items' => FALSE,
    ];
    $node = Node::create([
      'type' => 'article',
      'path' => ['alias' => '/the-one-with-a-block'],
      'title' => 'XB With a block in the layout',
      'field_hero' => $image_field_sample_value,
      // @todo Add E2E test coverage for starting with an empty canvas in
      //   https://drupal.org/i/3474257.
      'field_xb_demo' => [
        'tree' => json_encode($tree),
        'inputs' => json_encode($inputs),
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
        'inputs' => \json_encode([
          'component-sdc' => [
            'heading' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'Welcome to the site!',
              'expression' => 'ℹ︎string␟value',
            ],
          ],
          'component-block' => [
            'label' => '',
            'label_display' => FALSE,
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
          ],
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
      'permissions' => [
        'access administration pages',
        'access content',
        'administer media',
        'access media overview',
        'view media',
        'create media',
        'edit any article content',
        'create article content',
        Page::CREATE_PERMISSION,
        Page::EDIT_PERMISSION,
        Page::DELETE_PERMISSION,
        'administer url aliases',
        JavaScriptComponent::ADMIN_PERMISSION,
        Pattern::ADMIN_PERMISSION,
        'administer themes',
        'administer comments',
        'post comments',
        'administer permissions',
        PageRegion::ADMIN_PERMISSION,
      ],
    ]);
    $xb_role->save();

    $xb_user = User::create();
    $xb_user->setUsername('xbUser');
    $xb_user->setPassword('xbUser');
    $xb_user->setEmail('xb@test.com');
    $xb_user->addRole((string) $xb_role->id());
    $xb_user->enforceIsNew();
    $xb_user->activate();
    $xb_user->save();

    if (getenv('XB_EXTRA_MODULES')) {
      $modules = \explode(',', getenv('XB_EXTRA_MODULES'));
      $module_installer->install($modules);
      if (getenv('XB_EXTRA_PERMISSIONS')) {
        $role = Role::load('xb');
        if ($role) {
          $permissions = \explode(',', getenv('XB_EXTRA_PERMISSIONS'));
          foreach ($permissions as $permission) {
            $role->grantPermission($permission);
          }
          $role->save();
        }
      }

      // Rebuild the container before the test starts making HTTP requests.
      $kernel = \Drupal::service('kernel');
      $kernel->invalidateContainer();
      $kernel->rebuildContainer();
    }
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
