<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel;

use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\Storage\ComponentTreeLoader;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\experience_builder\TestSite\XBTestSetup;

/**
 * @coversDefaultClass \Drupal\experience_builder\Storage\ComponentTreeLoader
 *
 * @group experience_builder
 */
class ComponentTreeLoaderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['system']);
    (new XBTestSetup())->setup();
  }

  public function testGetXBFieldName(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
    ]);
    /** @var \Drupal\experience_builder\Storage\ComponentTreeLoader $loader */
    $loader = $this->container->get(ComponentTreeLoader::class);
    $this->assertEquals('field_xb_demo', $loader->load($node)->getFieldDefinition()->getName());
    $page = Page::create([
      'title' => 'My page',
    ]);
    $this->assertEquals('components', $loader->load($page)->getFieldDefinition()->getName());
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
    $this->expectExceptionMessage('For now XB only works if the entity is an xb_page or an article node! Other entity types and bundles must be tested before they are supported, to help see https://drupal.org/i/3493675.');
    /** @var \Drupal\experience_builder\Storage\ComponentTreeLoader $component_tree_loader */
    $component_tree_loader = $this->container->get(ComponentTreeLoader::class);
    $component_tree_loader->load($node);
  }

  public function testMissingXBField(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => '5 amazing uses for old toothbrushes',
    ]);
    $node->save();
    FieldStorageConfig::loadByName('node', 'field_xb_demo')?->delete();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    // Reload the node to refresh field definitions.
    $node = Node::load($node->id());
    self::assertNotNull($node);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('This entity does not have an XB field!');
    /** @var \Drupal\experience_builder\Storage\ComponentTreeLoader $component_tree_loader */
    $component_tree_loader = $this->container->get(ComponentTreeLoader::class);
    $component_tree_loader->load($node);
  }

}
