<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\Tests\canvas\Traits\ConstraintViolationsTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\canvas\Entity\EntityConstraintViolationList;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Exception\ConstraintViolationException;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;

/**
 * Tests Drupal\canvas\Entity\JavaScriptComponent.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(JavaScriptComponent::class)]
#[Group('canvas')]
class JavascriptComponentTest extends CanvasKernelTestBase {

  use ConstraintViolationsTestTrait;

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

  public static function providerNormalizePropsSchema(): \Generator {
    // Verify enum/meta:enum/x-translation-context were moved to items level.
    // Key order follows config schema merge order: prop_shape.array keys
    // (type, items) come first, then prop.* keys (title, examples).
    yield 'array prop enum normalization' => [
      'client_data' => [
        'props' => [
          'tags' => [
            'type' => 'array',
            'title' => 'Tags',
            'items' => ['type' => 'string'],
            // These are at array level (incorrect, but what client sends).
            'enum' => ['option1', 'option2'],
            'meta:enum' => ['option1' => 'Option 1', 'option2' => 'Option 2'],
            'x-translation-context' => 'Tag selection',
            'examples' => [['option1']],
          ],
        ],
      ],
      'expected_component_props' => [
        'tags' => [
          'type' => 'array',
          'items' => [
            'type' => 'string',
            'enum' => ['option1', 'option2'],
            'meta:enum' => ['option1' => 'Option 1', 'option2' => 'Option 2'],
            'x-translation-context' => 'Tag selection',
          ],
          'title' => 'Tags',
          'examples' => [['option1']],
        ],
      ],
      'expected_errors' => NULL,
    ];
    // Verify empty array examples are removed.
    // @todo This is needed until https://www.drupal.org/i/3516754.
    yield 'array prop examples normalization' => [
      'client_data' => [
        'props' => [
          'tags' => [
            'type' => 'array',
            'title' => 'Tags',
            'items' => ['type' => 'string'],
            'examples' => [[]],
          ],
        ],
      ],
      'expected_component_props' => [
        'tags' => [
          'type' => 'array',
          'items' => [
            'type' => 'string',
          ],
          'title' => 'Tags',
          'examples' => [],
        ],
      ],
      'expected_errors' => NULL,
    ];
    // Verify empty array examples are removed, even for required props, and
    // that this results in a validation error since required props must have an example value.
    // @todo This is needed until https://www.drupal.org/i/3516754.
    yield 'array prop examples normalization, required prop error' => [
      'client_data' => [
        'required' => ['tags'],
        'props' => [
          'tags' => [
            'type' => 'array',
            'title' => 'Tags',
            'items' => ['type' => 'string'],
            'examples' => [[]],
          ],
        ],
      ],
      'expected_component_props' => NULL,
      'expected_errors' => [
        '' => 'Prop "tags" is required, but does not have example value',
      ],
    ];
  }

  /**
   * Tests that props schema is normalized correctly.
   *
   * @param array $client_data
   *   The client data keys to override.
   * @param array|null $expected_component_props
   *   The expected props after normalization, if no errors.
   * @param array|null $expected_errors
   *   The expected validation errors, if any.
   *
   * @see \Drupal\canvas\Entity\JavaScriptComponent::normalizePropsSchema()
   */
  #[DataProvider('providerNormalizePropsSchema')]
  public function testNormalizePropsSchema(array $client_data, ?array $expected_component_props, ?array $expected_errors): void {
    $client_data = array_merge([
      'machineName' => 'normalize_props_test',
      'name' => 'Normalize Props Test',
      'status' => TRUE,
      'required' => [],
      'props' => [],
      'slots' => [],
      'sourceCodeJs' => 'console.log("test")',
      'sourceCodeCss' => '',
      'compiledJs' => 'console.log("test")',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'dataDependencies' => [],
    ], $client_data);
    $js_component = JavaScriptComponent::createFromClientSide($client_data);
    $violations = $js_component->getTypedData()->validate();
    if (\is_array($expected_errors)) {
      \assert(\is_null($expected_component_props));
      $this->assertSame(
        $expected_errors,
        self::violationsToArray($violations)
      );
      return;
    }
    \assert(\is_array($expected_component_props));
    self::assertCount(0, $violations);
    $this->assertSame(SAVED_NEW, $js_component->save());
    $this->assertSame($expected_component_props, $js_component->get('props'));
  }

}
