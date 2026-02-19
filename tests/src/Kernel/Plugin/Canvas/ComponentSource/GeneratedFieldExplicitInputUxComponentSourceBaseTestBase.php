<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Tests\canvas\Traits\GenerateComponentConfigTrait;

/**
 * @coversDefaultClass \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase
 */
abstract class GeneratedFieldExplicitInputUxComponentSourceBaseTestBase extends ComponentSourceTestBase {

  use GenerateComponentConfigTrait;

  /**
   * Data provider for ::testHydrationAndRenderingEdgeCases().
   *
   * TRICKY: the example value specified in the SDC is not used here, because an
   * explicit value was specified, it just happened to be completely empty (case
   * 3) or equivalent to empty (case 2).
   */
  public static function providerHydrationAndRenderingEdgeCases(): array {
    return [
      'populated optional object prop' => [
        [
          "src" => "/cat.jpg",
          "alt" => "🦙",
          "width" => 1,
          "height" => 1,
        ],
        TRUE,
        '<h1>Yo</h1><img src="/cat.jpg" alt="🦙" width="1" height="1"></img>',
      ],
      // This can occur when a DynamicPropSource is populating an optional
      // `type: object`-shaped prop, and that DynamicPropSource happens to
      // resolve to a set of key-value pairs with all NULL values because the
      // field it points to may be optional, too.
      'NULLish optional object prop' => [
        [
          "src" => NULL,
          "alt" => NULL,
          "width" => NULL,
          "height" => NULL,
        ],
        FALSE,
        '<h1>Yo</h1>',
      ],
      // This can occur when a DynamicPropSource is populating an optional prop
      // that is not `type: object`.
      'NULL optional object prop' => [
        NULL,
        FALSE,
        '<h1>Yo</h1>',
      ],
    ];
  }

  /**
   * @covers ::hydrateComponent
   * @covers ::renderComponent
   * @dataProvider providerHydrationAndRenderingEdgeCases
   */
  public function testHydrationAndRenderingEdgeCases(?array $resolved_explicit_input_values_for_object_prop, bool $is_object_prop_present_in_hydration, string $expected_html): void {
    $this->generateComponentConfig();
    // @phpstan-ignore-next-line property.notFound
    $component_with_optional_image_object_shape = Component::load($this->componentWithOptionalImageProp);
    self::assertNotNull($component_with_optional_image_object_shape);
    $source = $component_with_optional_image_object_shape->getComponentSource();
    $resolved = [
      'heading' => new EvaluationResult('Yo'),
      'image' => new EvaluationResult($resolved_explicit_input_values_for_object_prop),
    ];

    // Allow for reuse among different ComponentSource plugins using this base
    // class, without requiring each of the test components to have exactly the
    // same props. The only requirement is an optional `image` prop.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getExplicitInputDefinitions()
    $resolved = array_intersect_key($resolved, $component_with_optional_image_object_shape->getSettings()['prop_field_definitions']);

    // TRICKY: EntityFieldPropSources can only be used in ContentTemplates and hence
    // no host entity is known, which in turn causes the detailed validation for
    // it to be skipped thanks to MissingHostEntityException
    // being thrown.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\ValidComponentTreeItemConstraintValidator::validate()
    self::assertCount(0, $source->validateComponentInput(
      [
        'image' => [
          'sourceType' => PropSource::EntityField->value,
          'expression' => 'ℹ︎␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
        ],
      ] + $resolved,
      $this->randomString(),
      NULL,
    ));

    // Rendering MUST always succeed. It will only succeed if hydration is
    // smart enough to omit both optional props that are NULL(ish).
    // Hydration needs values for required props in the active version, to ensure
    // rendering of live implementation component instances using old Component
    // versions succeeds.
    $active_required_explicit_inputs = $component_with_optional_image_object_shape
      ->loadVersion($component_with_optional_image_object_shape->getActiveVersion())
      ->getComponentSource()
      ->getDefaultExplicitInput(only_required: TRUE);
    $hydrated = $source->hydrateComponent(['resolved' => $resolved], [], $active_required_explicit_inputs);
    // @phpstan-ignore-next-line offsetAccess.notFound
    self::assertSame($is_object_prop_present_in_hydration, \array_key_exists('image', $hydrated[GeneratedFieldExplicitInputUxComponentSourceBase::EXPLICIT_INPUT_NAME]));
    $build = $source->renderComponent($hydrated, [], $this->randomString(), FALSE);
    $html = (string) $this->renderer->renderInIsolation($build);
    if (str_starts_with($expected_html, '<')) {
      self::assertSame($expected_html, $html);
    }
    else {
      self::assertStringContainsString($expected_html, $html);
    }
  }

  /**
   * Data provider for ::testValidateComponentInputRejectsUnexpectedProps().
   *
   * @return array<string, array{source_id: string, source_specific_id: string, valid_prop_name: string, valid_prop_input: array<string, mixed>}>
   *   Returns an array of test cases containing:
   *   - source_id: The component source plugin ID (e.g., 'js', 'sdc')
   *   - source_specific_id: The source-specific component ID
   *   - valid_prop_name: Name of a valid prop that exists on the component
   *   - valid_prop_input: A complete, valid input array for that prop
   */
  abstract public static function providerComponentForValidateInputRejectsUnexpectedProps(): array;

  /**
   * Tests that validateComponentInput() rejects unexpected props (garbage values).
   *
   * @covers ::validateComponentInput
   * @dataProvider providerComponentForValidateInputRejectsUnexpectedProps
   */
  public function testValidateComponentInputRejectsUnexpectedProps(string $source_id, string $source_specific_id, string $valid_prop_name, array $valid_prop_input): void {
    $this->generateComponentConfig();

    $component_source_manager = $this->container->get(ComponentSourceManager::class);
    \assert($component_source_manager instanceof ComponentSourceManager);
    $component_source_definition = $component_source_manager->getDefinition($source_id);
    \assert(\array_key_exists('discovery', $component_source_definition));
    $discovery = $this->container->get('class_resolver')->getInstanceFromDefinition($component_source_definition['discovery']);
    \assert($discovery instanceof ComponentCandidatesDiscoveryInterface);
    $component_id = $discovery::getComponentConfigEntityId($source_specific_id);

    $component = Component::load($component_id);
    $this->assertInstanceOf(Component::class, $component);

    $source = $component->getComponentSource();
    $this->assertInstanceOf(GeneratedFieldExplicitInputUxComponentSourceBase::class, $source);

    $uuid = '07875b1b-b68c-4b90-955c-d6136ff8af93';

    // Test with unexpected prop - should fail validation.
    $input_with_garbage = [
      $valid_prop_name => $valid_prop_input,
      'textUnwanted' => [
        'sourceType' => 'static:field_item:string',
        'value' => [['value' => 'Unwanted value']],
        'expression' => 'ℹ︎string␟value',
      ],
    ];
    $violations = $source->validateComponentInput($input_with_garbage, $uuid, NULL);
    $this->assertCount(1, $violations, 'Input with one unexpected prop should produce one violation');

    $violation = $violations->get(0);
    $this->assertSame("Component `$uuid`: the `textUnwanted` prop is not defined.", $violation->getMessage());
    $this->assertSame("inputs.$uuid.textUnwanted", $violation->getPropertyPath());

    // Test with multiple unexpected props.
    $input_with_multiple_garbage = [
      $valid_prop_name => $valid_prop_input,
      'textUnwanted' => [
        'sourceType' => 'static:field_item:string',
        'value' => [['value' => 'Unwanted value']],
        'expression' => 'ℹ︎string␟value',
      ],
      'anotherBadProp' => [
        'sourceType' => 'static:field_item:string',
        'value' => [['value' => 'Another unwanted value']],
        'expression' => 'ℹ︎string␟value',
      ],
    ];
    $violations = $source->validateComponentInput($input_with_multiple_garbage, $uuid, NULL);
    $this->assertCount(2, $violations, 'Input with two unexpected props should produce two violations');

    $violation_messages = \array_map(fn($v) => $v->getMessage(), iterator_to_array($violations));
    $this->assertContains("Component `$uuid`: the `textUnwanted` prop is not defined.", $violation_messages);
    $this->assertContains("Component `$uuid`: the `anotherBadProp` prop is not defined.", $violation_messages);
  }

}
