<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests hydration of a multiple-cardinality prop that has no values.
 *
 * ::getExplicitInput() represents the absence of values for a
 * multiple-cardinality prop as the empty array, and a component iterating over
 * that prop needs it to stay an array. Hydration omits optional props that
 * evaluated to nothing, so it must tell "an object whose key-value pairs are
 * all empty", which is omitted, apart from "a list with no items", which is
 * not.
 *
 * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBaseTestBase::testHydrationAndRenderingEdgeCasesWithMediaBackedImageProp()
 * @see https://www.drupal.org/project/canvas/issues/3564392
 */
#[CoversClass(JsonSchemaPropsComponentSourceBase::class)]
#[Group('canvas')]
#[Group('canvas_component_sources')]
final class MultiValuePropHydrationTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('media');

    JavaScriptComponent::create([
      'machineName' => 'test',
      'name' => 'Test',
      'status' => TRUE,
      'props' => [
        'features' => [
          'type' => 'array',
          'title' => 'Features',
          'items' => ['type' => 'string'],
          'examples' => [['Alpha', 'Beta']],
        ],
      ],
      'required' => [],
      'slots' => [],
      'js' => [
        'original' => 'console.log("Test")',
        'compiled' => 'console.log("Test")',
      ],
      'css' => [
        'original' => '',
        'compiled' => '',
      ],
      'dataDependencies' => [],
    ])->save();
  }

  /**
   * Tests that an optional multiple-cardinality prop keeps its empty array.
   */
  public function testEmptyMultiValuePropIsRetained(): void {
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId('test'));
    self::assertInstanceOf(Component::class, $component);
    $prop_field_definitions = $component->getSettings()['prop_field_definitions'];
    self::assertFalse($prop_field_definitions['features']['required']);
    self::assertSame(-1, $prop_field_definitions['features']['cardinality']);

    $hydrated = $component->getComponentSource()->hydrateComponent(
      ['resolved' => ['features' => new EvaluationResult([])]],
      [],
      [],
    );

    $explicit_input = $hydrated[JsonSchemaPropsComponentSourceBase::EXPLICIT_INPUT_NAME];
    self::assertArrayHasKey('features', $explicit_input);
    self::assertSame([], $explicit_input['features']->value);
  }

}
