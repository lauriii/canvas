<?php

/**
 * @file
 * Documentation related to Experience Builder.
 */

use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropShape\CandidateStorablePropShape;

/**
 * @defgroup experience_builder_architecture Experience Builder Architecture
 * @{
 *
 * @section prop_expressions Prop Expressions
 *
 * Since instantiated components in:
 * - content type templates
 * - content entities
 * must be able to map values from structured data (field props) into component
 * props, and many APIs and layers are involved in doing this:
 * - correctly
 * - securely
 * - performant
 * It seems sensible to use a strongly typed approach to representing these
 * expressions.
 *
 * Furthermore, the Experience Builder UX must make it easy to surface viable
 * matches from the structured data that can fit in the components, as well as
 * the other way around.
 *
 * Therefore a base expression interface is provided, which guarantees a
 * stringable representation (simplifying both debugging as well as storing
 * these expressions), *and* the conversion back.
 * In other words: every possible expression used by Experience Builder can
 * always be converted from string to PHP object and vice versa.
 *
 * String representations of prop expressions probing into:
 * - components will always start with the symbol `⿲`
 * - structured data will always start with the symbol `ℹ`
 *
 *
 * String and storage representation of expressions referencing field types,
 * field instances, fields aka field item lists, field deltas aka field items,
 * field item properties:
 * - `␟` is the field item VS property name separator, because a field property
 *   is the smallest unit
 * - `␞` then is the field item list vs field item separator
 * - `␝` then is the field item list vs field item separator
 * - `␜` then is the entity vs field item list separator
 *
 * @see \Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface
 * @see https://github.com/SixArm/usv
 *
 * @}
 */

/**
 * Implements hook_storage_prop_shape_alter().
 */
function hook_storage_prop_shape_alter(CandidateStorablePropShape $storable_prop_shape): void {
  // Override the default widget for prop shapes constrained by `enum`.
  if (array_key_exists('enum', $storable_prop_shape->shape->schema)) {
    $storable_prop_shape->fieldWidget = 'options_buttons';
  }

  // Override the default field type + widget for the `format: uri` string shape
  // from the `uri` field type to the `link` field type.
  // @see xb_test_storage_prop_shape_alter_storage_prop_shape_alter()
  // @see \Drupal\Tests\experience_builder\Kernel\HookStoragePropAlterTest
  if ($storable_prop_shape->shape->schema == ['type' => 'string', 'format' => 'uri']) {
    // @see \Drupal\link\Plugin\Field\FieldType\LinkItem::propertyDefinitions()
    $storable_prop_shape->fieldTypeProp = StructuredDataPropExpression::fromString('ℹ︎link␟uri');
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
  // ⚠️ Any field widget that is used must have `xb.transforms` defined on the
  // field widget's plugin definition. See hook_field_widget_info_alter().
  if ($storable_prop_shape->fieldTypeProp === NULL && $storable_prop_shape->shape->schema == ['type' => 'string', 'format' => 'duration']) {
    $storable_prop_shape->fieldTypeProp = new FieldTypePropExpression('contrib_duration_field', 'value');
    $storable_prop_shape->fieldWidget = 'fancy_duration_widget';
  }
}

/**
 * Implements hook_field_widget_info_alter().
 *
 * Any field widgets defined to be used in hook_storage_prop_shape_alter() MUST
 * have a corresponding `xb.transforms` defined in their plugin definition.
 *
 * These "transforms" allow a field widget's value to be extracted on the client
 * side, resulting in the instantaneous previews XB users expect.
 *
 * XB's list of available field widget transforms:
 * - mainProperty
 * - firstRecord
 * - dateTime
 * - mediaSelection
 * - link
 *
 * @see docs/redux-integrated-field-widgets.md
 * @see experience_builder_field_widget_info_alter()
 */
function mymodule_field_widget_info_alter(array &$info): void {
  $info['options_buttons']['xb'] = [
    'transforms' => [
      // @todo Analyze the field widget PHP code, assign appropriate transforms.
    ],
  ];
  $info['link_default']['xb'] = [
    'transforms' => [
      // @todo Analyze the field widget PHP code, assign appropriate transforms.
    ],
  ];
  $info['fancy_duration_widget']['xb'] = [
    'transforms' => [
      // @todo Analyze the field widget PHP code, assign appropriate transforms.
    ],
  ];
}

/**
 * @addtogroup hooks
 * @{
 */

/**
 * @} End of "addtogroup hooks".
 */
