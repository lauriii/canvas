<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface;
use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropSource\PropSource;

/**
 * @internal
 */
final readonly class JsonSchemaPropsComponentInstanceInputsConfigSchemaGenerator implements ComponentInstanceInputsConfigSchemaGeneratorInterface {

  /**
   * {@inheritdoc}
   */
  public function getConfigSchemaMapping(ComponentSourceInterface $component_source): array {
    \assert($component_source instanceof JsonSchemaPropsComponentSourceBase);
    ['required' => $required, 'shapes' => $shapes] = $component_source->getExplicitInputDefinitions();

    $normalized_shapes = \array_map(
      fn (array $raw_json_schema): array => PropShape::normalize(PropShape::standardize($raw_json_schema)->resolvedSchema)->schema,
      $shapes,
    );

    $mapping_definition = [];
    foreach ($normalized_shapes as $prop_name => $prop_shape) {
      $mapping_definition[$prop_name] = [
        'type' => 'ignore',
      ];
      if (!\in_array($prop_name, $required, TRUE)) {
        $mapping_definition[$prop_name]['requiredKey'] = FALSE;
      }
      // For translatability, cardinality is irrelevant, only the shape matters,
      // so peek inside any `type: array` and get the actual shape.
      if ($prop_shape['type'] === JsonSchemaType::Array->value) {
        \assert(\array_key_exists('items', $prop_shape));
        $prop_shape = $prop_shape['items'];
        \assert(\is_array($prop_shape));
      }
      // Plain strings, HTML strings and URLs are considered translatable. So:
      // - type: string
      // - type: string: format: iri
      // - type: string: format: iri-reference
      // - type: string: format: uri
      // - type: string: format: uri-reference
      // - type: string, contentMediaType: text/html
      // - type: string, contentMediaType: text/html,
      //   x-formatting-context: inline
      // - type: string, contentMediaType: text/html,
      //   x-formatting-context: block
      // @todo Consider adding alter hook to allow more shapes to be translatable in https://drupal.org/i/3584178
      $translatable = PropShape::isPlainOrRichProse($prop_shape)
        || (
          $prop_shape['type'] === 'string'
          && \array_key_exists('format', $prop_shape)
          && JsonSchemaStringFormat::tryFrom($prop_shape['format'])?->isUriEsque()
        );
      if ($translatable) {
        \assert(\array_key_exists('type', $mapping_definition[$prop_name]));
        $mapping_definition[$prop_name]['translatable'] = TRUE;
        $mapping_definition[$prop_name]['label'] = $component_source->getMetadata()->schema['properties'][$prop_name]['title'] ?? $prop_name;
        // Reuse Canvas field widgets rather than core's config_translation
        // Textfield/TextFormat form element classes. This single class handles
        // all field types — both single-property (StringItem) and
        // multi-property (TextLongItem, LinkItem) — by conjuring the same
        // field widget that the Canvas UI uses.
        $mapping_definition[$prop_name]['form_element_class'] = CanvasStaticPropSourceFieldWidget::class;
      }
    }

    return $mapping_definition;
  }

  /**
   * {@inheritdoc}
   */
  public function refineForInstance(array $mapping, array $actual_inputs, string $component_id, string $component_version): array {
    // Only user input provided by the Content Author (so: StaticPropSource) is
    // translatable. Structured data is not.
    foreach (\array_keys($mapping) as $key) {
      if (\array_key_exists($key, $actual_inputs) && !self::isStaticPropSource($actual_inputs[$key])) {
        // TRICKY: `translatable: false` is not respected by TMGMT!
        // @see \Drupal\tmgmt_config\DefaultConfigProcessor::extractTranslatables()
        unset($mapping[$key]['translatable']);
        unset($mapping[$key]['form_element_class']);
      }
    }

    // Inject component context into translatable prop definitions so that
    // \Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget can
    // conjure the correct field widget at config translation time.
    // TRICKY: the component source plugin does not have access to its
    // corresponding Component config entity ID/version. Those are not
    // present in the instantiated source plugin's configuration array.
    foreach (\array_keys($mapping) as $key) {
      if (\array_key_exists('form_element_class', $mapping[$key])) {
        $mapping[$key]['_canvas_config_translation_form_element_context'] = [
          'component_id' => $component_id,
          'component_version' => $component_version,
          'prop_name' => $key,
        ];
      }
    }

    // @todo Consider adding alter hook to allow a specific SDC or code component's prop to be translatable (rather than all props of that shape) in https://drupal.org/i/3584178

    return $mapping;
  }

  /**
   * Checks if the given value for an explicit input is a static prop source.
   *
   * Public yet internal, to allow Canvas' TMGMT logic to reuse this.
   *
   * @internal
   */
  public static function isStaticPropSource(mixed $value): bool {
    // Detect an optimized explicit input.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::optimizeExplicitInputs()
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::collapse()
    if (!\is_array($value) || !\array_key_exists('sourceType', $value)) {
      return TRUE;
    }
    return PropSource::parse($value)->getSourceType() === PropSource::Static->value;
  }

}
