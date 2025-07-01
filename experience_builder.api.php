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
 *
 * @section xb_extensions XB Extensions
 *
 * XB Extensions makes additional functionalities and customization points
 * available for extending the Experience Builder module.
 *
 * XB Extensions can only be defined in modules. Themes are not supported.
 *
 * Any library with `drupalSettings.xbExtension` will be identified as an
 * Experience Builder extension and will be loaded with the UI. Be sure
 * to add `experience_builder/ui` as a dependency.
 * So, your `*.libraries.yml` file should contain an entry that looks similar to this:
 * @code
 * app:
 *  header: true
 *  js:
 *    path/to/your/xb/extension/something.js:
 *      attributes: { type: module }
 *  drupalSettings:
 *    xbExtension:
 *      testExtension: {
 *        id: 'experience-builder-test-extension',
 *        name: 'XB Test Extension',
 *        description: 'A test extension for Experience Builder.',
 *        imgSrc: 'relative/path/from/your/module/optionalImage.png'
 *      }
 *  dependencies:
 *    - experience_builder/xb-ui
 *
 * @see tests/modules/xb_test_extension/ui/index.jsx for how to wrap your
 * React Application so it has access to Experience Builder UI APIs
 * @see tests/modules/xb_test_extension/ui/components/ConceptProver.jsx for
 * examples of how these APIs can be used to access info about and make
 * changes to content in the UI.
 *
 * This functionality is currently experimental and a more formal API will be available in future versions.
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
 * @see \Drupal\experience_builder\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetInfoAlter()
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
