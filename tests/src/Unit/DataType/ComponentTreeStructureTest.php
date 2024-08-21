<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit\DataType;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\experience_builder\Traits\TestDataUtilitiesTrait;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
 */
class ComponentTreeStructureTest extends UnitTestCase {

  use TestDataUtilitiesTrait;

  /**
   * @covers ::getValue
   */
  public function testGetValue(): void {
    $data = DataDefinition::create('component_tree_structure');
    $component_tree_structure = new ComponentTreeStructure($data, 'component_tree_structure', NULL);
    $component_tree_structure->setValue('{"' . ComponentTreeStructure::ROOT_UUID . '": []}');
    $this->assertSame('{"' . ComponentTreeStructure::ROOT_UUID . '": []}', $component_tree_structure->getValue());
  }

  /**
   * @covers ::getComponentInstanceUuids
   */
  public function testGetComponentInstanceUuids(): void {
    $this->assertSame(
      ['uuid-root-1', 'uuid-root-2', 'uuid-root-3', 'uuid4-author1', 'uuid2-submitted', 'uuid5-author2', 'uuid4-author3'],
      $this->getTestComponentTreeStructure()->getComponentInstanceUuids());
  }

  /**
   * @covers ::getComponentId
   */
  public function testGetComponentId(): void {
    $component_tree_structure = $this->getTestComponentTreeStructure();
    $this->assertSame('provider:two-col', $component_tree_structure->getComponentId('uuid-root-1'));
    $this->assertSame('provider:marquee', $component_tree_structure->getComponentId('uuid-root-2'));
    $this->assertSame('provider:person-card', $component_tree_structure->getComponentId('uuid4-author1'));
    $this->assertSame('provider:elegant-date', $component_tree_structure->getComponentId('uuid2-submitted'));
    $this->assertSame('provider:person-card', $component_tree_structure->getComponentId('uuid5-author2'));
  }

  /**
   * @covers ::getComponentIdList
   */
  public function testGetComponentIdList(): void {
    $this->assertSame(
      [],
      ComponentTreeStructure::createInstance(DataDefinition::create('component_tree_structure'))->getComponentIdList()
    );
    $this->assertSame(
      ['provider:two-col', 'provider:marquee', 'provider:person-card', 'provider:elegant-date'],
      $this->getTestComponentTreeStructure()->getComponentIdList()
    );
  }

  /**
   * @covers ::getComponentId
   */
  public function testGetComponentIdMissing(): void {
    $this->expectException(\OutOfRangeException::class);
    $this->expectExceptionMessage('No component stored for uuid-missing. Caused by either incorrect logic or `props` being out of sync with `tree`.');
    $this->getTestComponentTreeStructure()->getComponentId('uuid-missing');
  }

  /**
   * @covers ::applyDefaultValue
   */
  public function testApplyDefaultValue(): void {
    $component_tree_structure = new ComponentTreeStructure(DataDefinition::create('component_tree_structure'), 'component_tree_structure', NULL);
    $component_tree_structure->applyDefaultValue();
    $this->assertSame('{"' . ComponentTreeStructure::ROOT_UUID . '": []}', $component_tree_structure->getValue());
  }

  /**
   * @return \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
   */
  private function getTestComponentTreeStructure(): ComponentTreeStructure {
    $tree = [
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'uuid-root-1', 'component' => 'provider:two-col'],
        ['uuid' => 'uuid-root-2', 'component' => 'provider:marquee'],
        ['uuid' => 'uuid-root-3', 'component' => 'provider:marquee'],
      ],
      'uuid-root-1' => [
        'firstColumn' => [
          ['uuid' => 'uuid4-author1', 'component' => 'provider:person-card'],
          ['uuid' => 'uuid2-submitted', 'component' => 'provider:elegant-date'],
        ],
        'secondColumn' => [
          ['uuid' => 'uuid5-author2', 'component' => 'provider:person-card'],
        ],
      ],
      'uuid-root-2' => [
        'content' => [
          ['uuid' => 'uuid4-author3', 'component' => 'provider:person-card'],
        ],
      ],
    ];

    $test_json = self::encodeXBData($tree);
    $definition = DataDefinition::create('component_tree_structure');
    $component_tree_structure = new ComponentTreeStructure($definition,
      'component_tree_structure');
    $component_tree_structure->setValue($test_json);
    return $component_tree_structure;
  }

}
