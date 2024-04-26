<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;

/**
 * Architectural Decision Record
 *
 * Since instantiated components in:
 * - content type templates
 * - content entities
 * must be able to map values from structured data (entity field props) into
 * component props, and many APIs and layers are involved in doing this:
 * - correctly
 * - securely
 * - performantly
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
 *
 * @see https://github.com/SixArm/usv
 */

interface PropExpressionInterface extends \Stringable {
  public static function fromString(string $representation);
}

interface ComponentPropExpressionInterface extends PropExpressionInterface {
  // Components are for graphical representations.
  const PREFIX = '⿲';
}

interface StructuredDataPropExpressionInterface extends PropExpressionInterface {
  // Structured data contains information.
  const PREFIX = 'ℹ︎';

  public function isSupported(EntityInterface|FieldItemInterface $entity_or_field): bool;

}

// For pointing to a prop in a component.
final class ComponentPropExpression implements ComponentPropExpressionInterface {
  public function __construct(
    public readonly string $componentName,
    public readonly string $propName,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␟%s", $this->componentName, $this->propName);
  }

  public static function fromString(string $representation): static {
    $parts = explode('␟', mb_substr($representation, 1));
    return new static(...$parts);
  }

}

// For pointing to a prop in a field type (not considering any delta).
class FieldTypePropExpression implements StructuredDataPropExpressionInterface {
  public function __construct(
    public readonly string $fieldType,
    public readonly string $propName,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␟%s", $this->fieldType, $this->propName);
  }

  public static function fromString(string $representation): static {
    $parts = explode('␟', mb_substr($representation, 2));
    return new static(...$parts);
  }

  public function isSupported(EntityInterface|FieldItemInterface $field_item): bool {
    assert($field_item instanceof FieldItemInterface);
    $actual_field_type = $field_item->getFieldDefinition()->getType();
    if ($actual_field_type !== $this->fieldType) {
      throw new \DomainException(sprintf("`%s` is an expression for field type `%s`, but the provided field item is of type `%s`.", (string) $this, $this->fieldType, $actual_field_type));
    }
    return TRUE;
  }

}

// For pointing to a prop in a field type (not considering any delta).
class FieldTypeObjectPropsExpression implements StructuredDataPropExpressionInterface {
  public function __construct(
    public readonly string $fieldType,
    public readonly array $objectPropsToFieldTypeProps,
  ) {
    assert(Inspector::assertAllStrings(array_keys($this->objectPropsToFieldTypeProps)));
    assert(Inspector::assertAll(function ($expr) {
      return $expr instanceof FieldTypePropExpression || $expr instanceof ReferenceFieldTypePropExpression;
    }, $this->objectPropsToFieldTypeProps));
  }

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␟{%s}", $this->fieldType, implode(', ', array_map(
      fn (string $obj_prop_name, FieldTypePropExpression|ReferenceFieldTypePropExpression $expr) => sprintf('%s%s%s',
        $obj_prop_name,
        $expr instanceof ReferenceFieldTypePropExpression ? '↝' : '↠',
        $expr instanceof ReferenceFieldTypePropExpression ? $expr->propName . '␜' . (string) $expr->referenced : $expr->propName,
      ),
      array_keys($this->objectPropsToFieldTypeProps),
      array_values($this->objectPropsToFieldTypeProps),
    )));
  }

  public static function fromString(string $representation): static {
    [$field_type, $object_mapping] = explode('␟', mb_substr($representation, 2));
    // Strip the surrounding curly braces.
    $object_mapping = mb_substr($object_mapping, 1, -1);

    $objectPropsToFieldTypeProps = [];
    foreach (explode(',', $object_mapping) as $obj_prop_mapping) {
      if (str_contains($obj_prop_mapping, '↠')) {
        [$sdc_obj_prop_name, $field_type_prop_name] = explode('↠', $obj_prop_mapping);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new FieldTypePropExpression($field_type, $field_type_prop_name);
      }
      else {
        throw new \LogicException('not yet implemented');
      }
    }

    return new static($field_type, $objectPropsToFieldTypeProps);
  }

  public function isSupported(EntityInterface|FieldItemInterface $field_item): bool {
    assert($field_item instanceof FieldItemInterface);
    $actual_field_type = $field_item->getFieldDefinition()->getType();
    if ($actual_field_type !== $this->fieldType) {
      throw new \DomainException(sprintf("`%s` is an expression for field type `%s`, but the provided field item is of type `%s`.", (string) $this, $this->fieldType, $actual_field_type));
    }
    return TRUE;
  }

}

// For pointing to a prop in a field type (not considering any delta).
final class ReferenceFieldTypePropExpression extends FieldTypePropExpression {
  public function __construct(
    public readonly string $fieldType,
    public readonly string $propName,
    public readonly FieldPropExpression $referenced,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␜%s", mb_substr(parent::__toString(), 1), mb_substr((string) $this->referenced, 1));
  }

  public static function fromString(string $representation): static {
    throw new \Exception('todo');
  }

  public function isSupported(EntityInterface|FieldItemInterface $field_item): bool {
    assert($field_item instanceof FieldItemInterface);
    $actual_field_type = $field_item->getFieldDefinition()->getType();
    if ($actual_field_type !== $this->fieldType) {
      throw new \DomainException(sprintf("`%s` is an expression for field type `%s`, but the provided field item is of type `%s`.", (string) $this, $this->fieldType, $actual_field_type));
    }
    return TRUE;
  }

}

// For pointing to a prop in a concrete field.
final class FieldPropExpression implements StructuredDataPropExpressionInterface {
  public function __construct(
    // @todo will this break down once we support config entities? It must, because top-level config entity props ~= content entity fields, but deeper than that it is different.
    public readonly EntityDataDefinition $entityType,
    public readonly string $fieldName,
    // A content entity field item delta is optional.
    // @todo Should this allow expressing "all deltas"? Should that be represented using `NULL`, `TRUE`, `*` or `∀`? For now assuming NULL.
    public readonly int|null $delta,
    public readonly string $propName,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "␜%s␝%s␞%s␟%s", $this->entityType->getDataType(), $this->fieldName, $this->delta ?? '', $this->propName);
  }

  public function withDelta(int $delta): static {
    return new static(
      $this->entityType,
      $this->fieldName,
      $delta,
      $this->propName,
    );
  }

  public static function fromString(string $representation): static {
    [$entity_part, $remainder] = explode('␝', $representation);
    $entity_data_definition = EntityDataDefinition::createFromDataType(mb_substr($entity_part, 3));
    [$field_name, $remainder] = explode('␞', $remainder, 2);
    [$delta, $prop_name] = explode('␟', $remainder, 2);
    return new static(
      $entity_data_definition,
      $field_name,
      $delta === '' ? NULL : (int) $delta,
      $prop_name,
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

final class ReferenceFieldPropExpression implements StructuredDataPropExpressionInterface {

  public function __construct(
    public readonly FieldPropExpression $referencer,
    public readonly ReferenceFieldPropExpression|FieldPropExpression $referenced,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␜%s", mb_substr((string)$this->referencer, 1), mb_substr((string) $this->referenced, 1));
  }

  public static function fromString(string $representation): static {
    $parts = explode('␜', $representation);
    $referencer = FieldPropExpression::fromString($parts[0] . '␜' . $parts[1]);
    $referenced = FieldPropExpression::fromString(static::PREFIX . '␜' . $parts[3]);
    return new static($referencer, $referenced);
  }

  public function isSupported(EntityInterface|FieldItemInterface $entity): bool {
    assert($entity instanceof EntityInterface);
    $expected_entity_type_id = $this->referencer->entityType->getEntityTypeId();
    $expected_bundle = $this->referencer->entityType->getBundles()[0] ?? $expected_entity_type_id;
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

      fn (string $obj_prop_name, FieldPropExpression|ReferenceFieldPropExpression $expr) => sprintf('%s%s%s',
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

final class PropExpressionEvaluator {

  public static function evaluate(null|EntityInterface|FieldItemInterface $entity_or_field, StructuredDataPropExpressionInterface $expr): mixed {
    if ($entity_or_field === NULL) {
      // Entity is optional for reference fields: the reference may point to something or not.
      if ($expr instanceof ReferenceFieldPropExpression) {
        return NULL;
      }
      throw new \LogicException('No data provided to evaluate expression ' . (string)$expr);
    }

    // Assert that the received entity or field meets the needs of the
    // expression.
    try {
      $expr->isSupported($entity_or_field);
    }
    catch (\DomainException $e) {
      throw $e;
    }

    return match (get_class($expr)) {
      FieldPropExpression::class => $entity_or_field->get($expr->fieldName)[$expr->delta ?? 0]?->get($expr->propName)->getValue(),
      ReferenceFieldPropExpression::class => self::evaluate(
        self::evaluate($entity_or_field, $expr->referencer),
        $expr->referenced
      ),
      FieldTypePropExpression::class => $entity_or_field->get($expr->propName)->getValue(),
      FieldTypeObjectPropsExpression::class => array_combine(
        array_keys($expr->objectPropsToFieldTypeProps),
        array_map(
          fn (FieldTypePropExpression $sub_expr) => self::evaluate($entity_or_field, $sub_expr),
          $expr->objectPropsToFieldTypeProps
        )
      ),
      FieldObjectPropsExpression::class => array_combine(
        array_keys($expr->objectPropsToFieldProps),
        array_map(
          fn (FieldPropExpression|ReferenceFieldPropExpression $sub_expr) => self::evaluate($entity_or_field, $sub_expr),
          $expr->objectPropsToFieldProps
        )
      ),
    };
  }

}
