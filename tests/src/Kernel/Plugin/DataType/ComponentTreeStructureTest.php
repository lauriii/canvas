<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Plugin\DataType;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;

/**
 * Tests testGetComponentIdList() in ComponentTreeStructure.
 *
 * @group experience_builder
 */
class ComponentTreeStructureTest extends UnitTestCase {

  // cspell:disable-next-line
  const VALUE = '[{"uuid":"dynamic-image-udf7d","type":"experience_builder:image"},{"uuid":"static-static-card1ab","type":"sdc_test:my-cta"},{"uuid":"dynamic-static-card2df","type":"sdc_test:my-cta"},{"uuid":"dynamic-dynamic-card3rr","type":"sdc_test:my-cta"},{"uuid":"dynamic-image-static-imageStyle-something7d","type":"experience_builder:image"}]';
  const COMPONENT_IDS = ['experience_builder:image', 'sdc_test:my-cta'];

  protected ComponentTreeStructure $tree;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tree = ComponentTreeStructure::createInstance(DataDefinition::create('component_tree_structure'));
  }

  public function testGetComponentIdList(): void {
    $this->assertSame([], $this->tree->getComponentIdList());
    $this->tree->setValue(self::VALUE);
    $this->assertSame(self::COMPONENT_IDS, $this->tree->getComponentIdList());
  }

}
