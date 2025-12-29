<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\search_api\processor;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\Component\Uuid\Php as UuidGenerator;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\search_api\processor\ComponentTreeInputs;
use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\Item;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('canvas')]
#[CoversClass(ComponentTreeInputs::class)]
final class ComponentTreeInputsTest extends KernelTestBase {

  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const string INDEX_ID = 'cms_content';

  private const string INDEX_FIELD_ID = 'canvas_component_tree_inputs_fulltext';

  protected static $modules = [
    'file',
    'canvas',
    'canvas_test_sdc',
    'filter',
    'text',
    'image',
    'link',
    'media',
    'media_library',
    'options',
    'path',
    'path_alias',
    'search_api',
    'search_api_db',
    'search_api_test',
    'system',
    'user',
    'views',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installPageEntitySchema();
    $this->installConfig(['search_api']);
    $this->installEntitySchema('search_api_task');

    Index::create([
      'id' => self::INDEX_ID,
      'name' => 'Page index',
      'tracker_settings' => [
        'default' => [],
      ],
      'datasource_settings' => [
        'entity:canvas_page' => [],
      ],
      'options' => ['index_directly' => TRUE],
    ])->save();

    $this->container->get(ComponentSourceManager::class)
      ->generateComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, ['canvas_test_sdc:props-slots']);
  }

  public function testNoProcessorPropertyForIndexWithoutPages(): void {
    $index = Index::create([
      'id' => 'some_index',
      'name' => 'Some index',
      'tracker_settings' => [
        'default' => [],
      ],
      'datasource_settings' => [
        'entity:media' => [],
      ],
      'options' => ['index_directly' => TRUE],
    ]);
    $index->save();
    $this->attachFieldToIndex($index);
    self::assertNull($index->getField(self::INDEX_FIELD_ID), 'Field was not added to the index.');
  }

  /**
   * Explicitly test that the processor field can be added to the index.
   */
  public function testProcessorFieldCanBeAddedToIndex(): void {
    $index = $this->getIndex();
    $this->attachFieldToIndex($index);
    self::assertNotNull($index->getField(self::INDEX_FIELD_ID), 'Field was added to the index.');
  }

  #[DataProvider('componentsAndInputs')]
  public function testExtractedInputs(array $components, array $expected_inputs): void {
    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'Welcome to our site with a cool meta description',
      'path' => ['alias' => '/homepage'],
      'components' => $components,
    ]);
    self::assertSaveWithoutViolations($page);

    $index = $this->getIndex();
    $this->attachFieldToIndex($index);

    $index_item = new Item($index, "entity:canvas_page/{$page->id()}",);
    $index_item->setOriginalObject(EntityAdapter::createFromEntity($page));
    $index_item->setField(self::INDEX_FIELD_ID, $index->getField(self::INDEX_FIELD_ID));

    $processor = $this->container
      ->get('search_api.plugin_helper')
      ->createProcessorPlugin($index, 'canvas_component_tree_inputs');
    $processor->addFieldValues($index_item);

    $field = $index->getField(self::INDEX_FIELD_ID);
    self::assertEquals($expected_inputs, $field?->getValues());
  }

  public static function componentsAndInputs(): iterable {
    yield 'empty' => [
      'components' => [],
      'expected_inputs' => [],
    ];

    $uuid = (new UuidGenerator())->generate();
    yield 'canvas_test_sdc.props-slots' => [
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Welcome to the site!',
          ],
        ],
      ],
      'expected_inputs' => [
        'Welcome to the site!',
      ],
    ];
  }

  public function testWithQuery(): void {
    $index = $this->getIndex();
    $this->attachFieldToIndex($index);
    $server = Server::create([
      'name' => 'Test server',
      'id' => 'test',
      'status' => 1,
      'backend' => 'search_api_db',
      'backend_config' => [
        'min_chars' => 3,
        'database' => 'default:default',
      ],
    ]);
    $server->save();
    $index->setServer($server);
    $index->enable();
    $index->save();

    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'Welcome to our site with a cool meta description',
      'path' => ['alias' => '/homepage'],
      'components' => [
        [
          'uuid' => (new UuidGenerator())->generate(),
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Welcome to the site!',
          ],
        ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);
    $this->container->get('search_api.post_request_indexing')->destruct();

    $query = $index->query();
    $query->keys('Homepage');
    self::assertCount(0, $query->execute()->getResultItems());

    $query = $index->query();
    $query->keys('site');
    self::assertCount(1, $query->execute()->getResultItems());
  }

  public function testCustomIgnoredPropNames(): void {
    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'Test page for custom ignored props',
      'path' => ['alias' => '/test-custom-ignored'],
      'components' => [
        [
          'uuid' => (new UuidGenerator())->generate(),
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Component Heading Content',
          ],
        ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);

    $index = $this->getIndex();
    $this->attachFieldToIndex($index);

    $index_item = new Item($index, "entity:canvas_page/{$page->id()}");
    $index_item->setOriginalObject(EntityAdapter::createFromEntity($page));
    $index_item->setField(self::INDEX_FIELD_ID, $index->getField(self::INDEX_FIELD_ID));

    // Test with custom configuration that ignores 'heading'
    $custom_config = ['ignored_prop_names' => ['heading', 'id', 'class', 'cssClasses', 'extraClasses']];
    $processor = $this->container
      ->get('search_api.plugin_helper')
      ->createProcessorPlugin($index, 'canvas_component_tree_inputs', $custom_config);
    $processor->addFieldValues($index_item);

    $field = $index->getField(self::INDEX_FIELD_ID);
    $values = $field?->getValues() ?? [];

    // Should not contain 'heading' value since it's ignored
    self::assertNotContains('Component Heading Content', $values);
    self::assertEmpty($values, 'No values should be extracted when all string props are ignored');
  }

  private function getIndex(): IndexInterface {
    $index = Index::load(self::INDEX_ID);
    self::assertInstanceOf(IndexInterface::class, $index);
    return $index;
  }

  private function attachFieldToIndex(IndexInterface $index): void {
    $search_fields_helper = $this->container->get('search_api.fields_helper');
    $extractor_field = $search_fields_helper->createField($index, self::INDEX_FIELD_ID, [
      'label' => 'Component tree inputs',
      'property_path' => 'canvas_component_tree_inputs',
      'type' => 'text',
    ]);
    $index->addField($extractor_field);
    $index->save();
  }

}
