<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * @internal
 *
 * @todo Fix upstream in core; \Drupal\text\Plugin\Field\FieldType\TextItemBase::applyDefaultValue() is broken due to its unsolved @todo!
 */
trait CoreBugFixTextItemBaseGenerateSampleValueTrait {

  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    $values = parent::generateSampleValue($field_definition);
    $settings = $field_definition->getSettings();
    if (count($settings['allowed_formats']) > 0) {
      $values['format'] = $settings['allowed_formats'][0];
    }
    return $values;
  }

}
