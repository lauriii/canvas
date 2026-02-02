<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\TestSite;

use Drupal\canvas\Entity\Component as ComponentEntity;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\Pattern;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\canvas\Traits\CanvasFieldCreationTrait;
use Drupal\Tests\canvas\Traits\CreateTestJsComponentTrait;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\RandomGeneratorTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\TestSite\TestSetupInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

if (!\class_exists(TestSetupInterface::class)) {
  // We're running test-discovery inside run-tests.sh which is before
  // autoloading for the \Drupal\TestSite namespace has been established.
  // run-tests.sh has set the cwd to the Drupal root.
  // @todo Remove in https://drupal.org/i/3531679
  $root = getcwd();
  $interface = $root . '/core/tests/Drupal/TestSite/TestSetupInterface.php';
  // If the site is installed under `web/`, sometimes getcwd returns
  // /var/www/html but not /var/www/html/web and it fails.
  if (!\file_exists($interface)) {
    $interface = $root . '/web/core/tests/Drupal/TestSite/TestSetupInterface.php';
  }
  if (!\file_exists($interface)) {
    $interface = $root . '/tests/Drupal/TestSite/TestSetupInterface.php';
  }
  require_once $interface;
}

class CanvasTestSetup implements TestSetupInterface {

  // Fixed IDs for testing sake
  public const string UUID_EMPTY_COMPONENT = 'cea4c5b3-7921-4c6f-b388-da921bd1496d';
  public const string UUID_TWO_COLUMN_UUID = '16176e0b-8197-40e3-ad49-48f1b6e9a7f9';
  public const string UUID_STATIC_IMAGE = '8f6780cd-7b64-499e-9545-321a14951a0d';
  public const string UUID_STATIC_CARD1 = '208452de-10d6-4fb8-89a1-10e340b3744c';
  public const string UUID_CODE_COMPONENT = '5fc4de04-f59c-4f56-b576-4673433381a4';
  public const string UUID_ALL_SLOTS_EMPTY = 'b8fd639d-f1df-413a-8926-8d2c7a3d6493';
  public const string UUID_STATIC_CARD2 = '4d866c38-7261-45c6-9b1e-0b94096d51e8';
  public const string UUID_STATIC_CARD3 = '5944ef12-4a3d-4f3a-8e67-086661be9ffc';
  public const string UUID_ADAPTED_IMAGE = 'd8afcb97-c2ba-426e-b2da-94600afd484b';
  public const string UUID_COMPONENT_SDC = '2c6e91ae-23ac-433d-9bb8-687144464b34';
  public const string UUID_COMPONENT_BLOCK = '78c73c1d-4988-4f9b-ad17-f7e337d40c29';

  use MediaTypeCreationTrait;
  use RandomGeneratorTrait;
  use TestFileCreationTrait;
  use ImageFieldCreationTrait;
  use BlockCreationTrait;
  use CreateTestJsComponentTrait;
  use CanvasFieldCreationTrait;

  protected string $root;

  protected static array $configSchemaCheckerExclusions = [
    // The "all-props" test-only SDC is used to assess also prop shapes that are
    // not yet storable, and hence do not meet the requirements.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery::checkRequirements()
    // @see /ui/tests/e2e/prop-types.cy.js
    'canvas.' . ComponentEntity::ENTITY_TYPE_ID . '.' . SingleDirectoryComponent::SOURCE_PLUGIN_ID . '.sdc_test_all_props.all-props',
  ];

  public function setup(bool $createContentTemplate = FALSE): void {
    // CreateTestJsComponentTrait requires having the $root set.
    $container = \Drupal::getContainer();
    $root = $container && $container->hasParameter('app.root') ? $container->getParameter('app.root') : DRUPAL_ROOT;
    \assert(is_string($root));
    $this->root = $root;

    // TRICKY: this runs in TestSiteInstallCommand, which has no way for either
    // disabling strict config schema checking OR for adding config schema
    // exclusions.
    // We cannot do that by implementing \Drupal\TestSite\TestPreinstallInterface
    // because that's too early.
    // So we are in a difficult spot: we already installed Drupal, and we can
    // use its services, but we cannot alter them.
    // So time for hacking the services.yml file, which we can ensure it exists
    // because of \Drupal\TestSite\Commands\TestSiteInstallCommand::installDrupal
    // using \Drupal\Core\Test\FunctionalTestSetupTrait::prepareSettings.
    // This works around that Drupal core limitation.
    // But... we are reusing this in some Kernel/Functional tests, which we
    // should stop doing. In the meantime, we need to verify to only alter this
    // file if it exists. For the use cases where it doesn't, we don't care as
    // those will pick up the proper $configSchemaCheckerExclusions from the
    // test itself.
    $site_path = $container->getParameter('site.path');
    \assert(is_string($site_path));
    $services_yml = $site_path . '/services.yml';
    if (file_exists($services_yml)) {
      $yaml = new SymfonyYaml();
      $content = file_get_contents($services_yml);
      \assert(is_string($content));
      $services = $yaml->parse($content);
      // @see \Drupal\Core\Test\FunctionalTestSetupTrait::prepareSettings
      // for the`testing.config_schema_checker` service definition.
      array_push(
        $services['services']['testing.config_schema_checker']['arguments'][1],
        ...self::$configSchemaCheckerExclusions,
      );
      file_put_contents($services_yml, $yaml->dump($services));

      // Container service files like the one we just changed are cached. We
      // need to invalidate it so the container is rebuilt reliably.
      // @see \Drupal\Core\Test\FunctionalTestSetupTrait::setContainerParameter()
      FileCacheFactory::get('container_yaml_loader')->delete($services_yml);

      // Rebuild the container before the test continues with the installation.
      $kernel = \Drupal::service('kernel');
      $kernel->invalidateContainer();
      $kernel->rebuildContainer();
    }

    $module_installer = \Drupal::service('module_installer');
    $module_installer->install(['system', 'user']);
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('system.logging');
    $config->set('error_level', ERROR_REPORTING_DISPLAY_VERBOSE);
    $config->save(TRUE);

    if (getenv('CANVAS_DISABLE_AGGREGATION') !== 'true') {
      $config = \Drupal::service('config.factory')->getEditable('system.performance');
      $config->set('js.preprocess', TRUE);
      $config->set('css.preprocess', TRUE);
      $config->save();
    }

    $module_installer = \Drupal::service('module_installer');
    \assert($module_installer instanceof ModuleInstallerInterface);
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
      // We cannot rely on the override because canvas module is not
      // yet installed so need to manually specify it here for testing sake.
      // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::defaultStorageSettings
      'display_default' => TRUE,
    ]);

    // The `image` media type must be installed before
    // \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
    // is invoked, which it is after installing new modules.
    // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
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
      'canvas',
      // Enabling Canvas OAuth to ensure that we don't break any routes for
      // non-OAuth2 requests.
      'canvas_oauth',
      'canvas_test_sdc',
      'canvas_e2e_support',
      'system',
    ]);
    $this->createComponentTreeField('node', 'article', 'field_canvas_demo');
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'article')
      ->setComponent('field_canvas_demo', [
        'label' => 'hidden',
        'type' => 'canvas_naive_render_sdc_tree',
        // The image field has weight -1 by default.
        'weight' => -2,
      ])
      ->save();

    $this->createMyCtaComponentFromSdc();
    $this->createTestCodeComponent();

    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
    $image_field_sample_value = ImageItem::generateSampleValue($field_definitions['field_hero']);
    // The field_hero field doesn't support 'title' in its field settings.
    $image_field_sample_value['title'] = '';
    \assert(\is_array($image_field_sample_value) && \array_key_exists('target_id', $image_field_sample_value));
    $hero_reference = Media::create([
      'bundle' => 'image',
      'name' => 'Hero image',
      'field_media_image' => $image_field_sample_value,
    ]);
    $hero_reference->save();
    // @todo Add a component without props in https://drupal.org/i/3511447.

    // @phpstan-ignore-next-line
    $fileUrl = File::load($image_field_sample_value['target_id'])->createFileUrl(FALSE);
    $static_image_prop_source = [
      'sourceType' => 'static:field_item:entity_reference',
      'value' => ['target_id' => 3],
      // This expression resolves `src` to the image's public URL.
      // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
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
    ];
    // Rely on `StaticPropSource::toArray()` (just like at runtime!) to ensure
    // consistent key order, enabling deterministic auto-save hashing.
    $static_image_prop_source = StaticPropSource::parse($static_image_prop_source)->toArray();
    $use_uri = \Drupal::moduleHandler()->moduleExists('canvas_test_storable_prop_shape_alter');
    $items = [
      [
        'component_id' => 'sdc.canvas_test_sdc.two_column',
        'uuid' => self::UUID_TWO_COLUMN_UUID,
        'inputs' => [
          'width' => 50,
        ],
      ],
      [
        'parent_uuid' => self::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_one',
        'component_id' => 'sdc.canvas_test_sdc.image',
        'uuid' => self::UUID_STATIC_IMAGE,
        'inputs' => [
          'image' => ['target_id' => 3],
        ],
      ],
      [
        'parent_uuid' => self::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_one',
        'component_id' => 'sdc.canvas_test_sdc.my-hero',
        'uuid' => self::UUID_STATIC_CARD1,
        'inputs' => [
          'heading' => 'hello, world!',
          'cta1href' => $use_uri
            ? 'https://drupal.org'
            : ['uri' => 'https://drupal.org', 'options' => []],
        ],
      ],
      [
        'parent_uuid' => self::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_one',
        'component_id' => 'js.test-code-component',
        'uuid' => self::UUID_CODE_COMPONENT,
        'inputs' => [
          'heading' => 'Test Code Component Heading',
          'content' => 'This is a test code component for testing the Edit component action',
        ],
      ],
      // Test edge cases in representations:
      // - server aims to minimize storage
      // - client should be as simple as possible
      // @see docs/data-model.md
      // @see docs/adr/0005-Keep-the-front-end-simple.md
      [
        'parent_uuid' => self::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_one',
        'uuid' => self::UUID_ALL_SLOTS_EMPTY,
        'component_id' => 'sdc.canvas_test_sdc.one_column',
        'inputs' => [
          'width' => 'full',
        ],
      ],
      [
        'parent_uuid' => self::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_two',
        'uuid' => self::UUID_STATIC_CARD2,
        'component_id' => 'sdc.canvas_test_sdc.my-hero',
        'inputs' => [
          'heading' => 'Canvas Needs This For The Time Being',
          'cta1href' => $use_uri
            ? 'https://drupal.org'
            : ['uri' => 'https://drupal.org', 'options' => []],
        ],
      ],
      [
        'parent_uuid' => self::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_two',
        'uuid' => self::UUID_STATIC_CARD3,
        'component_id' => 'sdc.canvas_test_sdc.my-hero',
        'inputs' => [
          'heading' => 'Canvas Needs This For The Time Being',
          'cta1href' => $use_uri
            ? $fileUrl
            : ['uri' => $fileUrl, 'options' => []],
        ],
      ],
      [
        'parent_uuid' => self::UUID_TWO_COLUMN_UUID,
        'slot' => 'column_two',
        'uuid' => self::UUID_ADAPTED_IMAGE,
        'component_id' => 'sdc.canvas_test_sdc.image',
        'inputs' => [
          'image' => [
            'sourceType' => 'adapter:image_apply_style',
            'adapterInputs' => [
              // This expression resolves `src` to the image's stream wrapper
              // URI.
              // Rely on `StaticPropSource::toArray()` (just like at runtime!)
              // to ensure consistent key order, enabling deterministic
              // auto-save hashing.
              'image' => StaticPropSource::parse([
                'expression' => 'ℹ︎entity_reference␟{src↝entity␜␜entity:media:image␝field_media_image␞␟entity␜␜entity:file␝uri␞␟value,alt↝entity␜␜entity:media:image␝field_media_image␞␟alt,width↝entity␜␜entity:media:image␝field_media_image␞␟width,height↝entity␜␜entity:media:image␝field_media_image␞␟height}',
              ] + $static_image_prop_source)->toArray(),
              'imageStyle' => [
                'sourceType' => 'static:field_item:string',
                'value' => 'thumbnail',
                'expression' => 'ℹ︎string␟value',
              ],
            ],
          ],
        ],
        'label' => 'Magnificent image!',
      ],
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
      'title' => 'Canvas Needs This For The Time Being',
      'field_hero' => $image_field_sample_value,
      // @todo Add E2E test coverage for starting with an empty canvas in
      //   https://drupal.org/i/3474257.
      'field_canvas_demo' => $items,
    ]);

    $node->save();

    if ($createContentTemplate) {
      $contentTemplate = ContentTemplate::create([
        'content_entity_type_id' => 'node',
        'content_entity_type_bundle' => 'article',
        'content_entity_type_view_mode' => 'full',
        'component_tree' => $items,
      ]);
      $contentTemplate->save();
    }

    $empty_node = Node::create([
      'type' => 'article',
      'title' => 'I am an empty node',
      'path' => ['alias' => '/i-am-an-empty-node'],
      'field_hero' => $image_field_sample_value,
    ]);
    $empty_node->save();
    $items[] = [
      'parent_uuid' => self::UUID_TWO_COLUMN_UUID,
      'slot' => 'column_one',
      'component_id' => 'block.system_menu_block.admin',
      'uuid' => self::UUID_EMPTY_COMPONENT,
      'inputs' => [
        'label' => 'Administration',
        'label_display' => FALSE,
        'level' => 1,
        'depth' => NULL,
        'expand_all_items' => FALSE,
      ],
    ];
    $node = Node::create([
      'type' => 'article',
      'path' => ['alias' => '/the-one-with-a-block'],
      'title' => 'Canvas With a block in the layout',
      'field_hero' => $image_field_sample_value,
      // @todo Add E2E test coverage for starting with an empty canvas in
      //   https://drupal.org/i/3474257.
      'field_canvas_demo' => $items,
    ]);
    $node->save();

    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'This is the homepage',
      'path' => ['alias' => '/homepage'],
      'components' => [
        [
          'uuid' => self::UUID_COMPONENT_SDC,
          'component_id' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Welcome to the site!',
          ],
        ],
        [
          'uuid' => self::UUID_COMPONENT_BLOCK,
          'component_id' => 'block.system_branding_block',
          'inputs' => [
            'label' => '',
            'label_display' => FALSE,
            'use_site_logo' => TRUE,
            'use_site_name' => TRUE,
            'use_site_slogan' => TRUE,
          ],
        ],
      ],
    ]);
    $page->save();

    $empty_page = Page::create([
      'title' => 'Empty Page',
      'description' => 'This is an empty page',
      'path' => ['alias' => '/test-page'],
    ]);
    $empty_page->save();

    $page_without_path = Page::create([
      'title' => 'Page without a path',
      'description' => 'This is a page without a path',
    ]);
    $page_without_path->save();

    $canvas_role = Role::create([
      'id' => 'canvas',
      'label' => 'canvas',
      'permissions' => [
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
        AutoSaveManager::PUBLISH_PERMISSION,
        'administer url aliases',
        'create url aliases',
        JavaScriptComponent::ADMIN_PERMISSION,
        Pattern::ADMIN_PERMISSION,
        'administer themes',
        'administer comments',
        'post comments',
        'administer permissions',
        PageRegion::ADMIN_PERMISSION,
        'administer site configuration',
      ],
    ]);
    $canvas_role->save();

    $canvas_user = User::create();
    $canvas_user->setUsername('canvasUser');
    $canvas_user->setPassword('canvasUser');
    $canvas_user->setEmail('canvas@test.com');
    $canvas_user->addRole((string) $canvas_role->id());
    $canvas_user->enforceIsNew();
    $canvas_user->activate();
    $canvas_user->save();

    if (getenv('CANVAS_EXTRA_MODULES')) {
      $modules = \explode(',', getenv('CANVAS_EXTRA_MODULES') ?: '');
      $module_installer->install($modules);

      // Rebuild the container before the test starts making HTTP requests.
      $kernel = \Drupal::service('kernel');
      $kernel->invalidateContainer();
      $kernel->rebuildContainer();
    }
    if (getenv('CANVAS_EXTRA_PERMISSIONS')) {
      $role = Role::load('canvas');
      if ($role) {
        $permissions = \explode(',', getenv('CANVAS_EXTRA_PERMISSIONS') ?: '');
        foreach ($permissions as $permission) {
          $role->grantPermission($permission);
        }
        $role->save();
      }
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
