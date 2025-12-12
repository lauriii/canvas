<?php

/**
 * @file
 * Documentation related to Drupal Canvas.
 */

use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\CandidateStorablePropShape;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Implements hook_canvas_storable_prop_shape_alter().
 *
 * @see docs/shape-matching.md#3.1.2.b
 * @see docs/diagrams/components.md
 */
function hook_canvas_storable_prop_shape_alter(CandidateStorablePropShape $storable_prop_shape): void {
  // Override the default widget for prop shapes constrained by `enum`.
  if (array_key_exists('enum', $storable_prop_shape->shape->schema)) {
    $storable_prop_shape->fieldWidget = 'options_buttons';
  }

  // Override the default field type + widget for the `format: uri` string shape
  // from the `uri` field type to the `link` field type.
  // @see canvas_test_storage_prop_shape_alter_storage_prop_shape_alter()
  // @see \Drupal\Tests\canvas\Kernel\HookStoragePropAlterTest
  if ($storable_prop_shape->shape->schema == ['type' => 'string', 'format' => 'uri']) {
    // @see \Drupal\link\Plugin\Field\FieldType\LinkItem::propertyDefinitions()
    $storable_prop_shape->fieldTypeProp = StructuredDataPropExpression::fromString('ℹ︎link␟url');
    // @see \Drupal\link\Plugin\Field\FieldType\LinkItem::defaultFieldSettings()
    $storable_prop_shape->fieldInstanceSettings = [
      // This shape only needs the URI, not a title.
      'title' => DRUPAL_DISABLED,
    ];
    // @see \Drupal\link\Plugin\Field\FieldWidget\LinkWidget
    $storable_prop_shape->fieldWidget = 'link_default';
  }

  // The `type: string, format: duration` JSON schema does not have a field type
  // in Drupal core that supports that shape. A contrib module could add support
  // for it.
  // ⚠️ Any field widget that is used must have `canvas.transforms` defined on
  // the field widget's plugin definition. See hook_field_widget_info_alter().
  if ($storable_prop_shape->fieldTypeProp === NULL && $storable_prop_shape->shape->schema == ['type' => 'string', 'format' => 'duration']) {
    $storable_prop_shape->fieldTypeProp = new FieldTypePropExpression('contrib_duration_field', 'value');
    $storable_prop_shape->fieldWidget = 'fancy_duration_widget';
  }
}

/**
 * @} End of "addtogroup hooks".
 */
