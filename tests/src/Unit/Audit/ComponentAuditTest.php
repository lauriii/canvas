<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit\Audit;

use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Config\Entity\ConfigEntityDependency;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\experience_builder\Audit\ComponentAudit;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ContentTemplate;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Entity\Pattern;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\field\FieldConfigInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorage;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\experience_builder\Audit\ComponentAudit
 * @group experience_builder
 * @todo Improve in https://www.drupal.org/project/experience_builder/issues/3522953, and consider converting to kernel test
 */
class ComponentAuditTest extends UnitTestCase {

  /**
   * @covers ::getContentRevisionsUsingComponent
   */
  public function testGetContentRevisionsUsingComponent(): void {
    $entity_query = $this->createMock(QueryInterface::class);
    $entity_query->expects($this->exactly(2))
      ->method('allRevisions')
      ->willReturnSelf();
    $entity_query->expects($this->exactly(2))
      ->method('accessCheck')
      ->with(TRUE)
      ->willReturnSelf();
    $condition_args = [
      ['base_definition.deps_config', '%experience_builder.component.my_test_component%', 'LIKE', NULL],
      ['field.deps_config', '%experience_builder.component.my_test_component%', 'LIKE', NULL],
    ];
    $entity_query->expects($this->exactly(2))
      ->method('condition')
      ->with(
        $this->callback(function (...$args) use ($condition_args): bool {
          return in_array($args, $condition_args);
        }),
      )->willReturnSelf();
    $entity_query->expects($this->exactly(2))
      ->method('execute')
      ->willReturnOnConsecutiveCalls(
        [5 => 5],
        [2 => 2, 3 => 3],
      );

    $rev2 = $this->prophesize(NodeInterface::class);
    $rev2->getRevisionId()->willReturn(2);
    $rev3 = $this->prophesize(NodeInterface::class);
    $rev3->getRevisionId()->willReturn(3);
    $rev5 = $this->prophesize(NodeInterface::class);
    $rev5->getRevisionId()->willReturn(5);

    $revisions = [
      2 => $rev2->reveal(),
      3 => $rev3->reveal(),
      5 => $rev5->reveal(),
    ];

    // For entity_storage we need a double that implements both
    // \Drupal\Core\Entity\RevisionableStorageInterface and
    // \Drupal\Core\Entity\Sql\SqlEntityStorageInterface
    $entity_storage = $this->createMock(NodeStorage::class);
    $entity_storage->expects($this->exactly(2))
      ->method('getQuery')->willReturn($entity_query);
    $entity_storage->expects($this->any())
      ->method('loadMultipleRevisions')
      ->willReturnCallback(function ($argument) use ($revisions) {
        return array_filter($revisions, fn($key) => in_array($key, $argument), ARRAY_FILTER_USE_KEY);
      });

    $component_definition = $this->prophesize(ConfigEntityTypeInterface::class);
    $component_definition->id()
      ->willReturn(Component::ENTITY_TYPE_ID);
    $component_definition->getConfigPrefix()
      ->willReturn('experience_builder.' . Component::ENTITY_TYPE_ID);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->any())
      ->method('getStorage')
      ->willReturn($entity_storage);
    $entity_type_manager->expects($this->any())
      ->method('getDefinition')
      ->with(Component::ENTITY_TYPE_ID)
      ->willReturn($component_definition->reveal());

    $config_manager = $this->prophesize(ConfigManagerInterface::class);
    $entity_field_manager = $this->prophesize(EntityFieldManagerInterface::class);
    $entity_field_manager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID)->willReturn([
      'node' => [
        'base_definition' => $this->prophesize(BaseFieldDefinition::class)->reveal(),
        'field' => $this->prophesize(FieldConfigInterface::class)->reveal(),
      ],
    ]);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set(EntityTypeManagerInterface::class, $entity_type_manager);
    \Drupal::setContainer($container);

    $audit = new ComponentAudit($config_manager->reveal(), $entity_type_manager, $entity_field_manager->reveal());
    $component = new Component([
      'id' => 'my_test_component',
    ], Component::ENTITY_TYPE_ID);

    $revisions = $audit->getContentRevisionsUsingComponent($component);
    $revision_ids = array_map(fn (RevisionableInterface $revision) => $revision->getRevisionId(), $revisions);
    $this->assertSame($revision_ids, [5, 2, 3]);
  }

  /**
   * @covers ::getConfigEntityDependenciesUsingComponent
   * @dataProvider configProvider
   */
  public function testGetConfigEntityDependenciesUsingComponent(string $config_id, array $expected): void {
    $content_template_entity_definition = $this->prophesize(ConfigEntityTypeInterface::class);
    $content_template_entity_definition->id()
      ->willReturn(ContentTemplate::ENTITY_TYPE_ID);
    $content_template_entity_definition->getConfigPrefix()
      ->willReturn('experience_builder.' . ContentTemplate::ENTITY_TYPE_ID);
    $page_region_definition = $this->prophesize(ConfigEntityTypeInterface::class);
    $page_region_definition->id()
      ->willReturn(PageRegion::ENTITY_TYPE_ID);
    $page_region_definition->getConfigPrefix()
      ->willReturn('experience_builder.' . PageRegion::ENTITY_TYPE_ID);
    $component_definition = $this->prophesize(ConfigEntityTypeInterface::class);
    $component_definition->id()
      ->willReturn(Component::ENTITY_TYPE_ID);
    $component_definition->getConfigPrefix()
      ->willReturn('experience_builder.' . Component::ENTITY_TYPE_ID);
    $pattern_definition = $this->prophesize(ConfigEntityTypeInterface::class);
    $pattern_definition->id()
      ->willReturn(Pattern::ENTITY_TYPE_ID);
    $pattern_definition->getConfigPrefix()
      ->willReturn('experience_builder.' . Pattern::ENTITY_TYPE_ID);

    $config_definitions = [
      Component::ENTITY_TYPE_ID => $component_definition->reveal(),
      PageRegion::ENTITY_TYPE_ID => $page_region_definition->reveal(),
      ContentTemplate::ENTITY_TYPE_ID => $content_template_entity_definition->reveal(),
      Pattern::ENTITY_TYPE_ID => $pattern_definition->reveal(),
    ];

    $config_entity_map = [
      // Patterns construction expects just an ID without the config prefix, see
      // \Drupal\experience_builder\Entity\Pattern::preCreate
      'first_section' => new Pattern(['id' => 'first_section'], Pattern::ENTITY_TYPE_ID),
      'second_section' => new Pattern(['id' => 'second_section'], Pattern::ENTITY_TYPE_ID),
      'olivero.header' => new PageRegion(['id' => 'experience_builder.page_region.olivero.header', 'theme' => 'olivero', 'region' => 'header'], PageRegion::ENTITY_TYPE_ID),
      'another_theme.footer' => new PageRegion(['id' => 'experience_builder.page_region.another_theme.footer', 'theme' => 'another_theme', 'region' => 'footer'], PageRegion::ENTITY_TYPE_ID),
      'node.article.full' => new ContentTemplate(['id' => 'experience_builder.content_template.node.article.full', 'content_entity_type_id' => 'node', 'content_entity_type_bundle' => 'article', 'content_entity_type_view_mode' => 'full'], ContentTemplate::ENTITY_TYPE_ID),
      'node.page.teaser' => new ContentTemplate(['id' => 'experience_builder.content_template.node.page.teaser', 'content_entity_type_id' => 'node', 'content_entity_type_bundle' => 'page', 'content_entity_type_view_mode' => 'teaser'], ContentTemplate::ENTITY_TYPE_ID),
    ];

    $entity_storage = $this->createMock(EntityStorageInterface::class);
    $entity_storage->expects($this->any())
      ->method('load')
      ->willReturnCallback(function ($argument) use ($config_entity_map) {
        return $config_entity_map[$argument];
      });

    $entity_type_repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->expects($this->any())
      ->method('getDefinition')
      ->willReturnCallback(function ($argument) use ($config_definitions) {
        return $config_definitions[$argument];
      });
    $entity_type_manager->expects($this->any())
      ->method('getStorage')
      ->willReturn($entity_storage);

    $container = new ContainerBuilder();
    $container->set('entity_type.repository', $entity_type_repository);
    $container->set('entity_type.manager', $entity_type_manager);
    \Drupal::setContainer($container);

    $config_dependency_manager = $this->prophesize(ConfigDependencyManager::class);
    $config_dependency_manager->getDependentEntities('config', 'experience_builder.component.my_test_component')
      ->shouldBeCalledTimes(1)
      ->willReturn([
        new ConfigEntityDependency('experience_builder.pattern.second_section', ['config' => ['experience_builder.component.my_test_component']]),
        new ConfigEntityDependency('experience_builder.page_region.olivero.header', ['config' => ['experience_builder.component.my_test_component']]),
        new ConfigEntityDependency('experience_builder.content_template.node.page.teaser', ['config' => ['enforced' => ['experience_builder.component.my_test_component']]]),
      ]);
    $config_manager = $this->prophesize(ConfigManagerInterface::class);
    $config_manager->getConfigDependencyManager()
      ->shouldBeCalledTimes(1)
      ->willReturn($config_dependency_manager->reveal());
    $entity_field_manager = $this->prophesize(EntityFieldManagerInterface::class);

    $audit = new ComponentAudit($config_manager->reveal(), $entity_type_manager, $entity_field_manager->reveal());
    $component = new Component([
      'id' => 'my_test_component',
    ], Component::ENTITY_TYPE_ID);

    $dependents = $audit->getConfigEntityDependenciesUsingComponent($component, $config_id);
    // We assert we got the expected entity types.
    foreach ($dependents as $dependent) {
      $this->assertSame($dependent->getEntityTypeId(), $config_id);
    }
    // We assert we got the expected entity ids.
    $dependents = array_map(fn(ConfigEntityInterface $config_dependency) => $config_dependency->id(), $dependents);
    $this->assertSame(array_values($dependents), array_values($expected));
  }

  /**
   * @return \Generator<array{string, array{string}}>
   */
  public static function configProvider(): \Generator {
    yield [PageRegion::ENTITY_TYPE_ID, ['olivero.header']];
    yield [Pattern::ENTITY_TYPE_ID, ['second_section']];
    yield [ContentTemplate::ENTITY_TYPE_ID, ['node.page.teaser']];
  }

}
