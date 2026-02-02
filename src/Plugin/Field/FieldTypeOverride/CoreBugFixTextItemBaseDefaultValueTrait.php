<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldTypeOverride;

/**
 * @internal
 *
 * @todo Fix upstream in core; \Drupal\text\Plugin\Field\FieldType\TextItemBase::applyDefaultValue() is broken due to its unsolved @todo!
 * @todo Consider moving this logic into \Drupal\filter\Plugin\DataType\FilterFormat::applyDefaultValue(), possible thanks to \Drupal\text\Plugin\Field\FieldType\TextItemBase::propertyDefinitions() passing it on
 */
trait CoreBugFixTextItemBaseDefaultValueTrait {

  public function applyDefaultValue($notify = TRUE) {
    $allowed_formats = $this->getDataDefinition()->getSetting('allowed_formats');
    $default_format = match (TRUE) {
      is_array($allowed_formats) && !empty($allowed_formats) => reset($allowed_formats),
      default => NULL,
    };
    \assert(is_null($default_format) || is_string($default_format));
    $this->setValue(['format' => $default_format], $notify);
    return $this;
  }

  public function setValue($values, $notify = TRUE): void {
    // If `format` is missing, fall back to the first allowed format from
    // settings if any.
    if (!is_array($values) || !array_key_exists('format', $values)) {
      $this->applyDefaultValue(FALSE);
      // Now `format` is guaranteed to be set, which is what is used below.
      \assert(array_key_exists('format', $this->values));
      $values = is_array($values)
        ? $values + $this->values
        // TRICKY: Drupal allows passing the main property directly, in that
        // case $values won't be an array.
        : ['value' => $values] + $this->values;
    }
    \assert(array_key_exists('format', $values));

    parent::setValue($values, FALSE);

    // Notify the parent if necessary.
    if ($notify && $this->parent) {
      $name = $this->getName();
      \assert(\is_string($name) || \is_int($name));
      $this->parent->onChange($name);
    }
  }

}
