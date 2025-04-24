<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit\DataType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;
use Drupal\experience_builder\MissingComponentInputsException;
use Drupal\experience_builder\Plugin\DataType\ComponentInputs;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\DataType\ComponentInputs
 * @group experience_builder
 */
class ComponentInputsTest extends UnitTestCase {

  /**
   * @covers ::getValues
   */
  public function testGetValues(): void {
    $existing_component_uuid = 'test-component-uuid';

    // Create test data.
    $test_inputs = [
      $existing_component_uuid => [
        'title' => [
          'sourceType' => 'static:text',
          'value' => 'Test Title',
          'expression' => '',
        ],
        'body' => [
          'sourceType' => 'static:text',
          'value' => 'Test Body',
          'expression' => '',
        ],
      ],
    ];
    $component_source = $this->prophesize(ComponentSourceInterface::class);

    $tree = $this->prophesize(ComponentTreeStructure::class);
    $tree->getComponentId($existing_component_uuid)->willReturn('test-component-id');
    $tree->getComponentSource($existing_component_uuid)->willReturn($component_source->reveal());

    $item = $this->prophesize(ComponentTreeItem::class);
    $item->get('tree')->willReturn($tree->reveal());
    $item->onChange(NULL)->shouldBeCalledTimes(1);

    $component_inputs = new ComponentInputs(
      $this->prophesize(DataDefinitionInterface::class)->reveal(),
      NULL,
      $item->reveal()
    );
    $component_inputs->setValue(Json::encode($test_inputs));

    // Test getting values for a existing UUID.
    $this->assertEquals(
      $test_inputs[$existing_component_uuid],
      $component_inputs->getValues($existing_component_uuid)
    );

    // Test getting values for a non-existing UUID that doesn't require explicit input.
    $non_existing_uuid = 'non-existing-uuid';
    $tree->getComponentSource($non_existing_uuid)->willReturn($component_source->reveal());
    $component_source->requiresExplicitInput()->willReturn(FALSE);

    $values = $component_inputs->getValues($non_existing_uuid);
    $this->assertEquals([], $values);

    // Test getting values for a non-existing UUID that requires explicit input.
    $component_source->requiresExplicitInput()->willReturn(TRUE);
    $this->expectException(MissingComponentInputsException::class);
    $component_inputs->getValues($non_existing_uuid);
  }

}
