<?php

declare(strict_types=1);

// cspell:ignore Bwidth Fitok Synx

namespace Drupal\Tests\canvas\Kernel\EcosystemSupport;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepository;
use Drupal\media\Entity\Media;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PredictableImageStyleItokTestTrait;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[Group('canvas')]
final class JsonapiSupportTest extends CanvasKernelTestBase {

  use MediaTypeCreationTrait;
  use PredictableImageStyleItokTestTrait;
  use TestFileCreationTrait;
  use UserCreationTrait;
  use ContentTypeCreationTrait;
  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'jsonapi',
    'field',
    'serialization',
  ];

  private UserInterface $user;
  private Page $page;
  private ContentTemplate $contentTemplate;

  private PageRegion $pageRegion;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('field_storage_config');
    $this->installEntitySchema('field_config');
    $this->installEntitySchema('user');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['user']);
    $this->installConfig(['canvas']);
    $this->installConfig(['image']);
    $this->installConfig(['node']);
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installEntitySchema('node');
    $this->setupPredictableItok();

    $this->user = $this->setUpCurrentUser([], [
      'access content',
      'view media',
      Page::CREATE_PERMISSION,
      Page::EDIT_PERMISSION,
    ]);

    $media_type = $this->createMediaType('image');
    $image_file = File::create([
      // @phpstan-ignore-next-line
      'uri' => $this->getTestFiles('image')[0]->uri,
      'uid' => $this->user->id(),
    ]);
    self::assertEntityIsValid($image_file);
    $image_file->save();
    $media_image = Media::create([
      'bundle' => $media_type->id(),
      'name' => 'Test image',
      'field_media_image' => $image_file,
      'uid' => $this->user->id(),
      'status' => TRUE,
    ]);
    self::assertEntityIsValid($media_image);
    $media_image->save();

    $article_content_type = $this->createContentType(['type' => 'article', 'name' => 'Article']);
    FieldStorageConfig::create([
      'field_name' => 'field_test',
      'type' => 'text',
      'entity_type' => 'node',
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_test',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Test field',
    ])->save();

    $component_source_manager = \Drupal::service(ComponentSourceManager::class);
    \assert($component_source_manager instanceof ComponentSourceManager);
    $component_source_manager->generateComponents();

    $components = Component::loadMultiple();

    $this->contentTemplate = ContentTemplate::create([
      'content_entity_type_id' => 'node',
      'content_entity_type_bundle' => $article_content_type->id(),
      'content_entity_type_view_mode' => 'full',
      'component_tree' => [],
      'status' => TRUE,
    ]);
    $this->contentTemplate->setComponentTree([
      [
        'uuid' => '435d1d20-a697-4d36-9892-9d61c825c99c',
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => $components['sdc.canvas_test_sdc.my-cta']->getActiveVersion(),
        'inputs' => [
          'text' => 'This is really tricky for a first-timer',
          'href' => 'https://drupal.org',
        ],
      ],
      [
        'uuid' => '2d06782a-0f24-43ae-963c-b5aff807dd95',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => $components['sdc.canvas_test_sdc.props-no-slots']->getActiveVersion(),
        'inputs' => [
          'heading' => [
            'sourceType' => PropSource::EntityField->value,
            'expression' => 'ℹ︎␜entity:node:article␝field_test␞␟value',
          ],
        ],
      ],
    ]);
    self::assertEntityIsValid($this->contentTemplate);
    $this->contentTemplate->save();

    $this->pageRegion = PageRegion::create([
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'component_tree' => [],
      'status' => TRUE,
    ]);
    $this->pageRegion->setComponentTree([
      [
        'uuid' => 'cd44b595-9f3f-47d2-ae7d-621dcec7f621',
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => $components['sdc.canvas_test_sdc.my-cta']->getActiveVersion(),
        'inputs' => [
          'text' => 'This is really tricky for a first-timer',
          'href' => 'https://drupal.org',
        ],
      ],
      [
        'uuid' => 'ff376f2e-4eb8-4a69-8e1e-7d5fbe64e518',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => $components['sdc.canvas_test_sdc.props-no-slots']->getActiveVersion(),
        'inputs' => [
          'heading' => 'A heading',
        ],
      ],
    ]);
    self::assertEntityIsValid($this->pageRegion);
    $this->pageRegion->save();

    $this->page = Page::create([
      'title' => 'Page with components',
      'path' => '/components-page',
      'status' => TRUE,
      'components' => [
        [
          'uuid' => '4c3482ac-4635-4ba9-aaf4-eb86892d77a1',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $components['sdc.canvas_test_sdc.heading']->getActiveVersion(),
          'inputs' => [
            'text' => 'My custom header',
            'style' => 'secondary',
            'element' => 'h3',
          ],
        ],
        [
          'uuid' => '834fc6b0-7abd-48c7-888e-93b0a7f2526c',
          'component_id' => 'sdc.canvas_test_sdc.card',
          'component_version' => $components['sdc.canvas_test_sdc.card']->getActiveVersion(),
          'inputs' => [
            'heading' => 'Test Card',
            'content' => 'Test content',
            'footer' => 'Test Card Footer',
            'loading' => 'lazy',
            'image' => [
              'target_id' => 1,
            ],
          ],
        ],
      ],
      'owner' => $this->user->id(),
    ]);
    self::assertEntityIsValid($this->page);
    $this->page->save();
  }

  /**
   * Tests JSON:API pages serialization.
   *
   * Includes resolved inputs.
   */
  public function testPage(): void {
    $components = Component::loadMultiple();
    $resource_type_repository = $this->container->get('jsonapi.resource_type.repository');
    \assert($resource_type_repository instanceof ResourceTypeRepository);
    $resource_type = $resource_type_repository->get(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    \assert($resource_type instanceof ResourceType);
    $context = [
      'account' => $this->user,
      'resource_object' => ResourceObject::createFromEntity($resource_type, $this->page),
    ];

    $value = $this->container->get('jsonapi.serializer')->normalize($this->page->components, 'api_json', $context);
    $this->assertInstanceOf(CacheableNormalization::class, $value);

    $this->assertSame([
        [
          'parent_uuid' => NULL,
          'slot' => NULL,
          'uuid' => '4c3482ac-4635-4ba9-aaf4-eb86892d77a1',
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => $components['sdc.canvas_test_sdc.heading']->getActiveVersion(),
          'inputs' => '{"text":"My custom header","style":"secondary","element":"h3"}',
          'label' => NULL,
          'inputs_resolved' => [
            'text' => 'My custom header',
            'style' => 'secondary',
            'element' => 'h3',
          ],
        ],
        [
          'parent_uuid' => NULL,
          'slot' => NULL,
          'uuid' => '834fc6b0-7abd-48c7-888e-93b0a7f2526c',
          'component_id' => 'sdc.canvas_test_sdc.card',
          'component_version' => $components['sdc.canvas_test_sdc.card']->getActiveVersion(),
          'inputs' => '{"heading":"Test Card","content":"Test content","footer":"Test Card Footer","loading":"lazy","image":{"target_id":1}}',
          'label' => NULL,
          'inputs_resolved' => [
            'heading' => 'Test Card',
            'content' => 'Test content',
            'footer' => 'Test Card Footer',
            'loading' => 'lazy',
            'image' => [
              'src' => '/' . $this->siteDirectory . '/files/image-test.png?alternateWidths=/' . ($this->siteDirectory) . '/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/image-test.png.avif%3Fitok%3DujSynxBM',
              'alt' => '',
              'width' => 40,
              'height' => 20,
            ],
          ],
        ],
    ], $value->getNormalization());
  }

  /**
   * Tests JSON:API content template serialization.
   *
   * TRICKY: This won't include `inputs_resolved` in the component tree, as
   * content entities use \Drupal\jsonapi\Normalizer\FieldNormalizer, while
   * config entities use \Drupal\jsonapi\Serializer\Serializer using the
   * config schema definition.
   */
  public function testContentTemplate(): void {
    $components = Component::loadMultiple();
    $resource_type_repository = $this->container->get('jsonapi.resource_type.repository');
    \assert($resource_type_repository instanceof ResourceTypeRepository);
    $resource_type = $resource_type_repository->get(ContentTemplate::ENTITY_TYPE_ID, ContentTemplate::ENTITY_TYPE_ID);
    \assert($resource_type instanceof ResourceType);
    $context = [
      'account' => $this->user,
      'resource_object' => ResourceObject::createFromEntity($resource_type, $this->contentTemplate),
    ];

    // This uses the protected property instead of getComponentTree() because
    // that's what the normalization will do: this is barely serializing the
    // config entity using the structure described by config schema in
    // type: canvas.content_template.*.*.*. That's why the return will be an
    // array, and not a CacheableNormalization object as the content entity
    // field normalization would return.
    // @phpstan-ignore-next-line property.protected
    $value = $this->container->get('jsonapi.serializer')->normalize($this->contentTemplate->component_tree, 'api_json', $context);
    // This is not a CacheableNormalization, but an array.
    $this->assertSame([
      [
        'uuid' => '435d1d20-a697-4d36-9892-9d61c825c99c',
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => $components['sdc.canvas_test_sdc.my-cta']->getActiveVersion(),
        'inputs' => [
          'text' => 'This is really tricky for a first-timer',
          'href' => 'https://drupal.org',
        ],
      ],
      [
        'uuid' => '2d06782a-0f24-43ae-963c-b5aff807dd95',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => $components['sdc.canvas_test_sdc.props-no-slots']->getActiveVersion(),
        'inputs' => [
          'heading' => [
            'sourceType' => PropSource::EntityField->value,
            'expression' => 'ℹ︎␜entity:node:article␝field_test␞␟value',
          ],
        ],
      ],
    ], $value);
  }

  /**
   * Tests JSON:API page region serialization.
   *
   * TRICKY: This won't include `inputs_resolved` in the component tree, as
   * content entities use \Drupal\jsonapi\Normalizer\FieldNormalizer, while
   * config entities use \Drupal\jsonapi\Serializer\Serializer using the
   * config schema definition.
   */
  public function testPageRegion(): void {
    $components = Component::loadMultiple();
    $resource_type_repository = $this->container->get('jsonapi.resource_type.repository');
    \assert($resource_type_repository instanceof ResourceTypeRepository);
    $resource_type = $resource_type_repository->get(PageRegion::ENTITY_TYPE_ID, PageRegion::ENTITY_TYPE_ID);
    \assert($resource_type instanceof ResourceType);
    $context = [
      'account' => $this->user,
      'resource_object' => ResourceObject::createFromEntity($resource_type, $this->pageRegion),
    ];

    // This uses the protected property instead of getComponentTree() because
    // that's what the normalization will do: this is barely serializing the
    // config entity using the structure described by config schema in
    // type: canvas.page_region.*. That's why the return will be an
    // array, and not a CacheableNormalization object as the content entity
    // field normalization would return.
    // @phpstan-ignore-next-line property.protected
    $value = $this->container->get('jsonapi.serializer')->normalize($this->pageRegion->component_tree, 'api_json', $context);
    // This is not a CacheableNormalization, but an array.
    $this->assertSame([
      [
        'uuid' => 'cd44b595-9f3f-47d2-ae7d-621dcec7f621',
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => $components['sdc.canvas_test_sdc.my-cta']->getActiveVersion(),
        'inputs' => [
          'text' => 'This is really tricky for a first-timer',
          'href' => 'https://drupal.org',
        ],
      ],
      [
        'uuid' => 'ff376f2e-4eb8-4a69-8e1e-7d5fbe64e518',
        'component_id' => 'sdc.canvas_test_sdc.props-no-slots',
        'component_version' => $components['sdc.canvas_test_sdc.props-no-slots']->getActiveVersion(),
        'inputs' => [
          'heading' => 'A heading',
        ],
      ],
    ], $value);
  }

}
