<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Kernel\Config;

use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\KernelTests\KernelTestBase;

/**
 * @coversDefaultClass \Drupal\experience_builder\Entity\JavaScriptComponent
 * @group experience_builder
 */
class JavascriptComponentTest extends KernelTestBase {

  protected static $modules = [
    'experience_builder',
  ];

  /**
   * @covers ::createFromClientSide
   * @covers ::updateFromClientSide
   */
  public function testAddingImportedComponentDependencies(): void {
    $client_data = [
      'machineName' => 'test',
      'name' => 'Test Code Component',
      'status' => FALSE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'source_code_js' => '',
      'source_code_css' => '',
      'compiled_js' => '',
      'compiled_css' => '',
      'imported_js_components' => [],
    ];
    $js_component = JavaScriptComponent::createFromClientSide($client_data);
    $this->assertSame(SAVED_NEW, $js_component->save());
    $this->assertCount(0, $js_component->getDependencies());

    // Create another component that will be imported by the first one.
    $client_data_2 = $client_data;
    $client_data_2['name'] = 'Test Code Component 2';
    $client_data_2['machineName'] = 'test2';
    $js_component2 = JavaScriptComponent::createFromClientSide($client_data_2);
    $this->assertSame(SAVED_NEW, $js_component2->save());
    $this->assertCount(0, $js_component2->getDependencies());

    // Adding a component to `imported_js_components` should add this component
    // to the dependencies.
    $client_data['imported_js_components'] = [$js_component2->id()];
    $js_component->updateFromClientSide($client_data);
    $this->assertSame(SAVED_UPDATED, $js_component->save());
    $this->assertSame(
      [
        'config' => [$js_component2->getConfigDependencyName()],
      ],
      $js_component->getDependencies()
    );

    // Ensure missing components are will throw a validation error.
    $client_data['imported_js_components'] = [$js_component2->id(), 'missing'];
    try {
      $js_component->updateFromClientSide($client_data);
    }
    catch (ConstraintViolationException $exception) {
      $this->assertCount(1, $exception->getConstraintViolationList());
      $violation = $exception->getConstraintViolationList()->get(0);
      $this->assertSame('imported_js_components.1', $violation->getPropertyPath());
      $this->assertSame("The JavaScript component with machine name 'missing' does not exist.", $violation->getMessage());
    }

    // Resetting the imported components to an empty array should remove the
    // dependencies.
    $client_data['imported_js_components'] = [];
    $js_component->updateFromClientSide($client_data);
    $this->assertSame(SAVED_UPDATED, $js_component->save());
    $this->assertSame([], $js_component->getDependencies());
  }

}
