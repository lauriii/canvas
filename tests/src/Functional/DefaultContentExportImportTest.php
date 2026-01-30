<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropShape\PersistentPropShapeRepository;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\EventSubscriber\DefaultContentSubscriber;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\DefaultContent\InvalidEntityException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepository;
use Drupal\file\Entity\File;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\media\Entity\Media;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[Group('canvas')]
#[Group('default_content_api')]
#[CoversClass(DefaultContentSubscriber::class)]
final class DefaultContentExportImportTest extends BrowserTestBase {

  use MediaTypeCreationTrait;
  use RecipeTestTrait;
  use GenerateComponentConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'media',
    'media_library',
    'canvas_test_sdc',
    'canvas',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!class_exists(Exporter::class)) {
      $this->markTestSkipped('This test requires Drupal 11.3 or later.');
    }
    parent::setUp();
  }

  public function testCanvasPage(): void {
    $entityRepository = $this->container->get('entity.repository');
    self::assertInstanceOf(EntityRepository::class, $entityRepository);
    $original_user = $this->createUser(['access content']);
    self::assertInstanceOf(UserInterface::class, $original_user);
    $this->drupalLogin($original_user);
    $image_uri = $this->getRandomGenerator()
      ->image(uniqid('public://') . '.png', '200x200', '400x400');
    self::assertFileExists($image_uri);
    $original_media_referenced_file = File::create(['uri' => $image_uri]);
    $original_media_referenced_file->save();

    $image_uri = $this->getRandomGenerator()
      ->image(uniqid('public://') . '.png', '200x200', '400x400');
    self::assertFileExists($image_uri);
    $original_directly_referenced_file = File::create(['uri' => $image_uri]);
    $original_directly_referenced_file->save();

    $image_uri = $this->getRandomGenerator()
      ->image(uniqid('public://') . '.png', '200x200', '400x400');
    self::assertFileExists($image_uri);
    $unreferenced_file = File::create(['uri' => $image_uri]);
    $unreferenced_file->save();

    $original_media = Media::create([
      'bundle' => $this->createMediaType('image')->id(),
      'name' => 'Test image',
      'field_media_image' => $original_media_referenced_file,
    ]);
    $original_media->save();

    /** @var \Drupal\canvas\PropShape\PropShapeRepositoryInterface $propShapeRepository */
    $propShapeRepository = \Drupal::service(PropShapeRepositoryInterface::class);
    self::assertInstanceOf(PersistentPropShapeRepository::class, $propShapeRepository);
    // Trigger a cache-write in PropShapeRepository - this happens on kernel
    // shutdown normally, but in a test we need to call it manually. This is
    // necessary to update all cached prop shapes.
    $propShapeRepository->destruct();
    // But that would trigger an update to Component entities that are affected
    // by any changes to the prop shapes, which in turn can affect what
    // information the default content subscriber can get from the component
    // instances it exports, including information about dependencies.

    // That's not happening here because we don't have separate requests, so we
    // force it manually. We also know that only the Component entity for the SDC
    // `canvas_test_sdc:image` is affected, amongst the component entities we
    // care about.
    $this->container->get(ComponentSourceManager::class)->generateComponents('sdc', ['canvas_test_sdc:image']);

    $saved_component_values = [
      'machineName' => 'hey_there',
      'name' => 'Hey there',
      'status' => TRUE,
      'props' => [
        'name' => [
          'type' => 'string',
          'title' => 'Name',
          'examples' => ['Gracie'],
        ],
        'breed' => [
          'type' => 'string',
          'title' => 'Name',
          'examples' => ['Mut', 'Poodle'],
        ],
      ],
      'slots' => [],
      'js' => [
        'original' => 'console.log("Hey there")',
        'compiled' => 'console.log("Hey there")',
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
      'dataDependencies' => [],
    ];
    $code_component = JavaScriptComponent::create($saved_component_values);
    $code_component->save();
    $js_component = Component::load(JsComponent::componentIdFromJavascriptComponentId((string) $code_component->id()));
    self::assertInstanceOf(Component::class, $js_component);
    $original_js_component_active_version = $js_component->getActiveVersion();

    $head_sdc_uuid = '345a2aa9-7b6d-446b-80cf-39f110bade1d';
    $image_sdc_uuid = 'c990c4ee-341a-4f38-ab5d-e75b3de1fa1f';
    $local_file_sdc_uuid = '75144f9b-1bfc-4874-b848-b5889f066217';
    $js_component_uuid = '8b9c4e63-e3e2-4969-8f1f-7cb764e0e19f';
    $block_component_uuid = 'ce05b065-00e7-43c4-8808-ac757de1c98a';

    $original_page = Page::create([
      'title' => 'Export this page',
      'components' => [
        // Simple heading text; self-contained and doesn't reference anything.
        [
          'uuid' => $head_sdc_uuid,
          'component_id' => 'sdc.canvas_test_sdc.heading',
          'component_version' => Component::load('sdc.canvas_test_sdc.heading')?->getActiveVersion(),
          'inputs' => [
            // Explicitly store this prop in non-collapsed format which will
            // cause a violation error but not cause as error on saving. We will
            // confirm that content export actually exports the input in the
            // collapsed format and the re-imported page will validate.
            'text' => [
              'sourceType' => 'static:field_item:string',
              'value' => 'I lead with this',
              'expression' => 'ℹ︎string␟value',
            ],
            'style' => 'primary',
            'element' => 'h1',
          ],
        ],
        // An image: references a media entity.
        [
          'uuid' => $image_sdc_uuid,
          'component_id' => 'sdc.canvas_test_sdc.image',
          'component_version' => Component::load('sdc.canvas_test_sdc.image')?->getActiveVersion(),
          'inputs' => [
            'image' => [
              'target_id' => $original_media->id(),
            ],
          ],
        ],
        // A code component instance.
        [
          'uuid' => $js_component_uuid,
          'component_id' => $js_component->id(),
          'component_version' => $original_js_component_active_version,
          'inputs' => [
            'name' => 'Gracie',
          ],
        ],
        // An image: references a file entity.
        [
          'uuid' => $local_file_sdc_uuid,
          'component_id' => 'sdc.canvas_test_sdc.card-with-local-image',
          'component_version' => Component::load('sdc.canvas_test_sdc.card-with-local-image')?->getActiveVersion(),
          'inputs' => [
            'src' => [
              'target_id' => $original_directly_referenced_file->id(),
            ],
            'alt' => 'This is file 2',
            'loading' => 'lazy',
          ],
        ],
        // A block component instance, with a few configuration options beyond
        // the defaults.
        [
          'uuid' => $block_component_uuid,
          'component_id' => 'block.system_menu_block.main',
          'component_version' => Component::load('block.system_menu_block.main')?->getActiveVersion(),
          'inputs' => [
            'label' => '',
            'label_display' => '0',
            'level' => 1,
            'depth' => 3,
            'expand_all_items' => TRUE,
          ],
        ],
      ],
    ]);
    $violations = $original_page->validate();
    self::assertCount(1, $violations);
    self::assertEquals($violations->get(0)->getMessage(), 'When using the default static prop source for a component input, you must use the collapsed input syntax.');
    self::assertEquals($violations->get(0)->getPropertyPath(), "components.0.inputs.$head_sdc_uuid");
    $original_page->save();
    self::assertPageReferencesTargetEntity($original_page, $original_media, $image_sdc_uuid, 'image');
    self::assertPageReferencesTargetEntity($original_page, $original_directly_referenced_file, $local_file_sdc_uuid, 'src');

    $content_export_dir = $this->tempFilesDirectory . '/temp-content';
    self::assertTrue(mkdir($content_export_dir));

    // Save all the UUIDs because they should be the same after importing.
    $page_uuid = $original_page->uuid();
    $media_uuid = $original_media->uuid();
    $directly_referenced_file_uuid = $original_directly_referenced_file->uuid();
    $unreferenced_file_uuid = $unreferenced_file->uuid();
    self::assertNotNull($unreferenced_file_uuid);

    // This ignore can be removed when we require Drupal 11.3 or later.
    // @phpstan-ignore-next-line
    $process = $this->runDrupalCommand([
      'content:export', 'canvas_page',
      $original_page->id(),
      "--dir=$content_export_dir",
      '--with-dependencies',
    ]);
    // The export should succeed.
    self::assertSame(0, $process->wait(), $process->getOutput());

    // After exporting delete the page and all its dependencies.
    $unreferenced_file->delete();

    $finder = new Finder($content_export_dir);
    $imported_entities = $this->deleteAndImportContent(
      $finder,
      [
        $original_media_referenced_file,
        $original_directly_referenced_file,
        $original_user,
        $original_page,
        $original_media,
      ],
    );

    // Ensure unreferenced file still does not exist.
    self::assertNull($entityRepository->loadEntityByUuid('file', $unreferenced_file_uuid));

    self::assertIsArray($finder->data[$page_uuid]['default']['components'][0]);
    $heading_component_instance = $finder->data[$page_uuid]['default']['components'][0];
    self::assertIsArray($heading_component_instance['inputs']);
    self::assertArrayHasKey('text', $heading_component_instance['inputs']);

    // The exported input that was saved in the uncollapsed format should have
    // been exported in the collapsed format.
    self::assertEquals('I lead with this', $heading_component_instance['inputs']['text']);
    \assert($imported_entities[$page_uuid] instanceof Page);
    self::assertCount(0, $imported_entities[$page_uuid]->validate());

    // Ensure the imported entities has a different id than the original
    // entities and the imported page now references the imported entities.
    self::assertPageReferencesTargetEntity($imported_entities[$page_uuid], $imported_entities[$media_uuid], $image_sdc_uuid, 'image');
    self::assertPageReferencesTargetEntity($imported_entities[$page_uuid], $imported_entities[$directly_referenced_file_uuid], $local_file_sdc_uuid, 'src');

    // Update the code component to make the 'name' property required. This
    // should change the active version, but the previous export should still be
    // valid because this input was set in the code component instance.
    $code_component->set('required', ['name']);
    $code_component->save();
    $js_component = Component::load(JsComponent::componentIdFromJavascriptComponentId((string) $code_component->id()));
    self::assertInstanceOf(Component::class, $js_component);
    $js_component_active_version_1 = $js_component->getActiveVersion();
    self::assertNotEquals($original_js_component_active_version, $js_component_active_version_1);
    $this->deleteAndImportContent(new Finder($content_export_dir), $imported_entities);

    // Update the code component to make the 'breed' property also required. This
    // should make the previous export invalid because this input was not set.
    $code_component->set('required', ['name', 'breed']);
    $code_component->save();
    $js_component = Component::load(JsComponent::componentIdFromJavascriptComponentId((string) $code_component->id()));
    self::assertInstanceOf(Component::class, $js_component);
    $js_component_active_version_2 = $js_component->getActiveVersion();
    self::assertNotEquals($original_js_component_active_version, $js_component_active_version_2);
    self::assertNotEquals($js_component_active_version_1, $js_component_active_version_2);
    $this->expectException(InvalidEntityException::class);
    $this->expectExceptionMessageMatches('/components\.2\.inputs\.' . preg_quote($js_component_uuid, '/') . '\.breed=The property breed is required\.$/');
    $this->deleteAndImportContent(new Finder($content_export_dir), $imported_entities);
  }

  private static function assertPageReferencesTargetEntity(Page $page, EntityInterface $target_entity, string $instance_uuid, string $input_name): void {
    $item = $page->getComponentTree()->getComponentTreeItemByUuid($instance_uuid);
    self::assertInstanceOf(ComponentTreeItem::class, $item);
    $inputs = $item->getInputs();
    self::assertIsArray($inputs);
    self::assertArrayHasKey($input_name, $inputs);
    self::assertIsArray($inputs[$input_name]);
    self::assertArrayHasKey('target_id', $inputs[$input_name]);
    self::assertEquals((string) $target_entity->id(), (string) $inputs[$input_name]['target_id']);
  }

  private function deleteAndImportContent(Finder $finder, array $expected_entities): array {
    $entityRepository = $this->container->get('entity.repository');
    // Delete all the entities.
    $exported_entity_info = [];
    foreach ($expected_entities as $entity) {
      \assert($entity instanceof EntityInterface);
      $entity_type = $entity->getEntityTypeId();
      $uuid = $entity->uuid();
      self::assertIsString($uuid);
      $exported_entity_info[] = [
        'entity_type' => $entity_type,
        'uuid' => $uuid,
        'id' => $entity->id(),
      ];
      // Load all the entities of this type.
      $entity = $entityRepository->loadEntityByUuid($entity_type, $uuid);
      self::assertNotNull($entity, "Entity $entity_type, $uuid should exist.");
      $entity->delete();
    }

    // Ensure all the entities were exported.
    $all_uuids = array_column($exported_entity_info, 'uuid');
    // While doing that, generate debug strings, as this has been hard to debug
    // when failing. So we can check (UUID, entity type) pairs on test output.
    $all_uuids_debug_string = var_export(array_combine($all_uuids, array_column($exported_entity_info, 'entity_type')), TRUE);
    sort($all_uuids);
    $actual_export_uuids = array_keys($finder->data);
    $actual_export_uuids_debug_string = var_export(array_combine(array_keys($finder->data), array_map(fn($data) => $data['_meta']['entity_type'], $finder->data)), TRUE);
    sort($actual_export_uuids);
    self::assertEquals($all_uuids, $actual_export_uuids, $all_uuids_debug_string . ' vs ' . $actual_export_uuids_debug_string);

    // Re-import the content we just deleted.
    $this->container->get(Importer::class)->importContent($finder);

    $imported_entities = [];
    foreach ($exported_entity_info as $entity_info) {
      $entity = $entityRepository->loadEntityByUuid($entity_info['entity_type'], $entity_info['uuid']);
      self::assertNotNull($entity, \sprintf("Entity %s, %s should exist after import.", $entity_info['entity_type'], $entity_info['uuid']));
      self::assertNotEquals((string) $entity_info['id'], $entity->id());
      $imported_entities[$entity->uuid()] = $entity;
    }
    return $imported_entities;
  }

}
