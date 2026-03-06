<?php

declare(strict_types=1);

namespace Drupal\canvas\CoreBugFix;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Schema\TypeResolver;
use Drupal\Core\Config\TypedConfigManager;

/**
 * @internal
 *
 * @todo Fix upstream in core in https://www.drupal.org/project/drupal/issues/3400181
 *
 * @see Cloned from Drupal 11.3.5, TypedConfigManager::getDefinitionWithReplacements()
 */
final class TypedConfigManagerWithCachePollutionFix extends TypedConfigManager {

  /**
   * {@inheritdoc}
   */
  protected function getDefinitionWithReplacements($base_plugin_id, array $replacements, $exception_on_invalid = TRUE) {
    $definitions = $this->getDefinitions();
    $type = $this->determineType($base_plugin_id, $definitions);
    $definition = $definitions[$type];
    // Check whether this type is an extension of another one and compile it.
    if (isset($definition['type'])) {
      $merge = $this->getDefinition($definition['type'], $exception_on_invalid);
      // Preserve integer keys on merge, so sequence item types can override
      // parent settings as opposed to adding unused second, third, etc. items.
      $definition = NestedArray::mergeDeepArray([$merge, $definition], TRUE);

      // Replace dynamic portions of the definition type.
      if (!empty($replacements) && strpos($definition['type'], ']')) {
        $sub_type = $this->determineType(TypeResolver::resolveDynamicTypeName($definition['type'], $replacements), $definitions);
        $sub_definition = $definitions[$sub_type];
        if (isset($definitions[$sub_type]['type'])) {
          $sub_merge = $this->getDefinition($definitions[$sub_type]['type'], $exception_on_invalid);
          $sub_definition = NestedArray::mergeDeepArray([$sub_merge, $definitions[$sub_type]], TRUE);
        }
        // Merge the newly determined subtype definition with the original
        // definition.
        $definition = NestedArray::mergeDeepArray([$sub_definition, $definition], TRUE);
        $type = "$type||$sub_type";
      }
      // Unset type so we try the merge only once per type.
      unset($definition['type']);
      // 💡The only change: wrap in a condition to prevent cache pollution!
      if (!empty($replacements)) {
        $this->definitions[$type] = $definition;
      }
    }
    // Add type and default definition class.
    $definition += [
      'definition_class' => '\Drupal\Core\TypedData\DataDefinition',
      'type' => $type,
      'unwrap_for_canonical_representation' => TRUE,
    ];
    return $definition;
  }

}
