<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin;

use Drupal\Component\Plugin\CategorizingPluginManagerInterface;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use JsonSchema\Constraints\BaseConstraint;
use JsonSchema\SchemaStorage;

/**
 * @todo Remove this once Canvas relies on a Drupal core version that includes https://www.drupal.org/i/3352063.
 * @internal
 */
class ComponentPluginManager extends CoreComponentPluginManager implements CategorizingPluginManagerInterface {

  const MAXIMUM_RECURSION_LEVEL = 10;

  protected static bool $isRecursing = FALSE;

  /**
   * JSON schema storage utility used for resolving references.
   */
  protected SchemaStorage $schemaStorage;

  /**
   * {@inheritdoc}
   *
   * @phpstan-ignore-next-line missingType.parameter
   */
  public function processDefinition(&$definition, $plugin_id): void {
    parent::processDefinition($definition, $plugin_id);
    if (isset($definition['props']['properties']) && is_array($definition['props']['properties']) && !empty($definition['props']['properties'])) {
      $definition['props'] = $this->resolveJsonSchemaReferences($definition['props'], 0);
    }
  }

  /**
   * Resolves schema references recursively.
   *
   * @param array $schema
   *   JSON schema of a component.
   * @param int $depth
   *   Depth index to avoid infinite recursion.
   *
   * @return array
   *   JSON schema of a component, with references resolved.
   */
  public function resolveJsonSchemaReferences(array $schema, int $depth = 0): array {
    $this->schemaStorage ??= new SchemaStorage();

    if ($depth > self::MAXIMUM_RECURSION_LEVEL) {
      return $schema;
    }

    $depth++;

    $schema = BaseConstraint::arrayToObjectRecursive($schema);
    $refSchema = (array) $this->schemaStorage->resolveRefSchema($schema);
    $schema = (array) $schema;
    unset($schema['$ref']);

    // Merge referenced schema into the current schema.
    $schema += $refSchema;

    // Recursively resolve nested objects.
    foreach ($schema as $key => $value) {
      if (is_object($value)) {
        $schema[$key] = $this->resolveJsonSchemaReferences((array) $value, $depth);
      }
    }

    // It looks heavy as a solution to convert objects to array recursively,
    // but it is exactly the inverse of what
    // BaseConstraint::arrayToObjectRecursive() is doing.
    $json = json_encode($schema);
    \assert(is_string($json));
    return json_decode($json, TRUE);
  }

}
