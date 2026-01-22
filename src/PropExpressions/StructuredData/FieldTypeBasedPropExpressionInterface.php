<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

/**
 * An expression that has a field type as the starting point.
 *
 * (By contrast, EntityFieldBasedPropExpressionInterface is for expressions that
 * have an entity field as the starting point.)
 *
 * These are used to evaluate data from stand-alone field instances containing
 * static data that is not attached to a specific entity: Canvas' so-called
 * "static prop sources". This allows a Canvas component tree to populate
 * components that do not have a native input UX (for example SDCs) to be
 * populated from static field data (i.e. which does not live in entity fields).
 *
 * Note the absence of a delta concept here, since it wouldn't make sense for a
 * static prop source to be populated with data that would then not be passed to
 * a component instance.
 * By contrast, it is possible that e.g. only the first delta of a multi-valued
 * entity field is used to populate a component instance.
 *
 * @see \Drupal\canvas\PropSource\StaticPropSource
 *
 * @internal
 */
interface FieldTypeBasedPropExpressionInterface extends StructuredDataPropExpressionInterface {

  /**
   * Gets the field type that is the root context of this expression.
   *
   * @return string
   *   A field type plugin ID, such as `string`, `uri`, `entity_reference`, etc.
   */
  public function getFieldType(): string;

}
