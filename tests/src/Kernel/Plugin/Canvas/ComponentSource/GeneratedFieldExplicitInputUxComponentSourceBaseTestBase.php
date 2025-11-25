<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
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

    // TRICKY: DynamicPropSources can only be used in ContentTemplates and hence
    // no host entity is known, which in turn causes the detailed validation for
    // it to be skipped thanks to MissingHostEntityException
    // being thrown.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\ValidComponentTreeItemConstraintValidator::validate()
    self::assertCount(0, $source->validateComponentInput(
      [
        'image' => [
          'sourceType' => 'dynamic',
          'expression' => 'ℹ︎␜entity:user␝user_picture␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
        ],
      ] + $resolved,
      $this->randomString(),
      NULL,
    ));

    // Rendering MUST always succeed. It will only succeed if hydration is
    // smart enough to omit both optional props that are NULL(ish).
    $hydrated = $source->hydrateComponent(['resolved' => $resolved], []);
    // @phpstan-ignore-next-line offsetAccess.notFound
    self::assertSame($is_object_prop_present_in_hydration, array_key_exists('image', $hydrated[GeneratedFieldExplicitInputUxComponentSourceBase::EXPLICIT_INPUT_NAME]));
    $build = $source->renderComponent($hydrated, [], $this->randomString(), FALSE);
    $html = (string) $this->renderer->renderInIsolation($build);
    if (str_starts_with($expected_html, '<')) {
      self::assertSame($expected_html, $html);
    }
    else {
      self::assertStringContainsString($expected_html, $html);
    }
  }

}
