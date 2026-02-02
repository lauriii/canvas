<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\canvas\ShapeMatcher\JsonSchemaFieldInstanceMatcher;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;

/**
 * Labels entity field expressions.
 *
 * @see \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface
 */
final class Labeler {

  /**
   * Computed a (hierarchical) label for an entity field expression.
   *
   * @param \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface $expr
   *   An entity field expression.
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
  public static function label(EntityFieldBasedPropExpressionInterface $expr, EntityDataDefinitionInterface $actual_entity_type_and_bundle): TranslatableMarkup {
    $expression_entity_definition = $expr->getHostEntityDataDefinition();
    if ($expression_entity_definition->getEntityTypeId() !== $actual_entity_type_and_bundle->getEntityTypeId()) {
      throw new \LogicException(\sprintf('Expression expects entity type `%s`, actual entity type is `%s`.', $expression_entity_definition->getEntityTypeId(), $actual_entity_type_and_bundle->getEntityTypeId()));
    }

    // To generate a label, the target entity type and bundle must be known.
    $actual_bundles = $actual_entity_type_and_bundle->getBundles();
    if (is_array($actual_bundles) && count($actual_bundles) > 1) {
      throw new \LogicException(\sprintf('Multi-bundle entity definition given (`%s`), not allowed.', implode('`, `', $actual_bundles)));
    }

    // Bundle-specific expressions need further validation.
    $expression_bundles = $expression_entity_definition->getBundles();
    if ($expression_bundles !== NULL) {
      \assert(count($expression_bundles) === 1);
      if ($actual_bundles === NULL) {
        throw new \LogicException(\sprintf('Expression expects bundle `%s`, no bundle given.', implode(', ', $expression_bundles)));
      }
      if (reset($expression_bundles) !== reset($actual_bundles)) {
        throw new \LogicException(\sprintf('Expression expects bundle `%s`, actual bundle is `%s`.', reset($expression_bundles), reset($actual_bundles)));
      }
    }

    $field_name = $expr->getFieldName();
    $field_definition = $actual_entity_type_and_bundle->getPropertyDefinition($field_name);
    if ($field_definition === NULL) {
      throw new \LogicException(\sprintf("Field `%s` does not exist on `%s` entities.",
        $field_name,
        $actual_entity_type_and_bundle->getDataType(),
      ));
    }
    \assert($field_definition instanceof FieldDefinitionInterface);
    \assert($field_definition->getItemDefinition() instanceof FieldItemDataDefinitionInterface);

    // To correctly represent this, this must take into account what
    // JsonSchemaFieldInstanceMatcher may or may not match. It will
    // never match:
    // - DataReferenceTargetDefinition field props: it considers these
    //   irrelevant; it's only the twin DataReferenceDefinition that
    //   is relevant
    // - props explicitly marked as internal
    // @see \Drupal\Core\TypedData\DataDefinition::isInternal
    $main_property = $field_definition->getItemDefinition()->getMainPropertyName();
    \assert(is_string($main_property));

    // When an expression targets a specific field item, generate an ordinal
    // suffix for the label.
    $delta = $expr->getDelta();
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

      // Non-reference expression: simple label.
      if (!$expr instanceof ReferencePropExpressionInterface) {
        return new TranslatableMarkup(
          // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
          implode('', $label_parts),
          $label_arguments,
        );
      }

      // Multi-bundle reference expression: convey only the reachable entity
      // type and bundles, do not recurse further even though this may omit
      // crucial information.
      // @todo Refine: consider (conditionally) recursing to better inform Canvas content template authors in https://www.drupal.org/i/3563309
      if ($expr->targetsMultipleBundles()) {
        // @phpstan-ignore property.notFound
        \assert($expr->referenced instanceof ReferencedBundleSpecificBranches);
        $referenceable_bundle_labels = array_map(
          // @phpstan-ignore return.type
          fn (EntityFieldBasedPropExpressionInterface $bundle_specific_expr): string => $bundle_specific_expr->getHostEntityDataDefinition()->getLabel(),
          $expr->referenced->bundleSpecificReferencedExpressions,
        );
        return new TranslatableMarkup(
          // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
          implode('', [
            ...$label_parts,
            StructuredDataPropExpressionInterface::PREFIX_ENTITY_LEVEL,
            '@referenced-entity-bundle-labels',
          ]),
          $label_arguments + [
            '@referenced-entity-bundle-labels' => implode(', ', $referenceable_bundle_labels),
          ],
        );
      }

      // Reference expression: convey the reference in the label, but use
      // heuristics to keep it user-friendly.
      $targets_file_entity_type = $expr->getTargetExpression()->getHostEntityDataDefinition()->getEntityTypeId() === 'file';
      $label_arguments = [
        ...$label_arguments,
        '@referenced' => self::label(
          $expr->getTargetExpression(),
          $expr->getTargetExpression()->getHostEntityDataDefinition(),
        ),
      ];
      return match ($targets_file_entity_type) {
        // For UX purposes, consider references targeting File entities an
        // implementation detail irrelevant to the Site Builder: omit them from
        // the hierarchical label when following a reference. Result: it seems
        // that fields on Files are field properties on e.g. an image field or
        // on a media entity reference field.
        TRUE => new TranslatableMarkup(
          // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
          implode('', [
            ...$label_parts,
            StructuredDataPropExpressionInterface::PREFIX_FIELD_LEVEL,
            '@referenced',
          ]),
          $label_arguments,
        ),
        // All non-File target reference expressions.
        FALSE => new TranslatableMarkup(
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
            '@referenced-entity-type-bundle-label' => $expr->getTargetExpression()->getHostEntityDataDefinition()->getLabel(),
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
          fn (string $field_property_name): string => (string) $field_definition->getItemDefinition()
            ->getPropertyDefinition($field_property_name)
            ->getLabel(),
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
  public static function getUsedFieldProps(EntityFieldBasedPropExpressionInterface $expr, EntityDataDefinitionInterface $actual_entity_type_and_bundle): string|array {
    $props = match (TRUE) {
      $expr instanceof ObjectPropExpressionInterface => array_map(
        // PHPStan incorrectly flags this error. It fails to realize that the
        // argument is of the correct type.
        // @phpstan-ignore argument.type
        fn ($obj_expr) => self::getUsedFieldProps($obj_expr, $actual_entity_type_and_bundle),
        $expr->getObjectExpressions(),
      ),
      $expr instanceof ScalarPropExpressionInterface,
      $expr instanceof ReferencePropExpressionInterface => $expr->getFieldPropertyName(),
      default => throw new \LogicException('Unhandled expression type.'),
    };

    // An array of props can only be returned for object expressions.
    \assert(is_string($props) || ($expr instanceof ObjectPropExpressionInterface && !array_is_list($props)));
    return $props;
  }

  private static function usesMainProperty(EntityFieldBasedPropExpressionInterface $expr, FieldDefinitionInterface $field_definition, EntityDataDefinitionInterface $actual_entity_type_and_bundle): bool {
    // Easiest case: a reference field's entire purpose is to reference, so
    // following the reference definitely is considered using the main property.
    if ($expr instanceof ReferencePropExpressionInterface) {
      return TRUE;
    }

    $field_item_definition = $field_definition->getItemDefinition();
    \assert($field_item_definition instanceof FieldItemDataDefinitionInterface);
    $main_property = $field_item_definition->getMainPropertyName();
    \assert(is_string($main_property));

    $used_props = (array) self::getUsedFieldProps($expr, $actual_entity_type_and_bundle);
    \assert(count($used_props) >= 1);

    // Easy case: if the main property is used directly.
    if (in_array($main_property, $used_props, TRUE)) {
      return TRUE;
    }

    // Otherwise, check if one of the used field properties is a computed one
    // that depends on the main one.
    $main_property_definition = $field_item_definition->getPropertyDefinition($main_property);
    \assert($main_property_definition instanceof DataDefinitionInterface);
    if (in_array($main_property_definition->getSetting('is source for'), $used_props, TRUE)) {
      return TRUE;
    }

    // Drupal core does not have native support for this; Canvas adds additional
    // metadata to be able to determine this. Any contributed field types that
    // wish to have computed properties automatically matched/suggested, need to
    // provide this additional metadata too.
    // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride
    // @see \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString
    foreach ($used_props as $prop_name) {
      $property_definition = $field_item_definition->getPropertyDefinition($prop_name);
      if ($property_definition === NULL) {
        throw new \LogicException(\sprintf("Property `%s` does not exist on field type `%s`. The following field properties exist: `%s`.",
          $prop_name,
          $field_definition->getType(),
          implode('`, `', array_keys($field_item_definition->getPropertyDefinitions())),
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
      \assert($expr_used_by_computed_property->getFieldType() === $field_definition->getType());
      return TRUE;
    }

    return FALSE;
  }

}
