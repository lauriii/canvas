<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Field\FieldItemInterface;

/**
 * For pointing to a prop in a concrete field.
 */
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
