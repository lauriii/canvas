<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit\DataType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\MissingComponentInputsException;
use Drupal\experience_builder\Plugin\DataType\ComponentInputs;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\experience_builder\Plugin\DataType\ComponentInputs
 * @see \Drupal\Tests\experience_builder\Kernel\DataType\ComponentInputsDependenciesTest
 * @group experience_builder
 */
class ComponentInputsTest extends UnitTestCase {

  /**
   * @covers ::getValues
   */
  public function testGetValues(): void {
    // Create test data.
    $test_inputs = [
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
    ];
    $component_source = $this->prophesize(ComponentSourceInterface::class);
    $component_source->requiresExplicitInput()->willReturn(FALSE);
    $component = $this->prophesize(ComponentInterface::class);
    $component->getComponentSource()->willReturn($component_source->reveal());

    $item = $this->prophesize(ComponentTreeItem::class);
    $item->onChange(NULL)->shouldBeCalled();
    $item->getComponent()->willReturn($component->reveal());
    $item->getUuid()->willReturn('abcd-1234');

    $component_inputs = new ComponentInputs(
      $this->prophesize(DataDefinitionInterface::class)->reveal(),
      NULL,
      $item->reveal()
    );
    $component_inputs->setValue(Json::encode($test_inputs));

    // Test getting values for a existing UUID.
    $this->assertEquals(
      $test_inputs,
      $component_inputs->getValues()
    );

    // Test getting empty values without requiring explicit input.
    $component_inputs->setValue('{}');
    $values = $component_inputs->getValues();
    $this->assertEquals([], $values);

    // Test getting values when explicit input is required.
    $component_source->requiresExplicitInput()->willReturn(TRUE);
    $this->expectException(MissingComponentInputsException::class);
    $component_inputs->getValues();
  }

}
