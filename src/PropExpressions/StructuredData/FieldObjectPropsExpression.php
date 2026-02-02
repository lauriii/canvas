<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;

final class FieldObjectPropsExpression implements EntityFieldBasedPropExpressionInterface, ObjectPropExpressionInterface {

  use CompoundExpressionTrait;
  use EntityFieldBasedExpressionTrait;

  /**
   * @param non-empty-array<string, (ScalarPropExpressionInterface&EntityFieldBasedPropExpressionInterface)|(ReferencePropExpressionInterface&EntityFieldBasedPropExpressionInterface)> $objectPropsToFieldProps
   *   A mapping of prop names to entity field-based expressions that yield
   *   scalar values or references.
   */
  public function __construct(
    // @todo will this break down once we support config entities? It must, because top-level config entity props ~= content entity fields, but deeper than that it is different.
    public readonly EntityDataDefinitionInterface $entityType,
    public readonly string $fieldName,
    // A content entity field item delta is optional.
    // @todo Should this allow expressing "all deltas"? Should that be represented using `NULL`, `TRUE`, `*` or `∀`? For now assuming NULL.
    public readonly int|null $delta,
    public readonly array $objectPropsToFieldProps,
  ) {
    \assert(!empty($this->objectPropsToFieldProps));
    \assert(Inspector::assertAllStrings(array_keys($this->objectPropsToFieldProps)));
    \assert(Inspector::assertAll(function ($expr) {
      return $expr instanceof FieldPropExpression || $expr instanceof ReferenceFieldPropExpression;
    }, $this->objectPropsToFieldProps));
    array_walk($objectPropsToFieldProps, function (EntityFieldBasedPropExpressionInterface $expr) {
      $targets_same_field_item = $this->hasSameStartingPointAs($expr);
      if (!$targets_same_field_item) {
        throw new \InvalidArgumentException(\sprintf(
          '`%s` is not a valid expression, because it does not map the same field item (entity type `%s`, field name `%s`, delta `%s`).',
          (string) $expr,
          $this->entityType->getDataType(),
          $this->fieldName,
          $this->delta === NULL ? 'null' : (string) $this->delta
        ));
      }
    });
  }

  public function __toString(): string {
    return static::PREFIX_EXPRESSION_TYPE
      . static::PREFIX_ENTITY_LEVEL . $this->entityType->getDataType()
      . static::PREFIX_FIELD_LEVEL . $this->fieldName
      . static::PREFIX_FIELD_ITEM_LEVEL . ($this->delta ?? '')
      . static::PREFIX_PROPERTY_LEVEL . static::PREFIX_OBJECT
      . implode(',', array_map(
        function (
          string $obj_prop_name,
          (ScalarPropExpressionInterface&EntityFieldBasedPropExpressionInterface)|(ReferencePropExpressionInterface&EntityFieldBasedPropExpressionInterface) $expr,
        ) {
          $tail = match ($expr::class) {
            ReferenceFieldPropExpression::class => $expr->referencer->getFieldPropertyName() . static::PREFIX_ENTITY_LEVEL . self::withoutExpressionTypePrefix((string) $expr->referenced),
            default => $expr->getFieldPropertyName(),
          };
          return \sprintf(
            '%s%s%s',
            $obj_prop_name,
            $expr instanceof ReferenceFieldPropExpression
              ? self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE
              : self::SYMBOL_OBJECT_MAPPED_USE_PROP,
            $tail,
          );
        },
        array_keys($this->objectPropsToFieldProps),
        array_values($this->objectPropsToFieldProps),
      ))
      . static::SUFFIX_OBJECT;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    \assert($host_entity === NULL || $host_entity instanceof FieldableEntityInterface);
    $dependencies = [];
    foreach ($this->objectPropsToFieldProps as $expr) {
      $dependencies = NestedArray::mergeDeep($dependencies, $expr->calculateDependencies($host_entity));
    }
    return $dependencies;
  }

  public function withDelta(int $delta): static {
    return new static(
      $this->entityType,
      $this->fieldName,
      $delta,
      $this->objectPropsToFieldProps,
    );
  }

  public static function fromString(string $representation): static {
    [$entity_part, $remainder] = explode(self::PREFIX_FIELD_LEVEL, $representation, 2);
    $entity_data_definition = BetterEntityDataDefinition::createFromDataType(mb_substr($entity_part, 3));
    [$field_name, $remainder] = explode(self::PREFIX_FIELD_ITEM_LEVEL, $remainder, 2);
    [$delta, $object_mapping] = explode(self::PREFIX_PROPERTY_LEVEL, $remainder, 2);
    // Strip the surrounding curly braces.
    $object_mapping = mb_substr($object_mapping, 1, -1);

    $objectPropsToFieldTypeProps = [];
    foreach (explode(',', $object_mapping) as $obj_prop_mapping) {
      if (str_contains($obj_prop_mapping, self::SYMBOL_OBJECT_MAPPED_USE_PROP)) {
        [$sdc_obj_prop_name, $field_instance_prop_name] = explode(self::SYMBOL_OBJECT_MAPPED_USE_PROP, $obj_prop_mapping);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new FieldPropExpression(
          $entity_data_definition,
          $field_name,
          $delta === '' ? NULL : (int) $delta,
          $field_instance_prop_name
        );
      }
      else {
        [$sdc_obj_prop_name, $obj_prop_mapping_remainder] = explode(self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE, $obj_prop_mapping);
        [$field_instance_prop_name, $field_prop_ref_expr] = explode(self::PREFIX_ENTITY_LEVEL, $obj_prop_mapping_remainder, 2);
        $referenced = StructuredDataPropExpression::fromString(self::PREFIX_EXPRESSION_TYPE . $field_prop_ref_expr);
        \assert($referenced instanceof ReferenceFieldPropExpression || $referenced instanceof FieldPropExpression || $referenced instanceof FieldObjectPropsExpression);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new ReferenceFieldPropExpression(
          new FieldPropExpression($entity_data_definition, $field_name, NULL, $field_instance_prop_name),
          $referenced,
        );
      }
    }

    return new static(
      $entity_data_definition,
      $field_name,
      $delta === '' ? NULL : (int) $delta,
      $objectPropsToFieldTypeProps
    );
  }

  public function validateSupport(EntityInterface|FieldItemInterface|FieldItemListInterface $entity): void {
    \assert($entity instanceof EntityInterface);
    $expected_entity_type_id = $this->entityType->getEntityTypeId();
    if ($entity->getEntityTypeId() !== $expected_entity_type_id) {
      throw new \DomainException(\sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `%s`.", (string) $this, $expected_entity_type_id, $entity->getEntityTypeId()));
    }
    $expected_bundles = $this->entityType->getBundles();
    \assert($expected_bundles === NULL || count($expected_bundles) === 1);
    if ($expected_bundles !== NULL && $entity->bundle() !== $expected_bundles[0]) {
      throw new \DomainException(\sprintf("`%s` is an expression for entity type `%s`, bundle `%s`, but the provided entity is of the bundle `%s`.", (string) $this, $expected_entity_type_id, $expected_bundles[0], $entity->bundle()));
    }
    // @todo validate that the field exists?
  }

  /**
   * {@inheritdoc}
   */
  public function getHostEntityDataDefinition(): EntityDataDefinitionInterface {
    return $this->entityType;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldName(): string {
    return $this->fieldName;
  }

  /**
   * {@inheritdoc}
   */
  public function getDelta(): ?int {
    return $this->delta;
  }

  /**
   * {@inheritdoc}
   *
   * @return non-empty-array<string, (\Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface)|(\Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface)>
   */
  public function getObjectExpressions(): array {
    return $this->objectPropsToFieldProps;
  }

}
