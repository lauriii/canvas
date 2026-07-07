<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface;
use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget;
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
    // Build the mapping exclusively from the *versioned* prop field
    // definitions — never from the live implementation's JSON Schema
    // (`::getMetadata()`), which cannot describe past component versions. A
    // prop removed from (or modified in) the live implementation keeps its
    // schema mapping entry for the versions its instances reference —
    // otherwise their previously-valid stored inputs would become "'…' is not
    // a supported key".
    $prop_field_definitions = $component_source->getConfiguration()['prop_field_definitions'] ?? [];

    $mapping_definition = [];
    foreach ($prop_field_definitions as $prop_name => $prop_field_definition) {
      $mapping_definition[$prop_name] = [
        'type' => 'ignore',
      ];
      if (!($prop_field_definition['required'] ?? FALSE)) {
        $mapping_definition[$prop_name]['requiredKey'] = FALSE;
      }
      if ($component_source->isExplicitInputTranslatable($prop_name)) {
        $mapping_definition[$prop_name]['translatable'] = TRUE;
        // The label is cosmetic — unlike the mapping structure above, it may
        // come from the live metadata: a prop that no longer exists there
        // safely falls back to its name, and a stale title cannot invalidate
        // stored config.
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
    // A populated input without a field definition in this component version —
    // e.g. a prop whose shape is not storable — must still validate, but only
    // when populated by a non-static prop source (structured data, such as a
    // DynamicPropSource). It is not translatable here; its value may be
    // translated at its source (e.g. the entity field it evaluates). A static
    // prop source for an unknown prop remains unsupported: that is
    // author-entered data for a prop this component version cannot store.
    foreach (\array_keys($actual_inputs) as $key) {
      if (!\array_key_exists($key, $mapping) && !self::isStaticPropSource($actual_inputs[$key])) {
        $mapping[$key] = [
          'type' => 'ignore',
          'requiredKey' => FALSE,
        ];
      }
    }

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
