<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Drupal\canvas\Entity\JavaScriptComponent.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(JavaScriptComponent::class)]
#[Group('canvas')]
class JavascriptComponentTest extends CanvasKernelTestBase {

  /**
   * Tests minItems enforcement in updateFromClientSide.
   *
   * @legacy-covers ::createFromClientSide
   * @legacy-covers ::updateFromClientSide
   */
  public function testUpdateFromClientSideMinItemsEnforcement(): void {
    $client_data = [
      'machineName' => 'test_min_items',
      'name' => 'Test minItems Component',
      'status' => FALSE,
      'required' => ['required_array_prop', 'required_string_prop'],
      'props' => [
        'required_array_prop' => [
          'type' => 'array',
          'title' => 'Required Array Prop',
          'items' => ['type' => 'string'],
        ],
        'optional_array_prop' => [
          'type' => 'array',
          'title' => 'Optional Array Prop',
          'items' => ['type' => 'string'],
        ],
        'required_string_prop' => [
          'type' => 'string',
          'title' => 'Required String Prop',
        ],
      ],
      'slots' => [],
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'dataDependencies' => [],
    ];

    $component = JavaScriptComponent::createFromClientSide($client_data);
    $props = $component->get('props');

    // Required array prop gets minItems: 1 even when client does not send it.
    $this->assertSame(1, $props['required_array_prop']['minItems']);
    // Optional array prop does not get minItems.
    $this->assertArrayNotHasKey('minItems', $props['optional_array_prop']);
    // Required non-array prop does not get minItems.
    $this->assertArrayNotHasKey('minItems', $props['required_string_prop']);

    // minItems sent by client on optional array prop is removed by server.
    $client_data['props']['optional_array_prop']['minItems'] = 1;
    $component->updateFromClientSide($client_data);
    $props = $component->get('props');
    $this->assertArrayNotHasKey('minItems', $props['optional_array_prop']);
  }

  /**
   * Tests adding imported component dependencies.
   *
   * @legacy-covers ::createFromClientSide
   * @legacy-covers ::updateFromClientSide
   */
  public function testAddingImportedComponentDependencies(): void {
    $client_data = [
      'machineName' => 'test',
      'name' => 'Test Code Component',
      'status' => FALSE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'sourceCodeJs' => '',
      'sourceCodeCss' => '',
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'dataDependencies' => [],
    ];
    $js_component = JavaScriptComponent::createFromClientSide($client_data);
    $this->assertSame(SAVED_NEW, $js_component->save());
    $this->assertCount(0, $js_component->getDependencies());
    $this->assertSame([
      'config:canvas.js_component.test',
    ], $js_component->getCacheTags());

    // Create another component that will be imported by the first one.
    $client_data_2 = $client_data;
    $client_data_2['name'] = 'Test Code Component 2';
    $client_data_2['machineName'] = 'test2';
    $js_component2 = JavaScriptComponent::createFromClientSide($client_data_2);
    $this->assertSame(SAVED_NEW, $js_component2->save());
    $this->assertCount(0, $js_component2->getDependencies());
    $this->assertSame([
      'config:canvas.js_component.test2',
    ], $js_component2->getCacheTags());

    // Adding a component to `importedJsComponents` should add this component
    // to the dependencies.
    $client_data['importedJsComponents'] = [$js_component2->id()];
    $js_component->updateFromClientSide($client_data);
    $this->assertSame(SAVED_UPDATED, $js_component->save());
    $this->assertSame(
      [
        'config' => [$js_component2->getConfigDependencyName()],
      ],
      $js_component->getDependencies()
    );
    $this->assertSame([
      'config:canvas.js_component.test',
      'config:canvas.js_component.test2',
    ], $js_component->getCacheTags());

    // Ensure missing components are will throw a validation error.
    $client_data['importedJsComponents'] = [$js_component2->id(), 'missing'];
    try {
      $js_component->updateFromClientSide($client_data);
      $this->fail('Expected ConstraintViolationException not thrown.');
    }
    catch (ConstraintViolationException $exception) {
      $violations = $exception->getConstraintViolationList();
      $this->assertInstanceOf(EntityConstraintViolationList::class, $violations);
      $this->assertSame($js_component->id(), $violations->entity->id());
      $this->assertCount(1, $violations);
      $violation = $violations->get(0);
      $this->assertSame('importedJsComponents.1', $violation->getPropertyPath());
      $this->assertSame("The JavaScript component with the machine name 'missing' does not exist.", $violation->getMessage());
    }

    // Ensure not sending `importedJsComponents` will throw an error.
    unset($client_data['importedJsComponents']);
    try {
      $js_component->updateFromClientSide($client_data);
      $this->fail('Expected ConstraintViolationException not thrown.');
    }
    catch (ConstraintViolationException $exception) {
      $violations = $exception->getConstraintViolationList();
      $this->assertInstanceOf(EntityConstraintViolationList::class, $violations);
      $this->assertSame($js_component->id(), $violations->entity->id());
      $this->assertCount(1, $violations);
      $violation = $violations->get(0);
      $this->assertSame('importedJsComponents', $violation->getPropertyPath());
      $this->assertSame("The 'importedJsComponents' field is required when 'sourceCodeJs' or 'compiledJs' is provided", $violation->getMessage());
    }

    // Resetting the imported components to an empty array should remove the
    // dependencies.
    $client_data['importedJsComponents'] = [];
    $js_component->updateFromClientSide($client_data);
    $this->assertSame(SAVED_UPDATED, $js_component->save());
    $this->assertSame([], $js_component->getDependencies());
    $this->assertSame([
      'config:canvas.js_component.test',
    ], $js_component->getCacheTags());
  }

  /**
   * Tests the client-side representation of an external component.
   *
   * @legacy-covers ::createFromClientSide
   * @legacy-covers ::updateFromClientSide
   * @legacy-covers ::normalizeForClientSide
   */
  public function testExternalComponent(): void {
    $client_data = [
      'machineName' => 'external_test',
      'name' => 'External test',
      'status' => TRUE,
      'type' => 'external',
      'required' => [],
      'props' => [],
      'slots' => [],
      'dataDependencies' => [],
    ];
    $component = JavaScriptComponent::createFromClientSide($client_data);

    self::assertTrue($component->isExternal());
    self::assertSame('external', $component->getComponentType());
    self::assertNull($component->get('js'));
    self::assertNull($component->get('css'));
    self::assertEntityIsValid($component);

    $representation = $component->normalizeForClientSide();
    self::assertNull($representation->preview);
    self::assertSame('external', $representation->values['type']);
    self::assertArrayNotHasKey('sourceCodeJs', $representation->values);
    self::assertArrayNotHasKey('sourceCodeCss', $representation->values);
    self::assertArrayNotHasKey('compiledJs', $representation->values);
    self::assertArrayNotHasKey('compiledCss', $representation->values);

    $this->expectException(ConstraintViolationException::class);
    $this->expectExceptionMessage('External code components cannot contain JavaScript or CSS.');
    $component->updateFromClientSide([
      ...$client_data,
      'sourceCodeJs' => 'export default function ExternalTest() {}',
    ]);
  }

  /**
   * Tests that saved external components reject identity changes.
   *
   * The external application's component metadata owns the name, status, and
   * type of an external component; client-side changes would be reverted by
   * the next synchronization and are rejected.
   *
   * @legacy-covers ::updateFromClientSide
   */
  public function testExternalComponentIdentityIsLocked(): void {
    $client_data = [
      'machineName' => 'external_locked',
      'name' => 'External locked',
      'status' => TRUE,
      'type' => 'external',
      'required' => [],
      'props' => [],
      'slots' => [],
      'dataDependencies' => [],
    ];
    $component = JavaScriptComponent::createFromClientSide($client_data);
    $component->save();

    // Resending the same identity values along with metadata changes is fine.
    $component->updateFromClientSide([
      ...$client_data,
      'props' => [
        'title' => [
          'type' => 'string',
          'title' => 'Title',
          'examples' => ['A title'],
        ],
      ],
    ]);
    self::assertSame(['title'], \array_keys($component->getProps() ?? []));

    $identity_changes = [
      [['name' => 'Renamed'], 'External code components cannot be renamed'],
      [['status' => FALSE], 'External code components cannot be exposed or unexposed'],
      [['type' => 'react'], 'The code component type cannot be changed.'],
    ];
    foreach ($identity_changes as [$identity_change, $expected_message]) {
      try {
        $component->updateFromClientSide($identity_change + $client_data);
        $this->fail("Expected a constraint violation containing '$expected_message'.");
      }
      catch (ConstraintViolationException $e) {
        self::assertStringContainsString($expected_message, $e->getMessage());
      }
    }

    // The type of a saved React component is locked, too: flipping it to
    // external would silently discard its source code.
    $react_component = JavaScriptComponent::createFromClientSide([
      'machineName' => 'react_locked',
      'name' => 'React locked',
      'status' => TRUE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'sourceCodeJs' => 'console.log("hey");',
      'sourceCodeCss' => '.big { font-size: 3rem; }',
      'compiledJs' => 'console.log("hey");',
      'compiledCss' => '.big{font-size:3rem;}',
      'importedJsComponents' => [],
      'dataDependencies' => [],
    ]);
    $react_component->save();
    $this->expectException(ConstraintViolationException::class);
    $this->expectExceptionMessage('The code component type cannot be changed.');
    $react_component->updateFromClientSide(['type' => 'external']);
  }

}
