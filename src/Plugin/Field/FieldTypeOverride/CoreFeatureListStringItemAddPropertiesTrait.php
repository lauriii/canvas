<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\canvas\Plugin\DataType\ListStringItemLabel;
use Drupal\canvas\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * @internal
 *
 * Adds properties to lists of strings field implementations.
 *
 * It adds:
 * - a `label` field property, which allows components to use the actual
 *   localized label (crucial for Canvas' ContentTemplates).
 */
trait CoreFeatureListStringItemAddPropertiesTrait {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $definitions = parent::propertyDefinitions($field_definition);
    $definitions['label'] = DataDefinition::create('string')
      // ⚠️ This label is visible in the UI.
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('Label for a string list'))
      ->setComputed(TRUE)
      ->setReadOnly(TRUE)
      ->setRequired($definitions['value']->isRequired())
      ->addConstraint('StringSemantics', StringSemanticsConstraint::PROSE)
      ->setClass(ListStringItemLabel::class);
    // The value property contains what is effectively a machine name: a
    // structured string, not prose. The challenge is it is impossible to know
    // what kind of structure the strings adhere to: it could be anything from
    // locale identifiers, color names, dark-vs-light, car makes, UUIDs…
    // That is why the computed "label" property above is introduced: to be
    // enable the value contained by this field to be mapped into components by
    // Canvas content authors.
    $definitions['value']->setSetting('is source for', 'label');
    return $definitions;
  }

}
