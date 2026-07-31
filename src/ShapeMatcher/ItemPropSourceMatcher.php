<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Matches prop shapes against one item of the field an item template iterates.
 *
 * The iterated field's own properties, plus — through the reference — the
 * fields of the entity a reference item points at. Both are already found by
 * `EntityFieldPropSourceMatcher`, which walks exactly that Typed Data tree; the
 * only difference is the root. So this matcher asks that one for the array
 * version of the prop shape (an unlimited field only ever matches an array
 * prop), keeps the matches on the iterated field, and re-roots each expression
 * from the entity to the field type.
 *
 * That re-rooting is also what drops the delta: an item template does not know
 * which delta it is rendering.
 *
 * @see \Drupal\canvas\PropSource\ItemPropSource
 * @see docs/adr/0021-item-template-data-context-is-a-field-item.md
 *
 * @internal
 */
final class ItemPropSourceMatcher {

  public function __construct(
    private readonly EntityFieldPropSourceMatcher $entityFieldPropSourceMatcher,
  ) {}

  /**
   * Matches a prop shape against one item of the given field.
   *
   * @param bool $is_required
   *   Whether the component prop is required. Accepted for signature symmetry
   *   with the other matchers and deliberately not forwarded; see ::match().
   * @param \Drupal\canvas\PropShape\PropShape $prop_shape
   *   The prop shape one item must fill. Array prop shapes are never matched:
   *   an item is a single value.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $iterated_field
   *   The multi-value field the enclosing item template iterates.
   * @param string $host_entity_type_id
   *   The entity type the field belongs to.
   * @param string $host_entity_bundle
   *   The bundle the field belongs to.
   *
   * @return list<FieldTypePropExpression|ReferenceFieldTypePropExpression|FieldTypeObjectPropsExpression>
   *   Field-item-rooted expressions, sorted by their string representation.
   */
  public function match(bool $is_required, PropShape $prop_shape, FieldDefinitionInterface $iterated_field, string $host_entity_type_id, string $host_entity_bundle): array {
    $schema = $prop_shape->resolvedSchema;
    if (($schema['type'] ?? NULL) === 'array') {
      // One item is one value. An array prop inside an item template would
      // need an array of items per item, which no field shape expresses.
      return [];
    }
    // Ask for the list version of the shape, because that is what a
    // multiple-cardinality field matches; each match's per-item expression is
    // the one this matcher wants.
    // @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::matchEntityPropsForScalar()
    $list_shape = new PropShape(['type' => 'array', 'items' => $schema]);

    // A required prop is asked for as an optional one. Requiredness restricts a
    // host entity binding to a field that always has a value, because the
    // component renders whether or not the field is populated. An item template
    // is the opposite: it renders once per value that exists, so the item is
    // guaranteed by construction, and an empty field renders no items at all
    // rather than an item with nothing in it. Forwarding requiredness here
    // would offer no binding for any required prop of any component placed in a
    // template over an optional field — which is nearly every gallery.
    $matches = [];
    foreach ($this->entityFieldPropSourceMatcher->match(FALSE, $list_shape, $host_entity_type_id, $host_entity_bundle) as $prop_source) {
      $expression = $prop_source->expression;
      if ($expression->getFieldName() !== $iterated_field->getName()) {
        continue;
      }
      $item_expression = self::reRootAtFieldType($expression, $iterated_field->getType());
      if ($item_expression !== NULL) {
        $matches[(string) $item_expression] = $item_expression;
      }
    }
    \ksort($matches);
    return \array_values($matches);
  }

  /**
   * Converts an entity-rooted expression to its field-item-rooted equivalent.
   *
   * Returns NULL for expression shapes that have no field-item-rooted form.
   */
  private static function reRootAtFieldType(EntityFieldBasedPropExpressionInterface $expression, string $field_type): FieldTypePropExpression|ReferenceFieldTypePropExpression|FieldTypeObjectPropsExpression|NULL {
    if ($expression instanceof FieldPropExpression) {
      return \is_string($expression->propName)
        ? new FieldTypePropExpression($field_type, $expression->propName)
        : NULL;
    }
    if ($expression instanceof FieldObjectPropsExpression) {
      $object_props = [];
      foreach ($expression->getObjectExpressions() as $name => $sub) {
        $re_rooted = self::reRootAtFieldType($sub, $field_type);
        // A field type object expression only nests scalars and references.
        if ($re_rooted instanceof FieldTypePropExpression || $re_rooted instanceof ReferenceFieldTypePropExpression) {
          $object_props[$name] = $re_rooted;
        }
      }
      return $object_props === [] ? NULL : new FieldTypeObjectPropsExpression($field_type, $object_props);
    }
    // The referenced half is already entity-rooted and stays as it is: only the
    // referencer moves from "this field of this entity" to "this item".
    if ($expression instanceof ReferenceFieldPropExpression && \is_string($expression->referencer->propName)) {
      return new ReferenceFieldTypePropExpression(
        new FieldTypePropExpression($field_type, $expression->referencer->propName),
        $expression->referenced,
      );
    }
    return NULL;
  }

}
