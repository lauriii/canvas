<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface;
use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface;
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
      $translatable = self::isTranslatableShape($prop_shape);
      // A translatable shape may still be backed by an entity reference — e.g.
      // an image `src` stored as `['target_id' => 1]`, whose field type prop
      // expression is a reference expression. A reference is not author-entered
      // text: there is nothing to translate, config translations may only hold
      // translatable strings (see ADR 0010), and storing a (necessarily empty)
      // translation breaks rendering. (A literal URL `src` is not a reference,
      // so it remains translatable.)
      if ($translatable && self::isReferenceBackedProp($component_source, $prop_name)) {
        $translatable = FALSE;
      }
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
   * Whether a component input's JSON schema shape holds translatable text.
   *
   * Single source of truth for which prop *shapes* contain author-entered,
   * translatable text — used both to generate the config schema (here) and to
   * extract TMGMT translatables, so the two never disagree (a disagreement
   * would make TMGMT offer an input the config schema rejects on save, or vice
   * versa). Translatable shapes are plain strings, HTML (rich) strings and
   * URI-esque strings. So:
   * - type: string (single-line)
   * - type: string, pattern: (.|\r?\n)* (multi-line)
   * - type: string, format: iri
   * - type: string, format: iri-reference
   * - type: string, format: uri
   * - type: string, format: uri-reference
   * - type: string, contentMediaType: text/html
   * - type: string, contentMediaType: text/html, x-formatting-context: inline
   * - type: string, contentMediaType: text/html, x-formatting-context: block
   *
   * Cardinality is irrelevant — an array of translatable items is translatable
   * — so this peeks inside `type: array`. It does NOT account for whether the
   * prop is populated by an entity reference (structured data, not author
   * text); callers handle that separately.
   *
   * @param array $prop_shape
   *   A normalized JSON schema prop shape.
   *
   * @return bool
   *   TRUE if the shape holds translatable text.
   *
   * @see \Drupal\canvas\PropShape\PropShape::isPlainOrRichProse()
   * @see \Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor
   * @todo Consider adding alter hook to allow more shapes to be translatable in https://drupal.org/i/3584178
   * @internal
   */
  public static function isTranslatableShape(array $prop_shape): bool {
    // For translatability, cardinality is irrelevant, only the shape matters,
    // so peek inside any array prop at its item shape. Only an array carries an
    // `items` key, so checking the key (rather than `type`) works whether the
    // shape has been normalized or is still raw SDC metadata.
    if (\array_key_exists('items', $prop_shape) && \is_array($prop_shape['items'])) {
      $prop_shape = $prop_shape['items'];
    }
    // SDC appends `object` to every declared `type` (the declared type stays
    // first); normalize to a scalar before comparing.
    $type = isset($prop_shape['type']) ? \strtolower(((array) $prop_shape['type'])[0]) : NULL;
    return PropShape::isPlainOrRichProse($prop_shape)
      || (
        $type === 'string'
        && \array_key_exists('format', $prop_shape)
        && JsonSchemaStringFormat::tryFrom($prop_shape['format'])?->isUriEsque()
      );
  }

  /**
   * Whether a prop is populated by an entity reference.
   *
   * Determined from the prop's field type prop expression — i.e. the
   * component's field definition for the prop, not any instance's resolved
   * inputs. An entity reference (`entity_reference`, `image`, `file`, media
   * reference) yields a reference expression.
   *
   * A reference's value is structured data, not author-entered text, so it is
   * not a translatable input — regardless of how translatable its JSON Schema
   * shape looks (e.g. a URI-reference string), and for both config and content
   * translation. (Translating the referenced entity itself is a separate
   * concern, handled on that entity, not on this prop.)
   */
  private static function isReferenceBackedProp(JsonSchemaPropsComponentSourceBase $component_source, string $prop_name): bool {
    try {
      $static_prop_source = $component_source->getDefaultStaticPropSource($prop_name, FALSE);
    }
    catch (\OutOfRangeException) {
      // This prop has no field definition in this component version, so it
      // cannot be populated by a StaticPropSource; the shape-based
      // translatability stands.
      return FALSE;
    }
    return $static_prop_source->expression instanceof ReferencePropExpressionInterface;
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
