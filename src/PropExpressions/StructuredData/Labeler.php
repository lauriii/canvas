<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\canvas\ShapeMatcher\JsonSchemaFieldInstanceMatcher;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;

/**
 * Labels field instance expressions.
 *
 * @see FieldPropExpression
 * @see FieldObjectPropsExpression
 * @see ReferenceFieldPropExpression
 */
final class Labeler {

  /**
   * Computed a (hierarchical) label for a field instance expression.
   *
   * @param FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $expr
   *   A field instance expression.
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $actual_entity_type_and_bundle
   *   The actual entity type and bundle this expression will be evaluated for;
   *   necessary to generate a label when an expression describes how to
   *   evaluate multiple possible target bundles in a reference.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   A hierarchical label (with semantical hierarchy markers).
   *
   * @see ::flatten())
   */
  public static function label(FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $expr, EntityDataDefinitionInterface $actual_entity_type_and_bundle): TranslatableMarkup {
    $expression_entity_definition = $expr->getHostEntityDataDefinition();
    if ($expression_entity_definition->getEntityTypeId() !== $actual_entity_type_and_bundle->getEntityTypeId()) {
      throw new \LogicException(sprintf('Expression expects entity type `%s`, actual entity type is `%s`.', $expression_entity_definition->getEntityTypeId(), $actual_entity_type_and_bundle->getEntityTypeId()));
    }

    // To generate a label, the target entity type and bundle must be known.
    $actual_bundles = $actual_entity_type_and_bundle->getBundles();
    if (is_array($actual_bundles) && count($actual_bundles) > 1) {
      throw new \LogicException(sprintf('Multi-bundle entity definition given (`%s`), not allowed.', implode('`, `', $actual_bundles)));
    }

    // Bundle-specific expressions need further validation.
    $expression_bundles = $expression_entity_definition->getBundles();
    if ($expression_bundles !== NULL) {
      if ($actual_bundles === NULL) {
        throw new \LogicException(sprintf('Expression expects bundle `%s`, no bundle given.', implode(', ', $expression_bundles)));
      }
      if (count($expression_bundles) === 1 && reset($expression_bundles) !== reset($actual_bundles)) {
        throw new \LogicException(sprintf('Expression expects bundle `%s`, actual bundle is `%s`.', reset($expression_bundles), reset($actual_bundles)));
      }
      if (count($expression_bundles) > 1 && !in_array(reset($actual_bundles), $expression_bundles, TRUE)) {
        throw new \LogicException(sprintf('Expression expects one bundle of `%s`, actual bundle is `%s`.', implode('`, `', $expression_bundles), reset($actual_bundles)));
      }
    }

    $field_name = self::getFieldName($expr, $actual_entity_type_and_bundle);

    $field_definition = $actual_entity_type_and_bundle->getPropertyDefinition($field_name);
    if ($field_definition === NULL) {
      throw new \LogicException(sprintf("Field `%s` does not exist on `%s` entities.",
        $field_name,
        $actual_entity_type_and_bundle->getDataType(),
      ));
    }
    assert($field_definition instanceof FieldDefinitionInterface);
    assert($field_definition->getItemDefinition() instanceof FieldItemDataDefinitionInterface);

    // To correctly represent this, this must take into account what
    // JsonSchemaFieldInstanceMatcher may or may not match. It will
    // never match:
    // - DataReferenceTargetDefinition field props: it considers these
    //   irrelevant; it's only the twin DataReferenceDefinition that
    //   is relevant
    // - props explicitly marked as internal
    // @see \Drupal\Core\TypedData\DataDefinition::isInternal
    $main_property = $field_definition->getItemDefinition()->getMainPropertyName();
    assert(is_string($main_property));

    // When an expression targets a specific field item, generate an ordinal
    // suffix for the label.
    $delta = match (get_class($expr)) {
      FieldPropExpression::class, FieldObjectPropsExpression::class => $expr->delta,
      ReferenceFieldPropExpression::class => $expr->referencer->delta,
      default => NULL,
    };
    if ($delta !== NULL) {
      $human_delta = $delta + 1;
      $label_item_delta_parts = [
        StructuredDataPropExpressionInterface::PREFIX_FIELD_ITEM_LEVEL,
        t('@field-item-delta item'),
      ];
      $label_item_delta_arguments = [
        '@field-item-delta' => (new \NumberFormatter('en_US', \NumberFormatter::ORDINAL))->format($human_delta),
      ];
    }
    else {
      $label_item_delta_parts = [];
      $label_item_delta_arguments = [];
    }

    // Simpler label if the field's main property is used by the expression.
    if (self::usesMainProperty($expr, $field_definition, $actual_entity_type_and_bundle)) {
      $label_parts = [
        '@field-label',
        ...$label_item_delta_parts,
      ];
      $label_arguments = [
        '@field-label' => $field_definition->getLabel(),
        ...$label_item_delta_arguments,
      ];
      // @phpstan-ignore-next-line match.unhandled
      return match (TRUE) {
        $expr instanceof FieldPropExpression, $expr instanceof FieldObjectPropsExpression => new TranslatableMarkup(
          // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
          implode('', $label_parts),
          $label_arguments,
        ),
        // For UX purposes, consider references to File entities an
        // implementation detail irrelevant to the Site Builder: omit them from
        // the hierarchical label when following a reference. Result: it seems
        // that fields on Files are field properties on e.g. an image field or
        // on a media entity reference field.
        $expr->referenced->getHostEntityDataDefinition()->getEntityTypeId() === 'file' => new TranslatableMarkup(
          // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
          implode('', [
            ...$label_parts,
            StructuredDataPropExpressionInterface::PREFIX_FIELD_LEVEL,
            '@referenced',
          ]),
          [
            ...$label_arguments,
            '@referenced' => self::label($expr->referenced, $expr->referenced->getHostEntityDataDefinition()),
          ],
        ),
        // All non-File reference expressions.
        $expr->referenced->getHostEntityDataDefinition()->getEntityTypeId() !== 'file' => new TranslatableMarkup(
          // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
          implode('', [
            ...$label_parts,
            StructuredDataPropExpressionInterface::PREFIX_ENTITY_LEVEL,
            '@referenced-entity-type-bundle-label',
            StructuredDataPropExpressionInterface::PREFIX_FIELD_LEVEL,
            '@referenced',
          ]),
          [
            ...$label_arguments,
            '@referenced-entity-type-bundle-label' => $expr->referenced->getHostEntityDataDefinition()->getLabel(),
            '@referenced' => self::label($expr->referenced, $expr->referenced->getHostEntityDataDefinition()),
          ],
        ),
      };
    }

    // More complex label (with extra level of nesting) if the field's main
    // property is NOT used by the expression.
    $used_field_properties = (array) self::getUsedFieldProps($expr, $actual_entity_type_and_bundle);
    \assert(count($used_field_properties) >= 1);
    // A reference expression always follows the reference, which guarantees its
    // main field property is used.
    // @see ::usesMainProperty()
    \assert(!$expr instanceof ReferenceFieldPropExpression);
    return new TranslatableMarkup(
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      implode('', [
        '@field-label',
        ...$label_item_delta_parts,
        StructuredDataPropExpressionInterface::PREFIX_PROPERTY_LEVEL,
        '@field-item-properties-labels',
      ]),
      [
        '@field-label' => $field_definition->getLabel(),
        ...$label_item_delta_arguments,
        '@field-item-properties-labels' => implode(', ', array_map(
          // @phpstan-ignore-next-line method.nonObject
          fn (string $field_property_name): string => $field_definition->getItemDefinition()
            ->getPropertyDefinition($field_property_name)
            ->getLabel()
            ->__toString(),
          $used_field_properties,
        )),
      ],
    );
  }

  /**
   * Flattens hierarchical labels: strips semantical hierarchy markers with `→`.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $hierarchical_label
   *   A hierarchical label as generated by ::label()
   * @param array $map_levels_to_characters
   *   The mapping that determines what each semantical hierarchy marker gets
   *   replaced with. Defaults to ` → `.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   */
  public static function flatten(
    TranslatableMarkup $hierarchical_label,
    array $map_levels_to_characters = [
      StructuredDataPropExpressionInterface::PREFIX_ENTITY_LEVEL => ' → ',
      StructuredDataPropExpressionInterface::PREFIX_FIELD_LEVEL => ' → ',
      StructuredDataPropExpressionInterface::PREFIX_FIELD_ITEM_LEVEL => ' → ',
      StructuredDataPropExpressionInterface::PREFIX_PROPERTY_LEVEL => ' → ',
    ],
  ): TranslatableMarkup {
    return new TranslatableMarkup(
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      str_replace(
        array_keys($map_levels_to_characters),
        array_values($map_levels_to_characters),
        $hierarchical_label->getUntranslatedString(),
      ),
      array_map(
        fn (mixed $arg): mixed => $arg instanceof TranslatableMarkup
          ? self::flatten($arg, $map_levels_to_characters)
          : $arg,
        $hierarchical_label->getArguments(),
      )
    );
  }

  /**
   * @todo Make private.
   * @internal
   */
  public static function getFieldName(FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $expr, EntityDataDefinitionInterface $actual_entity_type_and_bundle): string {
    $expr_field_name = match (get_class($expr)) {
      ReferenceFieldPropExpression::class => $expr->referencer->fieldName,
      FieldPropExpression::class, FieldObjectPropsExpression::class => $expr->fieldName,
    };
    // TRICKY: FieldPropExpression::$fieldName can be an array, but only
    // when used in a reference.
    // @see https://www.drupal.org/i/3530521
    if (is_string($expr_field_name)) {
      return $expr_field_name;
    }
    \assert(is_array($actual_entity_type_and_bundle->getBundles()));
    \assert(array_keys($actual_entity_type_and_bundle->getBundles()) === [0]);
    $actual_bundle = $actual_entity_type_and_bundle->getBundles()[0];
    \assert(array_key_exists($actual_bundle, $expr_field_name));
    return $expr_field_name[$actual_bundle];
  }

  /**
   * @todo Make private.
   * @internal
   */
  public static function getUsedFieldProps(FieldPropExpression|ReferenceFieldPropExpression|FieldObjectPropsExpression $expr, EntityDataDefinitionInterface $actual_entity_type_and_bundle): string|array {
    $props = match (get_class($expr)) {
      FieldPropExpression::class => $expr->propName,
      ReferenceFieldPropExpression::class => $expr->referencer->propName,
      FieldObjectPropsExpression::class => array_map(
        fn (FieldPropExpression|ReferenceFieldPropExpression $obj_expr) => self::getUsedFieldProps($obj_expr, $actual_entity_type_and_bundle),
        $expr->objectPropsToFieldProps
      ),
    };

    // Multi-bundle expressions need extra care.
    if ($expr instanceof FieldPropExpression && is_array($expr->fieldName)) {
      // Even though a multi-bundle expression may target multiple fields, they
      // may all use the same field property.
      if (is_string($props)) {
        return $props;
      }
      \assert(!array_is_list($props));
      \assert(is_array($actual_entity_type_and_bundle->getBundles()));
      \assert(array_keys($actual_entity_type_and_bundle->getBundles()) === [0]);
      // Use the actual bundle to determine the actual field name, to in turn
      // determine the props actually used by this expression.
      $actual_bundle = $actual_entity_type_and_bundle->getBundles()[0];
      $actual_field = $expr->fieldName[$actual_bundle];
      $actual_props = $props[$actual_field];
      \assert(is_string($actual_props));
      return $actual_props;
    }

    // An array of props can only be returned for FieldObjectPropsExpressions.
    \assert(is_string($props) || ($expr instanceof FieldObjectPropsExpression && !array_is_list($props)));
    return $props;
  }

  private static function usesMainProperty(FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $expr, FieldDefinitionInterface $field_definition, EntityDataDefinitionInterface $actual_entity_type_and_bundle): bool {
    // Easiest case: a reference field's entire purpose is to reference, so
    // following the reference definitely is considered using the main property.
    if ($expr instanceof ReferenceFieldPropExpression) {
      return TRUE;
    }

    assert($field_definition->getItemDefinition() instanceof FieldItemDataDefinitionInterface);
    $main_property = $field_definition->getItemDefinition()->getMainPropertyName();
    assert(is_string($main_property));

    $used_props = (array) self::getUsedFieldProps($expr, $actual_entity_type_and_bundle);
    assert(count($used_props) >= 1);

    // Easy case: if the main property is used directly.
    if (in_array($main_property, $used_props, TRUE)) {
      return TRUE;
    }

    // Otherwise, check if one of the used field properties is a computed one
    // that depends on the main one.
    // Drupal core does not have native support for this; Canvas adds additional
    // metadata to be able to determine this. Any contributed field types that
    // wish to have computed properties automatically matched/suggested, need to
    // provide this additional metadata too.
    // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride
    // @see \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString
    foreach ($used_props as $prop_name) {
      $property_definition = $field_definition->getItemDefinition()->getPropertyDefinition($prop_name);
      if ($property_definition === NULL) {
        throw new \LogicException(sprintf("Property `%s` does not exist on field type `%s`. The following field properties exist: `%s`.",
          $prop_name,
          $field_definition->getType(),
          implode('`, `', array_keys($field_definition->getItemDefinition()->getPropertyDefinitions())),
        ));
      }

      // Second easy case: if this is a ReferenceFieldPropExpression, and one of
      // the used properties is the (computed) data reference definition, then
      // even though the main property is the target ID, conceptually the main
      // value of the field is still used.
      // @see \Drupal\Core\TypedData\DataReferenceTargetDefinition
      if ($property_definition instanceof DataReferenceDefinitionInterface) {
        return TRUE;
      }

      $expr_used_by_computed_property = JsonSchemaFieldInstanceMatcher::getReferenceDependency($property_definition);
      if ($expr_used_by_computed_property === NULL) {
        continue;
      }
      // Final sanity check: the reference expression found in the computed
      // property definition's settings MUST target the field type used by this
      // field instance.
      assert($expr_used_by_computed_property->referencer->fieldType === $field_definition->getType());
      return TRUE;
    }

    return FALSE;
  }

}
