<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\Plugin\Validation\Constraint\StringSemanticsConstraint;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringLongItem;

/**
 * @todo Fix upstream.
 */
class StringLongItemOverride extends StringLongItem {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['value']->addConstraint('StringSemantics', [
      'semantic' => StringSemanticsConstraint::PROSE,
    ]);
    // Marks the property as accepting any string, including newlines, so that
    // multi-line string component props can match it. Built via patternToPcre()
    // so it is byte-identical to the prop-side requirement it must match. The
    // pattern matches every string and is never actually evaluated.
    // @see \Drupal\canvas\Validation\JitSafeRegexValidator
    $properties['value']->addConstraint('Regex', [
      'pattern' => JsonSchemaType::patternToPcre('(.|\r?\n)*'),
    ]);
    return $properties;
  }

}
