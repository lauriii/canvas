<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldItemInterface;

class FieldObjectPropsExpression implements StructuredDataPropExpressionInterface {

  public function __construct(
    // @todo will this break down once we support config entities? It must, because top-level config entity props ~= content entity fields, but deeper than that it is different.
    public readonly EntityDataDefinition $entityType,
    public readonly string $fieldName,
    // A content entity field item delta is optional.
    // @todo Should this allow expressing "all deltas"? Should that be represented using `NULL`, `TRUE`, `*` or `∀`? For now assuming NULL.
    public readonly int|null $delta,
    public readonly array $objectPropsToFieldProps,
  ) {
    assert(Inspector::assertAllStrings(array_keys($this->objectPropsToFieldProps)));
    assert(Inspector::assertAll(function ($expr) {
      return $expr instanceof FieldPropExpression || $expr instanceof ReferenceFieldPropExpression;
    }, $this->objectPropsToFieldProps));
  }

  public function __toString(): string {
    return sprintf(static::PREFIX . "␜%s␝%s␞%s␟{%s}", $this->entityType->getDataType(), $this->fieldName, $this->delta ?? '', implode(', ', array_map(
      fn (
        string $obj_prop_name,
        FieldPropExpression|ReferenceFieldPropExpression $expr
      ) => sprintf(
        '%s%s%s',
        $obj_prop_name,
        $expr instanceof ReferenceFieldPropExpression ? '↝' : '↠',
        $expr instanceof ReferenceFieldPropExpression ? $expr->referencer->propName . '␜' . (string) $expr->referenced : $expr->propName,
      ),
      array_keys($this->objectPropsToFieldProps),
      array_values($this->objectPropsToFieldProps),
    )));
  }

  public static function fromString(string $representation): static {
    [$entity_part, $remainder] = explode('␝', $representation, 2);
    $entity_data_definition = EntityDataDefinition::createFromDataType(mb_substr($entity_part, 3));
    [$field_name, $remainder] = explode('␞', $remainder, 2);
    [$delta, $object_mapping] = explode('␟', $remainder, 2);
    // Strip the surrounding curly braces.
    $object_mapping = mb_substr($object_mapping, 1, -1);

    $objectPropsToFieldTypeProps = [];
    foreach (explode(',', $object_mapping) as $obj_prop_mapping) {
      if (str_contains($obj_prop_mapping, '↠')) {
        [$sdc_obj_prop_name, $field_instance_prop_name] = explode('↠', $obj_prop_mapping);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new FieldPropExpression(
          $entity_data_definition,
          $field_name,
          $delta === '' ? NULL : (int) $delta,
          $field_instance_prop_name
        );
      }
      else {
        [$sdc_obj_prop_name, $obj_prop_mapping_remainder] = explode('↝', $obj_prop_mapping);
        [$field_instance_prop_name, $field_prop_ref_expr] = explode('␜', $obj_prop_mapping_remainder, 2);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new ReferenceFieldPropExpression(
          new FieldPropExpression($entity_data_definition, $field_name, NULL, $field_instance_prop_name),
          FieldPropExpression::fromString($field_prop_ref_expr)
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

  public function isSupported(EntityInterface|FieldItemInterface $entity): bool {
    assert($entity instanceof EntityInterface);
    $expected_entity_type_id = $this->entityType->getEntityTypeId();
    $expected_bundle = $this->entityType->getBundles()[0] ?? $expected_entity_type_id;
    if ($entity->getEntityTypeId() !== $expected_entity_type_id) {
      throw new \DomainException(sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `%s`.", (string) $this, $expected_entity_type_id, $entity->getEntityTypeId()));
    }
    if ($entity->bundle() !== $expected_bundle) {
      throw new \DomainException(sprintf("`%s` is an expression for entity type `%s`, bundle `%s`, but the provided entity is of the bundle `%s`.", (string) $this, $expected_entity_type_id, $expected_bundle, $entity->bundle()));
    }
    // @todo validate that the field exists?
    return TRUE;
  }

}
