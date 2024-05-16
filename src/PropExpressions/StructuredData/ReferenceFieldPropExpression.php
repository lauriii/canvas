<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\StructuredData;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;

final class ReferenceFieldPropExpression implements StructuredDataPropExpressionInterface {

  public function __construct(
    public readonly FieldPropExpression $referencer,
    public readonly ReferenceFieldPropExpression|FieldPropExpression $referenced,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␜%s", mb_substr((string) $this->referencer, 1), mb_substr((string) $this->referenced, 1));
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
