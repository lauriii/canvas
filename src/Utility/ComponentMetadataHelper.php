<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\Component\ComponentMetadata;

/**
 * Provides helper methods for ComponentMetadata.
 *
 * @see \Drupal\Core\Theme\Component\ComponentMetadata
 * @internal
 *
 * @phpstan-import-type JsonSchema from \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType
 */
final class ComponentMetadataHelper {

  /**
   * Gets the JSON schema definitions for non-attribute SDC properties.
   *
   * @param \Drupal\Core\Theme\Component\ComponentMetadata $metadata
   *
   * @return array<string, JsonSchema>
   *
   * @see \Drupal\Core\Theme\Component\ComponentMetadata::parseSchemaInfo()
   */
  public static function getNonAttributeComponentProperties(ComponentMetadata $metadata): array {
    $component_properties = [];
    /** @var array<string, mixed> $component_schema */
    $component_schema = $metadata->schema ?? [];
    foreach ($component_schema['properties'] ?? [] as $prop_name => $prop_schema) {
      // TRICKY: `Attribute`-typed props are a special case that we need to
      // ignore. Even more TRICKY, `attributes` named prop is even a more
      // special case — as it's initialized by default.
      // @see \Drupal\sdc\Twig\TwigExtension::mergeAdditionalRenderContext()
      // @see https://www.drupal.org/project/drupal/issues/3352063#comment-15277820
      // @see `canvas_test_sdc:attributes` component template as an example for
      // how to initialize the `Attribute`-typed prop.
      if (in_array(Attribute::class, $prop_schema['type'], TRUE)) {
        continue;
      }
      $component_properties[$prop_name] = $prop_schema;
    }
    return $component_properties;
  }

}
