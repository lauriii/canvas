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

final class ReferenceFieldPropExpression implements StructuredDataPropExpressionInterface {

  use CompoundExpressionTrait;

  public function __construct(
    public readonly FieldPropExpression $referencer,
    public readonly ReferenceFieldPropExpression|FieldPropExpression|FieldObjectPropsExpression $referenced,
  ) {
    // References can start from a multi-target-bundle reference field, with the
    // referenced result then either:
    // - using the same field type (e.g. multiple bundles with different fields,
    //   but all of the `image` field type)
    // - referencing the same entity type (e.g. multiple bundles with different
    //   fields but all pointing to a `File` entity)
    // @todo Consider adding branching support to allow per-bundle expressions resulting in the same shape in https://www.drupal.org/project/canvas/issues/3550750
    if ($this->referencer->isMultiBundle() && $this->referenced->isMultiBundle()) {
      throw new \InvalidArgumentException('A reference expression must start from a single entity reference field, and hence must start in a single entity type + bundle, UNLESS there is a single target.');
    }
  }

  public function __toString(): string {
    return static::PREFIX
      . self::withoutPrefix((string) $this->referencer)
      . self::PREFIX_ENTITY_LEVEL
      . self::withoutPrefix((string) $this->referenced);
  }

  /**
   * Gets the references chain prefixes: without the leaf (a non-reference).
   *
   * These prefixes allow checking whether two different expressions overlap or
   * not.
   *
   * For example:
   * 1. `node:foo`'s `uid` field points to a `user` entity
   * 2. `user`'s `user_picture` field points to a `file` entity
   * 3. `file` `uri` field's `url` field property is targeted
   *
   * Then this method would return 1+2, not 3, because 1+2 are both reference
   * expressions, the 3rd is merely fetching a value on the given entity. Hence
   * it would return
   *
   * @code
   * ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜
   * @endcode
   * and
   * @code
   * ℹ︎␜entity:node:foo␝revision_uid␞␟entity␜␜entity:user␝user_picture␞␟entity␜
   * @endcode
   *
   * (The last one is always the full/maximal chain.)
   *
   * @return non-empty-array<string>
   *   All reference chain prefixes of this reference expression. One or more.
   */
  public function getReferenceChainPrefixes(): array {
    $chain = (string) $this->referencer . self::PREFIX_ENTITY_LEVEL;
    $chain_prefixes = [$chain];

    $additional = [];
    if ($this->referenced instanceof ReferenceFieldPropExpression) {
      // @see ::__toString()
      $additional = array_map(
        fn (string $recursion_result): string => $chain . self::withoutPrefix($recursion_result),
        $this->referenced->getReferenceChainPrefixes()
      );
    }
    return [
      ...$chain_prefixes,
      ...$additional,
    ];
  }

  public function getFullReferenceChain(): string {
    $prefixes = $this->getReferenceChainPrefixes();
    $full = end($prefixes);
    return $full;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    assert($host_entity === NULL || $host_entity instanceof FieldableEntityInterface);
    $dependencies = $this->referencer->calculateDependencies($host_entity);
    if ($host_entity === NULL) {
      $dependencies = NestedArray::mergeDeep($dependencies, $this->referenced->calculateDependencies());
    }
    else {
      // ⚠️ Do not require values while calculating dependencies: this MUST not
      // fail.
      $referenced_content_entities = Evaluator::evaluate($host_entity, $this->referencer, is_required: FALSE)->value;
      $referenced_content_entities = match (gettype($referenced_content_entities)) {
        // Reference field containing nothing.
        'NULL' => [],
        // Reference field containing multiple references.
        'array' => $referenced_content_entities,
        // Reference field containing a single reference.
        default => [$referenced_content_entities],
      };
      \assert(Inspector::assertAllObjects($referenced_content_entities, FieldableEntityInterface::class));
      $dependencies['content'] = [
        ...$dependencies['content'] ?? [],
        ...array_map(
          fn (FieldableEntityInterface $entity) => $entity->getConfigDependencyName(),
          $referenced_content_entities,
        ),
      ];
      // The referenced content entity is the starting point for the
      // `referenced` expression, so pass it as the host entity. This is
      // necessary to ensure content dependencies in references are identified.
      foreach ($referenced_content_entities as $referenced_content_entity) {
        $dependencies = NestedArray::mergeDeep($dependencies, $this->referenced->calculateDependencies($referenced_content_entity));
      }
      if (empty($referenced_content_entities)) {
        $dependencies = NestedArray::mergeDeep($dependencies, $this->referenced->calculateDependencies());
      }
    }
    return $dependencies;
  }

  public function withDelta(int $delta): static {
    return new static(
      $this->referencer->withDelta($delta),
      $this->referenced,
    );
  }

  public static function fromString(string $representation): static {
    [$referencer_part, $remainder] = explode(self::PREFIX_ENTITY_LEVEL . self::PREFIX_ENTITY_LEVEL, $representation, 2);
    $referencer = FieldPropExpression::fromString($referencer_part);
    $referenced = StructuredDataPropExpression::fromString(static::PREFIX . self::PREFIX_ENTITY_LEVEL . $remainder);
    \assert($referenced instanceof FieldPropExpression || $referenced instanceof ReferenceFieldPropExpression || $referenced instanceof FieldObjectPropsExpression);
    return new static($referencer, $referenced);
  }

  public function validateSupport(EntityInterface|FieldItemInterface|FieldItemListInterface $entity): void {
    assert($entity instanceof EntityInterface);
    $expected_entity_type_id = $this->referencer->entityType->getEntityTypeId();
    if ($entity->getEntityTypeId() !== $expected_entity_type_id) {
      throw new \DomainException(sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `%s`.", (string) $this, $expected_entity_type_id, $entity->getEntityTypeId()));
    }
    $expected_bundles = $this->referencer->entityType->getBundles();
    if ($expected_bundles !== NULL && !in_array($entity->bundle(), $expected_bundles)) {
      throw new \DomainException(sprintf("`%s` is an expression for entity type `%s`, bundle(s) `%s`, but the provided entity is of the bundle `%s`.", (string) $this, $expected_entity_type_id, implode(', ', $expected_bundles), $entity->bundle()));
    }
    // @todo validate that the field exists?
  }

  public function getHostEntityDataDefinition(): EntityDataDefinitionInterface {
    return $this->referencer->getHostEntityDataDefinition();
  }

  public function isMultiBundle(): bool {
    return $this->referencer->isMultiBundle();
  }

  public function getLeaf(): FieldPropExpression|FieldObjectPropsExpression {
    if ($this->referenced instanceof ReferenceFieldPropExpression) {
      return $this->referenced->getLeaf();
    }

    return $this->referenced;
  }

}
