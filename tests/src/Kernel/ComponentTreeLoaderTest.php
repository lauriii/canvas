<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas\Kernel\Traits\VfsPublicStreamUrlTrait;
use Drupal\Tests\canvas\TestSite\CanvasTestSetup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Drupal\canvas\Storage\ComponentTreeLoader.
 *
 * @todo Refactor this to start using CanvasKernelTestBase and stop using CanvasTestSetup in https://www.drupal.org/project/canvas/issues/3531679
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ComponentTreeLoader::class)]
#[Group('canvas')]
class ComponentTreeLoaderTest extends KernelTestBase {

  use VfsPublicStreamUrlTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(ModuleInstallerInterface::class)->install(['system']);
    // @todo Refactor this away in https://www.drupal.org/project/canvas/issues/3531679
    (new CanvasTestSetup())->setup();
  }

  public function testGetCanvasFieldName(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
    ]);
    /** @var \Drupal\canvas\Storage\ComponentTreeLoader $loader */
    $loader = $this->container->get(ComponentTreeLoader::class);
    $this->assertEquals('field_canvas_demo', $loader->load($node)->getFieldDefinition()->getName());
    $page = Page::create([
      'title' => 'My page',
    ]);
    $this->assertEquals('components', $loader->load($page)->getFieldDefinition()->getName());
  }

  public function testLoadAll(): void {
    $loader = $this->container->get(ComponentTreeLoader::class);
    \assert($loader instanceof ComponentTreeLoader);

    // canvas_page and a single-field bundle each resolve to one tree.
    self::assertCount(1, $loader->loadAll(Page::create(['title' => 'My page'])));
    $node = Node::create(['type' => 'article', 'title' => 'One field']);
    $lists = $loader->loadAll($node);
    self::assertCount(1, $lists);
    self::assertSame('field_canvas_demo', $lists[0]->getFieldDefinition()->getName());
    // findItemListContaining returns NULL when no field holds the instance.
    self::assertNull($loader->findItemListContaining($node, 'e2b3c4d5-0000-4000-8000-000000000000'));

    // A bundle with more than one component_tree field (the templated /
    // per-slot-field shape) resolves to one tree per field.
    $storage = FieldStorageConfig::create([
      'field_name' => 'canvas_slot_extra',
      'entity_type' => 'node',
      'type' => 'component_tree',
    ]);
    $storage->save();
    FieldConfig::create(['field_storage' => $storage, 'bundle' => 'article'])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $multiField = Node::create(['type' => 'article', 'title' => 'Two fields']);
    self::assertCount(2, $loader->loadAll($multiField));
  }

  public function testEntityBundleRestriction(): void {
    $page_type = NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ]);
    $page_type->save();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test',
    ]);
    $node->save();
    $this->expectException(\LogicException::class);
    // @todo Fix in https://drupal.org/i/3498525 for testing a bundle where a
    //   canvas field is not present.
    // @see \Drupal\canvas\Storage\ComponentTreeLoader::getCanvasFieldName
    $this->expectExceptionMessage('For now Canvas only works if the entity is a canvas_page! Other entity types and bundles must use content templates for now, see https://drupal.org/i/3498525');
    /** @var \Drupal\canvas\Storage\ComponentTreeLoader $component_tree_loader */
    $component_tree_loader = $this->container->get(ComponentTreeLoader::class);
    $component_tree_loader->load($node);
  }

  public function testMissingCanvasField(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
    ]);
    $node->save();
    FieldStorageConfig::loadByName('node', 'field_canvas_demo')?->delete();
    $this->container->get(EntityFieldManagerInterface::class)->clearCachedFieldDefinitions();
    // Reload the node to refresh field definitions.
    $node = Node::load($node->id());
    self::assertNotNull($node);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('This entity does not have a Canvas field!');
    /** @var \Drupal\canvas\Storage\ComponentTreeLoader $component_tree_loader */
    $component_tree_loader = $this->container->get(ComponentTreeLoader::class);
    $component_tree_loader->load($node);
  }

}
