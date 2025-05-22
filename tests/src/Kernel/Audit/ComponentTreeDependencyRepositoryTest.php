<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Audit;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\experience_builder\Audit\ComponentTreeDependencyRepository;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Plugin\BlockManager;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;

/**
 * @coversDefaultClass \Drupal\experience_builder\Audit\ComponentTreeDependencyRepository
 * @group experience_builder
 */
class ComponentTreeDependencyRepositoryTest extends ComponentAuditTestBase {

  protected static $modules = [
    'xb_test_block',
    'path_alias',
    'language',
    'content_translation',
    'field',
    'entity_test',
    'xb_entity_test',
  ];

  protected ComponentInterface $component1;
  protected ComponentInterface $component2;
  protected ComponentInterface $component3;

  protected ComponentTreeDependencyRepository $dependencyRepository;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->dependencyRepository = $this->container->get(ComponentTreeDependencyRepository::class);
    $this->container->get(BlockManager::class)->getDefinitions();
    $component1 = Component::load('sdc.xb_test_sdc.props-slots');
    \assert($component1 instanceof ComponentInterface);
    $this->component1 = $component1;

    $component2 = Component::load('block.xb_test_block_input_none');
    \assert($component2 instanceof ComponentInterface);
    $this->component2 = $component2;

    $component3 = Component::load('sdc.xb_test_sdc.props-no-slots');
    \assert($component3 instanceof ComponentInterface);
    $this->component3 = $component3;

    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  protected function assertDependencyRecords(array $expected): void {
    // Use a closure to access data from the repository, this lets us proxy
    // access to the data via the repository without needing to expose test-only
    // methods.
    $records = \Closure::bind(function () {
      $query = $this->connection->select(self::TABLE_NAME, 't')
        ->orderBy(self::SOURCE_TYPE)
        ->orderBy(self::SOURCE_REVISION_ID)
        ->orderBy(self::SOURCE_REVISION_ID_STRING)
        ->condition(self::SOURCE_FIELD_NAME, 'field_xb_field')
        ->fields('t', [
          self::SOURCE_TYPE,
          self::SOURCE_DELTA,
          self::SOURCE_REVISION_ID,
          self::SOURCE_REVISION_ID_STRING,
          self::SOURCE_LANGCODE,
          self::DEPENDENCY_TYPE,
          self::DEPENDENCY_NAME,
        ]);
      $result = $this->executeQuery($query);
      \assert($result instanceof StatementInterface);
      $results = $result->fetchAll(\PDO::FETCH_ASSOC);
      $collated = [];
      foreach ($results as $record) {
        $id = \sprintf('%s:%s:%s', $record[self::SOURCE_TYPE], $record[self::SOURCE_REVISION_ID] !== '0' ? $record[self::SOURCE_REVISION_ID] : $record[self::SOURCE_REVISION_ID_STRING], $record[self::SOURCE_LANGCODE]);
        $collated = NestedArray::mergeDeep($collated, [$id => [$record[self::DEPENDENCY_TYPE] => [$record[self::DEPENDENCY_NAME]]]]);
      }
      return $collated;
    }, $this->dependencyRepository, $this->dependencyRepository)();
    // Avoid database-specific sorts ending up in test expectations.
    foreach ($records as &$dependencies) {
      foreach ($dependencies as &$deps) {
        sort($deps);
        $deps = \array_unique($deps);
      }
    }
    self::assertEquals($expected, $records);
  }

  private function setNewRevision(ContentEntityInterface $entity): void {
    $entity->setNewRevision();
    if ($entity->getEntityTypeId() === 'entity_test_mulrev') {
      // String revision IDs need to be set.
      // @see \Drupal\xb_entity_test\Hook\EntityTestHooks::entityBaseFieldInfoAlter
      $revision_field = $entity->getEntityType()->getKey('revision');
      \assert(\is_string($revision_field));
      $entity->set($revision_field, $this->container->get(UuidInterface::class)->generate());
    }
  }

  private static function entityRevisionIdentifier(ContentEntityInterface $entity): string {
    return \sprintf('%s:%s:%s', $entity->getEntityTypeId(), $entity->getRevisionId(), $entity->language()->getId());
  }

  public static function providerEntityTypes(): iterable {
    yield Page::ENTITY_TYPE_ID => [Page::ENTITY_TYPE_ID];
    yield 'entity_test_string_id' => ['entity_test_string_id'];
    yield 'entity_test' => ['entity_test'];
    yield 'entity_test_rev' => ['entity_test_rev'];
    // This has a string revision ID.
    // @see \Drupal\xb_entity_test\Hook\EntityTestHooks::entityBaseFieldInfoAlter
    yield 'entity_test_mulrev' => ['entity_test_mulrev'];
  }

  private function buildTestEntity(string $entity_type_id, ?string $bundle): ContentEntityInterface {
    if (\str_starts_with($entity_type_id, 'entity_test')) {
      // We only do this for entity_test entity types. The xb_page entity type
      // is installed in the base class.
      $this->installEntitySchema($entity_type_id);
    }
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $storage = $entity_type_manager->getStorage($entity_type_id);
    $this->createXbField($entity_type_id, $bundle ?? $entity_type_id);
    $entity_type = $entity_type_manager->getDefinition($entity_type_id);
    $data = [
      // String entities require an ID, the DB will cast it.
      $entity_type->getKey('id') => 12345,
      $entity_type->getKey('label') => $this->randomMachineName(),
      'field_xb_field' => $this->tree,
    ];
    if ($entity_type_id === 'entity_test_mulrev') {
      // String revision IDs don't have default values.
      // @see \Drupal\xb_entity_test\Hook\EntityTestHooks::entityBaseFieldInfoAlter
      $data[$entity_type->getKey('revision')] = $this->container->get(UuidInterface::class)->generate();
    }
    if ($bundle) {
      $data[$entity_type->getKey('bundle')] = $bundle;
    }
    $entity = $storage->create($data);
    $entity->save();
    /** @var \Drupal\Core\Entity\ContentEntityInterface */
    return $entity;
  }

  private function createXbField(string $entity_type_id, string $bundle): void {
    $field_label = Unicode::ucfirst($this->randomMachineName());

    $storage = FieldStorageConfig::create([
      'field_name' => 'field_xb_field',
      'entity_type' => $entity_type_id,
      'type' => ComponentTreeItem::PLUGIN_ID,
      'settings' => ['datetime_type' => DateTimeItem::DATETIME_TYPE_DATE],
    ]);
    $storage->save();
    FieldConfig::create([
      'field_storage' => $storage,
      'label' => $field_label,
      'bundle' => $bundle,
      'required' => TRUE,
    ])->save();
  }

  /**
   * @covers ::onEntityUpdateOrInsert
   *
   * @dataProvider providerEntityTypes
   */
  public function testOnEntityUpdateOrInsert(string $entity_type_id, ?string $bundle = NULL): void {
    $this->assertDependencyRecords([]);
    $entity = $this->buildTestEntity($entity_type_id, $bundle);

    $first_revision = self::entityRevisionIdentifier($entity);
    $this->assertDependencyRecords([
      $first_revision => [
        'config' => [$this->component1->getConfigDependencyName()],
        'plugin' => ['field_type:string'],
      ],
    ]);

    $translation = NULL;
    if ($entity->getEntityType()->isTranslatable()) {
      $french = $entity->addTranslation('fr');
      $french->set('field_xb_field', [
        [
          'uuid' => 'my-component',
          'component_id' => 'sdc.xb_test_sdc.props-slots',
          'inputs' => [
            'heading' => StaticPropSource::generate(
              expression: new FieldTypePropExpression('string', 'value'),
              cardinality: 1,
            )->withValue('Hey there')->toArray(),
          ],
        ],
        [
          'uuid' => 'second-component',
          'component_id' => 'block.xb_test_block_input_none',
          'inputs' => [],
        ],
        [
          'uuid' => 'third-component',
          'component_id' => 'sdc.xb_test_sdc.props-no-slots',
          'inputs' => [
            'heading' => StaticPropSource::generate(
              expression: new FieldTypePropExpression('string', 'value'),
              cardinality: 1,
            )->withValue('Bonjour')->toArray(),
          ],
        ],
      ])->save();
      $translation = self::entityRevisionIdentifier($french);
      $this->assertDependencyRecords([
        $first_revision => [
          'config' => [$this->component1->getConfigDependencyName()],
          'plugin' => ['field_type:string'],
        ],
        $translation => [
          'config' => [
            $this->component2->getConfigDependencyName(),
            $this->component3->getConfigDependencyName(),
            $this->component1->getConfigDependencyName(),
          ],
          'plugin' => ['field_type:string'],
        ],
      ]);
    }

    if (!$entity->getEntityType()->isRevisionable()) {
      return;
    }
    $this->setNewRevision($entity);
    $entity->set('field_xb_field', [
      [
        'uuid' => 'my-component',
        'component_id' => 'sdc.xb_test_sdc.props-slots',
        'inputs' => [
          'heading' => StaticPropSource::generate(
            expression: new FieldTypePropExpression('string', 'value'),
            cardinality: 1,
          )->withValue('Hey there')->toArray(),
        ],
      ],
      [
        'uuid' => 'second-component',
        'component_id' => 'block.xb_test_block_input_none',
        'inputs' => [],
      ],
    ])->save();
    $entity->save();

    $second_revision = self::entityRevisionIdentifier($entity);
    $expected_records = [
      $first_revision => [
        'config' => [$this->component1->getConfigDependencyName()],
        'plugin' => ['field_type:string'],
      ],
      $second_revision => [
        'config' => [
          $this->component2->getConfigDependencyName(),
          $this->component1->getConfigDependencyName(),
        ],
        'plugin' => ['field_type:string'],
      ],
    ];
    if ($translation !== NULL) {
      $expected_records[$translation] = [
        'config' => [
          $this->component2->getConfigDependencyName(),
          $this->component3->getConfigDependencyName(),
          $this->component1->getConfigDependencyName(),
        ],
        'plugin' => ['field_type:string'],
      ];
    }
    $this->assertDependencyRecords($expected_records);
  }

  public function testOnFieldConfigDelete(): void {
    $this->assertDependencyRecords([]);
    $entity1 = $this->buildTestEntity(Page::ENTITY_TYPE_ID, Page::ENTITY_TYPE_ID);
    $entity2 = $this->buildTestEntity('entity_test_string_id', 'entity_test_string_id');
    $dependencies = [
      'config' => [$this->component1->getConfigDependencyName()],
      'plugin' => ['field_type:string'],
    ];
    $this->assertDependencyRecords([
      self::entityRevisionIdentifier($entity2) => $dependencies,
      self::entityRevisionIdentifier($entity1) => $dependencies,
    ]);
    FieldConfig::load('entity_test_string_id.entity_test_string_id.field_xb_field')?->delete();
    // Should retain the values for a field of the same name on a different
    // entity-type.
    $this->assertDependencyRecords([
      self::entityRevisionIdentifier($entity1) => $dependencies,
    ]);
  }

  /**
   * @covers ::onEntityDelete
   * @dataProvider providerEntityTypes
   */
  public function testOnEntityDelete(string $entity_type_id, ?string $bundle = NULL): void {
    $this->assertDependencyRecords([]);
    $entity = $this->buildTestEntity($entity_type_id, $bundle);

    $first_revision = self::entityRevisionIdentifier($entity);
    $dependencies = [
      'config' => [$this->component1->getConfigDependencyName()],
      'plugin' => ['field_type:string'],
    ];
    $this->assertDependencyRecords([
      $first_revision => $dependencies,
    ]);

    if ($entity->getEntityType()->isRevisionable()) {
      $this->setNewRevision($entity);
      $entity->save();
      $this->assertDependencyRecords([
        $first_revision => $dependencies,
        self::entityRevisionIdentifier($entity) => $dependencies,
      ]);
    }

    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage($entity->getEntityTypeId());
    $storage->delete([$entity]);
    $this->assertDependencyRecords([]);
  }

  /**
   * @covers ::onEntityRevisionDelete
   * @dataProvider providerEntityTypes
   */
  public function testOnEntityRevisionDelete(string $entity_type_id, ?string $bundle = NULL): void {
    if (!$this->container->get(EntityTypeManagerInterface::class)->getDefinition($entity_type_id)->isRevisionable()) {
      $this->markTestSkipped();
    }
    $this->assertDependencyRecords([]);
    $entity = $this->buildTestEntity($entity_type_id, $bundle);

    $first_revision = self::entityRevisionIdentifier($entity);
    $first_revision_id = $entity->getRevisionId();
    $dependencies = [
      'config' => [$this->component1->getConfigDependencyName()],
      'plugin' => ['field_type:string'],
    ];
    $this->assertDependencyRecords([
      $first_revision => $dependencies,
    ]);

    $this->setNewRevision($entity);
    $entity->save();
    $this->assertDependencyRecords([
      $first_revision => $dependencies,
      self::entityRevisionIdentifier($entity) => $dependencies,
    ]);

    $storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage($entity->getEntityTypeId());
    \assert($storage instanceof RevisionableStorageInterface);
    // @phpstan-ignore-next-line Core wrongly type hints these as integers.
    $storage->deleteRevision($first_revision_id);

    $this->assertDependencyRecords([
      self::entityRevisionIdentifier($entity) => $dependencies,
    ]);
  }

}
