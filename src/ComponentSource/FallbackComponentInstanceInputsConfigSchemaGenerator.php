<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

/**
 * @internal
 *
 * Fallback strategy that generates `type: ignore` config schema for components
 * without precise schema support.
 */
final readonly class FallbackComponentInstanceInputsConfigSchemaGenerator implements ComponentInstanceInputsConfigSchemaGeneratorInterface {

  /**
   * {@inheritdoc}
   */
  public function getConfigSchemaMapping(ComponentSourceInterface $component_source): array {
    $valid_inputs = \array_keys($component_source->getDefaultExplicitInput());
    $required_inputs = \array_keys($component_source->getDefaultExplicitInput(TRUE));

    // Generate a mapping definition based on the source's default explicit
    // inputs that are valid vs required. Assume none to be translatable; only a
    // concrete strategy can know which are translatable.
    $mapping_definition = [];
    foreach ($valid_inputs as $key) {
      $mapping_definition[$key] = [
        'type' => 'ignore',
      ];
      if (!\in_array($key, $required_inputs, TRUE)) {
        $mapping_definition[$key]['requiredKey'] = FALSE;
      }
    }

    // Note: It is impossible for this fallback strategy to generate an
    // appropriate `label` for each explicit input.
    // @todo Set `label` for each explicit input in https://www.drupal.org/i/3586490
    return $mapping_definition;
  }

  /**
   * {@inheritdoc}
   */
  public function refineForInstance(array $mapping, array $actual_inputs, string $component_id, string $component_version): array {
    // The Fallback generates only `type: ignore` with no `translatable` or
    // `form_element_class`, so no instance-level refinement is needed.
    return $mapping;
  }

}
