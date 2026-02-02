<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;

final class ReferenceFieldPropExpression implements EntityFieldBasedPropExpressionInterface, ReferencePropExpressionInterface {

  use CompoundExpressionTrait;
  use EntityFieldBasedExpressionTrait;

  public function __construct(
    public readonly FieldPropExpression $referencer,
    public readonly ReferencedBundleSpecificBranches|EntityFieldBasedPropExpressionInterface $referenced,
  ) {
    if ($referenced instanceof ReferencedBundleSpecificBranches) {
      // Note: AFTER the update path has run, this would pass when moved outside
      // this if-test. However, during the update path it would trigger a fatal
      // error.
      // @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
      if (count($this->referencer->getHostEntityDataDefinition()->getBundles() ?? []) > 1) {
        throw new \InvalidArgumentException('A reference expression MUST start from a single entity reference field on a single entity type + bundle.');
      }
      // Validate the target branches of the entity reference field correspond
      // to the specified bundle-specific branches. However:
      // 1. the `target_bundles` settings can change over time!
      // 2. the entity type manager is not available in all circumstances
      // Hence trigger a deprecation error if we can perform the validation, but
      // do not throw an exception.
      // @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testInvalidReferenceFieldTypePropExpressionDueToMismatchedLeafExpressionCardinality
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      if (\Drupal::getContainer()->has('entity_type.manager')) {
        $reference_field_definition = $referencer->getHostEntityDataDefinition()
          ->getPropertyDefinition($referencer->getFieldName());
        \assert($reference_field_definition instanceof FieldDefinitionInterface);
        $target_entity_type_id = $reference_field_definition->getSettings()['target_type'];
        $current_target_bundles = $reference_field_definition->getSettings()['handler_settings']['target_bundles'];
        $expected_branches = array_map(
          fn (string $bundle) => "entity:$target_entity_type_id:$bundle",
          $current_target_bundles,
        );
        sort($expected_branches);
        $actual_branches = array_keys($referenced->bundleSpecificReferencedExpressions);
        if ($expected_branches !== $actual_branches) {
          // phpcs:ignore
          trigger_error(\sprintf(
            'The reference expression `%s` was constructed with bundle-specific branches `%s`, but the referenced field `%s` on entity type `%s` currently targets bundles `%s`.',
            (string) $this,
            implode(', ', $actual_branches),
            $referencer->getFieldName(),
            $referencer->getHostEntityDataDefinition()->getEntityTypeId(),
            implode(', ', $expected_branches),
          ), E_USER_DEPRECATED);
        }
      }
    }
  }

  public function __toString(): string {
    if (!$this->referenced instanceof ReferencedBundleSpecificBranches) {
      $referenced_as_string = self::withoutExpressionTypePrefix((string) $this->referenced);
    }
    else {
      $referenced_as_string = (string) $this->referenced;
    }

    return static::PREFIX_EXPRESSION_TYPE
      . self::withoutExpressionTypePrefix((string) $this->referencer)
      . self::PREFIX_ENTITY_LEVEL
      . $referenced_as_string;
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
        fn (string $recursion_result): string => $chain . self::withoutExpressionTypePrefix($recursion_result),
        $this->referenced->getReferenceChainPrefixes()
      );
    }
    return [
      ...$chain_prefixes,
      ...$additional,
    ];
  }

  /**
   * Returns the string representation of the full/maximal reference chain.
   *
   * @return string
   *
   * @see ::getReferenceChainPrefixes()
   */
  public function getFullReferenceChain(): string {
    $prefixes = $this->getReferenceChainPrefixes();
    $full = end($prefixes);
    return $full;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    \assert($host_entity === NULL || $host_entity instanceof FieldableEntityInterface);
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
    $is_branching = str_contains($representation, self::PREFIX_ENTITY_LEVEL . self::PREFIX_BRANCH . self::PREFIX_ENTITY_LEVEL);
    if ($is_branching) {
      // Find opening of first branch
      $opening_first_branch = mb_strpos($representation, self::PREFIX_BRANCH);
      \assert(is_int($opening_first_branch));
      // Find closing of last branch.
      $closing_last_branch = mb_strrpos($representation, self::SUFFIX_BRANCH);
      \assert(is_int($closing_last_branch));
      $branches = self::parseBranches(mb_substr($representation, $opening_first_branch, $closing_last_branch));
      $referenced_branches = array_map(
      // Each of the branch expressions MUST be starting with an entity field,
      // because that is the only way to branch. Therefore, parse each as its
      // own stand-alone prop expression.
        fn (string $branch) => StructuredDataPropExpression::fromString(static::PREFIX_EXPRESSION_TYPE . $branch),
        $branches
      );
      $parts = explode(self::PREFIX_ENTITY_LEVEL . self::PREFIX_BRANCH . self::PREFIX_ENTITY_LEVEL, $representation, 2);
      $referencer = FieldPropExpression::fromString($parts[0]);
      // @phpstan-ignore argument.type
      $referenced = new ReferencedBundleSpecificBranches(array_combine(
        array_map(
        // @phpstan-ignore argument.type
          fn (EntityFieldBasedPropExpressionInterface $expr) => $expr->getHostEntityDataDefinition()->getDataType(),
          $referenced_branches,
        ),
        $referenced_branches,
      ));
      return new static($referencer, $referenced);
    }

    [$referencer_part, $remainder] = explode(self::PREFIX_ENTITY_LEVEL . self::PREFIX_ENTITY_LEVEL, $representation, 2);
    $referencer = FieldPropExpression::fromString($referencer_part);
    $referenced = StructuredDataPropExpression::fromString(static::PREFIX_EXPRESSION_TYPE . self::PREFIX_ENTITY_LEVEL . $remainder);
    \assert($referenced instanceof FieldPropExpression || $referenced instanceof ReferenceFieldPropExpression || $referenced instanceof FieldObjectPropsExpression);
    return new static($referencer, $referenced);
  }

  public function validateSupport(EntityInterface|FieldItemInterface|FieldItemListInterface $entity): void {
    \assert($entity instanceof EntityInterface);
    $expected_entity_type_id = $this->referencer->entityType->getEntityTypeId();
    if ($entity->getEntityTypeId() !== $expected_entity_type_id) {
      throw new \DomainException(\sprintf("`%s` is an expression for entity type `%s`, but the provided entity is of type `%s`.", (string) $this, $expected_entity_type_id, $entity->getEntityTypeId()));
    }
    $expected_bundles = $this->referencer->entityType->getBundles();
    if ($expected_bundles !== NULL && !in_array($entity->bundle(), $expected_bundles, TRUE)) {
      throw new \DomainException(\sprintf("`%s` is an expression for entity type `%s`, bundle(s) `%s`, but the provided entity is of the bundle `%s`.", (string) $this, $expected_entity_type_id, implode(', ', $expected_bundles), $entity->bundle()));
    }
    // @todo validate that the field exists?
  }

  /**
   * {@inheritdoc}
   */
  public function getHostEntityDataDefinition(): EntityDataDefinitionInterface {
    return $this->referencer->getHostEntityDataDefinition();
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldName(): string {
    return $this->referencer->getFieldName();
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldPropertyName(): string {
    return $this->referencer->getFieldPropertyName();
  }

  /**
   * {@inheritdoc}
   */
  public function getDelta(): int|null {
    return $this->referencer->delta;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetExpression(FieldableEntityInterface|EntityDataDefinitionInterface|null $referenced = NULL) : EntityFieldBasedPropExpressionInterface {
    if ($this->targetsMultipleBundles() && $referenced === NULL) {
      throw new \LogicException('A reference expression that targets multiple bundles needs to be informed which branch to return.');
    }

    // Single-branch.
    if ($referenced === NULL) {
      \assert(!$this->referenced instanceof ReferencedBundleSpecificBranches);
      // @see ::withoutBranch()
      return $this->referenced;
    }

    // Multi-branch.
    \assert($this->referenced instanceof ReferencedBundleSpecificBranches);

    if ($referenced instanceof FieldableEntityInterface) {
      \assert($referenced->bundle() !== NULL);
      return $this->referenced->getBranch($referenced->getEntityTypeId(), $referenced->bundle());
    }

    if ($referenced->getEntityTypeId() === NULL) {
      throw new \LogicException('The referenced entity data definition must have an entity type ID defined when selecting a bundle-specific branch.');
    }
    $bundles = $referenced->getBundles() ?? [];
    if (count($bundles) !== 1) {
      throw new \LogicException('The referenced entity data definition must have a single bundle defined when selecting a bundle-specific branch.');
    }
    return $this->referenced->getBranch($referenced->getEntityTypeId(), reset($bundles));
  }

  /**
   * {@inheritdoc}
   */
  public function getFinalTargetExpression(): (ScalarPropExpressionInterface&EntityFieldBasedPropExpressionInterface)|(ObjectPropExpressionInterface&EntityFieldBasedPropExpressionInterface) {
    if ($this->referenced instanceof ReferencePropExpressionInterface) {
      return $this->referenced->getFinalTargetExpression();
    }

    \assert($this->referenced instanceof ScalarPropExpressionInterface || $this->referenced instanceof ObjectPropExpressionInterface);
    return $this->referenced;
  }

  /**
   * {@inheritdoc}
   */
  public function targetsMultipleBundles(): bool {
    // @see ::withoutBranch()
    return $this->referenced instanceof ReferencedBundleSpecificBranches;
  }

}
