<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

/**
 * @internal
 *
 * Defines an interface for generating component instance inputs config schema.
 *
 * This generated config schema serves 2 purposes:
 * - it is used to ensure a consistent structure whenever instances of this
 *   component are stored in a config entity (a "config-defined" component tree)
 * - its metadata determines which inputs of the instance are translatable
 *
 * Examples:
 * - Block components' explicit inputs are called "settings". They are described
 *   in config schema already, so generating config schema for them is trivial.
 * - SDC and code components' explicit inputs are called "props". They are
 *   described in JSON schema, and they store "optimized prop sources", which
 *   are impossible to describe in config schema. For these, a coarse config
 *   schema can be generated: enumerating all props, and marking some as
 *   translatable (varying per instance depending on how they are populated).
 *
 * A fallback is provided too, which must use the public API methods on
 * ComponentSourceInterface to determine all existing explicit inputs and which
 * of those are required.
 *
 * @see \Drupal\canvas\Attribute\ComponentSource::__construct(inputs_config_schema_generator)
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface
 * @see \Drupal\canvas\ComponentSource\FallbackComponentInstanceInputsConfigSchemaGenerator
 *
 * @phpstan-type ConfigSchemaTypeUntranslatable array{type: string, ...}
 * @phpstan-type ConfigSchemaTypePossiblyTranslatable array{type: string, label?: string, translatable?: true, form_element_class?: string, ...}
 * @phpstan-type ConfigSchemaTypeWithTranslatability ConfigSchemaTypeUntranslatable|ConfigSchemaTypePossiblyTranslatable
 */
interface ComponentInstanceInputsConfigSchemaGeneratorInterface {

  /**
   * Generates a config schema mapping definition for the Component version.
   *
   * The result provides the structure but may be further refined per-instance
   * (based on the actual stored explicit inputs) by refineForInstance().
   *
   * @param \Drupal\canvas\ComponentSource\ComponentSourceInterface $component_source
   *   The component source plugin instance for a particular Component version.
   *
   * @return array<string, ConfigSchemaTypeWithTranslatability>
   *   The generated config schema mapping definition.
   */
  public function getConfigSchemaMapping(ComponentSourceInterface $component_source): array;

  /**
   * Applies component instance-specific refinements.
   *
   * @param array<string, mixed> $mapping
   *   Mapping from getConfigSchemaMapping().
   * @param array<string, mixed> $actual_inputs
   *   The actual stored explicit inputs. This allows for example:
   *   - deciding to disallow translating an EntityFieldPropSource
   *   - injecting additional context for use in the Config Translation UI
   *    (preferably in an underscore-prefixed key-value pair)
   * @param string $component_id
   *   The component config entity ID.
   * @param string $component_version
   *   The component version.
   *
   * @return array<string, ConfigSchemaTypeWithTranslatability>
   *   The refined config schema mapping definition.
   */
  public function refineForInstance(array $mapping, array $actual_inputs, string $component_id, string $component_version): array;

}
