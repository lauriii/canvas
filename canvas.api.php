<?php

/**
 * @file
 * Documentation related to Drupal Canvas.
 */

use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\CandidateStorablePropShape;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;

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

  // The `type: object, $ref: json-schema-definitions://canvas.module/image`
  // shape allows picking any media of a media type powered by the "image" media
  // source by default.
  // Some sites may want to exclude certain media types, and/or add other media
  // types that use a different media source (with a different expression).
  // @see \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::hasBranch()
  // @see \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::withoutBranch()
  // @see \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::withAdditionalBranch()
  if (
    // "image" object shape?
    $storable_prop_shape->shape->schema['type'] === 'object'
    && isset($storable_prop_shape->shape->schema['$ref'])
    && $storable_prop_shape->shape->schema['$ref'] === 'json-schema-definitions://canvas.module/image'
    // Currently using media types?
    // @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
    && $storable_prop_shape->fieldTypeProp instanceof ReferenceFieldTypePropExpression
    && $storable_prop_shape->fieldTypeProp->getFieldType() === 'entity_reference'
    && $storable_prop_shape->fieldTypeProp->getTargetExpression()->getHostEntityDataDefinition()->getEntityTypeId() === 'media'
  ) {
    $expr = $storable_prop_shape->fieldTypeProp;
    $target_bundles = $storable_prop_shape->fieldInstanceSettings['handler_settings']['target_bundles'];

    // Exclude the "vacation_photos" media type: don't allow it to be stored in
    // the field, and update the expression.
    if ($expr->hasBranch('entity:media:vacation_photos')) {
      $target_bundles = array_diff_key($target_bundles, array_flip(['vacation_photos']));
      $expr = $expr->withoutBranch('entity:media:vacation_photos');
    }

    // Add the "remote_image" media type, which uses the oEmbed media source.
    // Allow it to be stored in the field, and update the expression.
    // @see https://www.drupal.org/project/media_remote_image
    $target_bundles = $target_bundles + ['remote_image' => 'remote_image'];
    $expr->withAdditionalBranch(new FieldPropExpression(
      entityType: BetterEntityDataDefinition::create('media', ['remote_image']),
      fieldName: 'field_media_remote_image',
      delta: NULL,
      // @todo Update this to use the relevant computed property instead of "non_existent_computed_property" after Canvas depends on a Drupal core version that includes https://www.drupal.org/project/drupal/issues/3567249
      propName: 'non_existent_computed_property',
    ));

    // Apply the updated changes.
    $storable_prop_shape->fieldTypeProp = $expr;
    $storable_prop_shape->fieldInstanceSettings['handler_settings']['target_bundles'] = $target_bundles;
  }
}

/**
 * @} End of "addtogroup hooks".
 */
