<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\search_api\processor;

// cspell:ignore Página inicio luminoso

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\search_api\processor\ComponentTreeInputs;
use Drupal\Component\Uuid\Php;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\Item;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\PageTrait;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[Group('canvas')]
#[CoversClass(ComponentTreeInputs::class)]
#[RunTestsInSeparateProcesses]
final class ComponentTreeInputsTest extends CanvasKernelTestBase {

  use ContentTranslationTestTrait;
  use PageTrait;
  use RequestTrait;
  use UserCreationTrait;

  private const string INDEX_ID = 'cms_content';

  private const string INDEX_FIELD_ID = 'canvas_component_tree_inputs_fulltext';

  protected static $modules = [
    'content_translation',
    'language',
    'search_api',
    'search_api_db',
    'search_api_test',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installPageEntitySchema();
    $this->installConfig(['search_api']);
    $this->installEntitySchema('search_api_task');

    ConfigurableLanguage::createFromLangcode('es')->save();

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
      ->generateComponents(SingleDirectoryComponent::SOURCE_PLUGIN_ID, [
        'canvas_test_sdc:props-slots',
        'canvas_test_sdc:date',
      ]);
  }

  /**
   * Attaches and enables a search_api_db server on the index.
   */
  private static function attachDbServer(IndexInterface $index): void {
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
  }

  /**
   * Runs a fulltext query, optionally scoped to languages, and returns items.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index to query.
   * @param string $keys
   *   The fulltext term to search for.
   * @param array<string>|null $languages
   *   The languages to restrict the query to, or NULL for all.
   *
   * @return array<string, \Drupal\search_api\Item\ItemInterface>
   *   The result items, keyed by item id.
   */
  private static function query(IndexInterface $index, string $keys, ?array $languages = NULL): array {
    $query = $index->query();
    $query->keys($keys);
    if ($languages !== NULL) {
      $query->setLanguages($languages);
    }
    return $query->execute()->getResultItems();
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
    $index = self::getIndex();
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

    $index = self::getIndex();
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

    $uuid = (new Php())->generate();
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
    $index = self::getIndex();
    $this->attachFieldToIndex($index);
    self::attachDbServer($index);

    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'Welcome to our site with a cool meta description',
      'path' => ['alias' => '/homepage'],
      'components' => [
        [
          'uuid' => \Drupal::service(UuidInterface::class)->generate(),
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Welcome to the site!',
          ],
        ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);
    $this->container->get('search_api.post_request_indexing')->destruct();

    self::assertCount(0, self::query($index, 'Homepage'));
    self::assertCount(1, self::query($index, 'site'));
  }

  /**
   * Each translation is indexed as its own item with its own string props.
   */
  public function testTranslationsIndexedAsSeparateItems(): void {
    $index = self::getIndex();
    $this->attachFieldToIndex($index);
    self::attachDbServer($index);

    $page = $this->createTranslatedPage();

    self::assertSame(
      ["entity:canvas_page/{$page->id()}:en"],
      \array_keys(self::query($index, 'Lighthouse')),
      'The en-only term resolves to the en item only.',
    );
    self::assertSame(
      ["entity:canvas_page/{$page->id()}:es"],
      \array_keys(self::query($index, 'Faro')),
      'The es-only term resolves to the es item only.',
    );
  }

  /**
   * A language-scoped query returns only the matching translation.
   */
  public function testLanguageFilteredQuery(): void {
    $index = self::getIndex();
    $this->attachFieldToIndex($index);
    self::attachDbServer($index);

    $page = $this->createTranslatedPage();

    // The en term restricted to es, and vice versa, find nothing — no bleed.
    self::assertCount(0, self::query($index, 'Lighthouse', ['es']));
    self::assertCount(0, self::query($index, 'Faro', ['en']));

    // Each term restricted to its own language still resolves to its item.
    self::assertSame(
      ["entity:canvas_page/{$page->id()}:en"],
      \array_keys(self::query($index, 'Lighthouse', ['en'])),
    );
    self::assertSame(
      ["entity:canvas_page/{$page->id()}:es"],
      \array_keys(self::query($index, 'Faro', ['es'])),
    );
  }

  /**
   * Symmetric translation: a synced non-translatable prop is indexed per-language.
   *
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer
   */
  public function testSymmetricTranslationIndexing(): void {
    $this->setUpSymmetricalTranslation();

    $index = self::getIndex();
    $this->attachFieldToIndex($index);
    self::attachDbServer($index);

    $uuid = \Drupal::service(UuidInterface::class)->generate();
    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'Homepage description',
      'path' => ['alias' => '/homepage'],
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'sdc.canvas_test_sdc.date',
          'inputs' => [
            // Translatable prop (caption) different between translations.
            'caption' => 'Lighthouse beacon',
            // Non-translatable (date); synced to non-default translations.
            'date' => '2026-10-15',
          ],
        ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);

    $en_list = $page->get('components');
    \assert($en_list instanceof ComponentTreeItemList);
    self::assertSame(
      ['caption'],
      $en_list->getComponentTreeItemByUuid($uuid)?->get('inputs')->getTranslatableInputKeys(),
    );

    $page->addTranslation('es', [
      'title' => 'Página de inicio',
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'sdc.canvas_test_sdc.date',
          'inputs' => [
            'caption' => 'Faro luminoso',
          ],
        ],
      ],
    ]);
    $page->save();
    $this->container->get('search_api.post_request_indexing')->destruct();

    // Assert translatable props (caption) are indexed translated
    self::assertSame(
      ["entity:canvas_page/{$page->id()}:en"],
      \array_keys(self::query($index, 'Lighthouse')),
      'The en translatable prop resolves to the en item only.',
    );
    self::assertSame(
      ["entity:canvas_page/{$page->id()}:es"],
      \array_keys(self::query($index, 'Faro')),
      'The es translatable prop resolves to the es item only.',
    );

    // Assert non-translatable props (date) are indexed and return the value
    // in both languages.
    self::assertSame(
      ["entity:canvas_page/{$page->id()}:es"],
      \array_keys(self::query($index, '2026', ['es'])),
      'The synced non-translatable date is indexed for the es translation.',
    );
    self::assertSame(
      ["entity:canvas_page/{$page->id()}:en"],
      \array_keys(self::query($index, '2026', ['en'])),
      'The synced non-translatable date is indexed for the en translation.',
    );
  }

  public function testCustomIgnoredPropNames(): void {
    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'Test page for custom ignored props',
      'path' => ['alias' => '/test-custom-ignored'],
      'components' => [
        [
          'uuid' => \Drupal::service(UuidInterface::class)->generate(),
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => [
            'heading' => 'Component Heading Content',
          ],
        ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);

    $index = self::getIndex();
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

  private static function getIndex(): IndexInterface {
    $index = Index::load(self::INDEX_ID);
    self::assertInstanceOf(IndexInterface::class, $index);
    return $index;
  }

  /**
   * Creates a saved page with an en heading and an es translation heading.
   */
  private function createTranslatedPage(): Page {
    $uuid = \Drupal::service(UuidInterface::class)->generate();
    $page = Page::create([
      'title' => 'Homepage',
      'description' => 'Welcome to our site with a cool meta description',
      'path' => ['alias' => '/homepage'],
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => ['heading' => 'Lighthouse beacon'],
        ],
      ],
    ]);
    self::assertSaveWithoutViolations($page);

    // The es translation stores its own component tree; fields not set here
    // (no fallback) are simply empty for that translation.
    $page->addTranslation('es', [
      'title' => 'Página de inicio',
      'components' => [
        [
          'uuid' => $uuid,
          'component' => 'sdc.canvas_test_sdc.props-slots',
          'inputs' => ['heading' => 'Faro luminoso'],
        ],
      ],
    ]);
    $page->save();

    $this->container->get('search_api.post_request_indexing')->destruct();
    return $page;
  }

  /**
   * Enables symmetric content translation on the canvas_page components field.
   *
   * The canvas_page `components` base field is already translatable; this adds a
   * BaseFieldOverride carrying the `translation_sync` setting that
   * FieldTranslationSynchronizer::getFieldSynchronizationSettings() reads
   * (tree synced, inputs translatable), which puts the field in symmetric mode.
   *
   * @see \Drupal\Tests\canvas\Kernel\Translation\ComponentTreeFieldSymmetricalTranslationSynchronizerTest::setUpSymmetricalContentTranslation()
   */
  private function setUpSymmetricalTranslation(): void {
    $this->enableContentTranslation(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    $base_field_definition = $this->container->get('entity_field.manager')
      ->getBaseFieldDefinitions(Page::ENTITY_TYPE_ID)['components'];
    \assert($base_field_definition instanceof BaseFieldDefinition);
    $override = BaseFieldOverride::createFromBaseFieldDefinition(
      $base_field_definition,
      Page::ENTITY_TYPE_ID,
    );
    $override->setThirdPartySetting('content_translation', 'translation_sync', [
      'inputs' => 'inputs',
      'tree' => 0,
    ]);
    self::assertEntityIsValid($override);
    $override->save();
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
