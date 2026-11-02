<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Contains unstructured data for 1 custom object prop ("group").
 *
 * The composite counterpart of StaticPropSource: one conjured StaticPropSource
 * per sub-property, evaluating to a single JSON object value — or, for
 * multi-value groups, to a JSON array of objects, preserving item order. Items
 * are stored as explicit per-item structures; there is no cross-field delta
 * alignment to corrupt.
 *
 * Wire format (see ::toArray()):
 * @code
 * [
 *   'sourceType' => 'object-props',
 *   'value' => <object, or list of objects for multi-value groups>,
 *   'sources' => <one value-less StaticPropSource array per sub-property>,
 *   'sourceTypeSettings' => ['cardinality' => <only for multi-value groups>],
 * ]
 * @endcode
 *
 * @see \Drupal\canvas\PropShape\ObjectPropsStorablePropShape
 * @see docs/adr/0021-object-props-in-code-components.md
 * @internal
 *
 * Each entry in `sources` is a value-less StaticPropSource array: it carries
 * `sourceType`, `expression`, and optionally `sourceTypeSettings`.
 * @phpstan-type ObjectPropsSourceArray array{sourceType: string, value: array<string, mixed>|list<array<string, mixed>>|null, sources: non-empty-array<string, array<string, mixed>>, sourceTypeSettings?: array{cardinality?: int}}
 */
final class ObjectPropsSource extends PropSourceBase {

  /**
   * @param non-empty-array<string, \Drupal\canvas\PropSource\StaticPropSource> $subPrototypes
   *   One value-less StaticPropSource per sub-property.
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED|int<2, max>|null $cardinality
   *   NULL for a single-value group, otherwise the maximum number of items.
   * @param array<string, mixed>|list<array<string, mixed>>|null $value
   *   The stored value: one object (single-value groups) or a list of objects
   *   (multi-value groups).
   */
  private function __construct(
    private readonly array $subPrototypes,
    private readonly ?int $cardinality,
    private readonly mixed $value,
  ) {
    \assert($this->subPrototypes !== []);
  }

  /**
   * Generates a new (empty) object props source.
   *
   * @param non-empty-array<string, \Drupal\canvas\PropSource\StaticPropSource> $sub_prototypes
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED|int<2, max>|null $cardinality
   */
  public static function generate(array $sub_prototypes, ?int $cardinality = NULL): static {
    return new ObjectPropsSource($sub_prototypes, $cardinality, $cardinality === NULL ? NULL : []);
  }

  /**
   * @return \Drupal\Core\Field\FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED|int<1, max>
   *   1 for single-value groups, like StaticPropSource::getCardinality().
   */
  public function getCardinality(): int {
    return $this->cardinality ?? 1;
  }

  /**
   * Gets per-sub-property sources with the stored values applied.
   *
   * @param int|null $delta
   *   For multi-value groups: which item's values to apply. NULL for
   *   single-value groups.
   *
   * @return array<string, \Drupal\canvas\PropSource\StaticPropSource>
   */
  public function getSubSources(?int $delta = NULL): array {
    $values = $this->getItemValue($delta);
    $sources = [];
    foreach ($this->subPrototypes as $sub_property_name => $prototype) {
      $sources[$sub_property_name] = \array_key_exists($sub_property_name, $values)
        ? $prototype->withValue($values[$sub_property_name], allow_empty: TRUE)
        : $prototype;
    }
    return $sources;
  }

  /**
   * @return array<string, mixed>
   */
  private function getItemValue(?int $delta): array {
    if ($this->cardinality === NULL) {
      \assert($delta === NULL);
      /** @var array<string, mixed> */
      return \is_array($this->value) ? $this->value : [];
    }
    \assert($delta !== NULL);
    $item = $this->value[$delta] ?? [];
    /** @var array<string, mixed> */
    return \is_array($item) ? $item : [];
  }

  /**
   * The item deltas of a multi-value group's stored value.
   *
   * @return list<int>
   */
  private function getItemDeltas(): array {
    \assert($this->cardinality !== NULL);
    return \array_keys(\array_values(\is_array($this->value) ? $this->value : []));
  }

  /**
   * {@inheritdoc}
   *
   * @return ObjectPropsSourceArray
   */
  public function toArray(): array {
    /** @var array<string, mixed>|list<array<string, mixed>>|null $value */
    $value = $this->getValue();
    $array_representation = [
      'sourceType' => $this->getSourceType(),
      'value' => $value,
      'sources' => \array_map(
        static fn (StaticPropSource $prototype): array => \array_diff_key($prototype->toArray(), \array_flip(['value'])),
        $this->subPrototypes,
      ),
    ];
    if ($this->cardinality !== NULL) {
      $array_representation['sourceTypeSettings']['cardinality'] = $this->cardinality;
    }
    return $array_representation;
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $sdc_prop_source): static {
    // `sourceType = object-props` requires a value and sources to be specified.
    $missing = array_diff(['value', 'sources'], \array_keys($sdc_prop_source));
    if (!empty($missing)) {
      throw new \LogicException(\sprintf('Missing the keys %s.', implode(',', $missing)));
    }
    \assert(\array_key_exists('value', $sdc_prop_source));
    \assert(\array_key_exists('sources', $sdc_prop_source));
    if (!\is_array($sdc_prop_source['sources']) || $sdc_prop_source['sources'] === []) {
      throw new \LogicException('The `sources` key must contain at least one sub-property prop source.');
    }

    $sub_prototypes = \array_map(
      // @phpstan-ignore argument.type
      static fn (array $sub_source): StaticPropSource => StaticPropSource::parse(['value' => NULL] + $sub_source),
      $sdc_prop_source['sources'],
    );
    $cardinality = $sdc_prop_source['sourceTypeSettings']['cardinality'] ?? NULL;
    return (new ObjectPropsSource($sub_prototypes, $cardinality, NULL))
      ->withValue(self::sanitizeValue($sdc_prop_source['value'], $sub_prototypes, $cardinality), allow_empty: TRUE);
  }

  /**
   * Drops sub-property values that cannot be stored by their field type.
   *
   * The client model's `source` values may temporarily hold *resolved* values
   * (e.g. a resolved example image object) for sub-properties the Content
   * Creator has not populated yet. Those cannot be stored; treat them as
   * empty, mirroring how mismatched values for scalar props gracefully
   * degrade.
   *
   * @param array<string, \Drupal\canvas\PropSource\StaticPropSource> $sub_prototypes
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::clientModelToInput()
   */
  private static function sanitizeValue(mixed $value, array $sub_prototypes, ?int $cardinality): mixed {
    if (!\is_array($value)) {
      return $cardinality === NULL ? NULL : [];
    }
    $is_multiple = $cardinality !== NULL;
    $items = $is_multiple ? \array_values(\array_filter($value, '\is_array')) : [$value];
    $sanitized_items = [];
    foreach ($items as $item) {
      $sanitized_item = [];
      foreach ($item as $sub_property_name => $sub_value) {
        if (!\array_key_exists($sub_property_name, $sub_prototypes)) {
          continue;
        }
        try {
          $sub_prototypes[$sub_property_name]->withValue($sub_value, allow_empty: TRUE);
        }
        catch (\Exception) {
          // @phpcs:ignore Drupal.Semantics.FunctionTriggerError.TriggerErrorTextLayoutRelaxed
          @trigger_error(\sprintf('The value `%s` for the `%s` sub-property cannot be stored by its field type and was ignored.', json_encode($sub_value), $sub_property_name), E_USER_DEPRECATED);
          continue;
        }
        $sanitized_item[$sub_property_name] = $sub_value;
      }
      $sanitized_items[] = $sanitized_item;
    }
    return $is_multiple ? $sanitized_items : ($sanitized_items[0] ?? NULL);
  }

  /**
   * Returns a new ObjectPropsSource with the given value.
   *
   * @param mixed $value
   *   One object (single-value groups) or a list of objects (multi-value
   *   groups). NULL (or [] for multi-value groups) empties the source.
   * @param bool $allow_empty
   *   See StaticPropSource::withValue(). Empty values are needed when
   *   validating, when loading stored data, and when previewing mid-input
   *   state.
   */
  public function withValue(mixed $value, bool $allow_empty = FALSE): static {
    if ($this->cardinality === NULL) {
      if ($value !== NULL && (!\is_array($value) || \array_is_list($value) && $value !== [])) {
        throw new \LogicException('A single-value group must be populated with an object value.');
      }
      // Normalize the empty array to NULL.
      $value = $value === [] ? NULL : $value;
    }
    else {
      if ($value === NULL) {
        $value = [];
      }
      if (!\is_array($value) || !\array_is_list($value)) {
        throw new \LogicException('A multi-value group must be populated with a list of object values.');
      }
    }

    // Verify each sub-property value is settable on its prototype; this also
    // rejects values for undeclared sub-properties — except when loading
    // stored (or mid-input) data, where stale values for sub-properties that
    // no longer exist must be ignored: a group's sub-properties may have been
    // removed in a newer component version.
    // @see https://en.wikipedia.org/wiki/Robustness_principle
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceUpdater::canUpdate()
    $items = $this->cardinality === NULL ? [$value ?? []] : $value;
    foreach ($items as $delta => $item) {
      if (!\is_array($item)) {
        throw new \LogicException('A group item must be an object value.');
      }
      foreach ($item as $sub_property_name => $sub_value) {
        if (!\array_key_exists($sub_property_name, $this->subPrototypes)) {
          if (!$allow_empty) {
            throw new \LogicException(\sprintf("'%s' is not a sub-property of this group.", $sub_property_name));
          }
          unset($item[$sub_property_name]);
          continue;
        }
        // Throws when the value does not fit the sub-property's field type.
        $this->subPrototypes[$sub_property_name]->withValue($sub_value, $allow_empty);
      }
      $items[$delta] = $item;
    }
    $value = $this->cardinality === NULL
      ? ($items[0] === [] && $value === NULL ? NULL : $items[0])
      : \array_values($items);

    $new = new ObjectPropsSource($this->subPrototypes, $this->cardinality, $value);
    if (!$allow_empty && $new->isEmpty()) {
      throw new \LogicException(\sprintf('%s called with a value that is considered empty.', __METHOD__));
    }
    return $new;
  }

  /**
   * Determines if this prop source is empty.
   *
   * A group is empty when every sub-property (of every item, for multi-value
   * groups) is considered empty by its field type.
   */
  public function isEmpty(): bool {
    $deltas = $this->cardinality === NULL ? [NULL] : $this->getItemDeltas();
    foreach ($deltas as $delta) {
      foreach ($this->getSubSources($delta) as $sub_source) {
        if (!$sub_source->isEmpty()) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Gets the stored value: the minimal representation per sub-property.
   *
   * Sub-properties (and, for multi-value groups, whole items) considered empty
   * by their field types are omitted.
   *
   * @return array<string, mixed>|list<array<string, mixed>>|null
   */
  public function getValue(): mixed {
    if ($this->cardinality === NULL) {
      $item_value = $this->collapseItem(NULL);
      return $item_value === [] ? NULL : $item_value;
    }
    $items = [];
    foreach ($this->getItemDeltas() as $delta) {
      $item_value = $this->collapseItem($delta);
      if ($item_value !== []) {
        $items[] = $item_value;
      }
    }
    return $items;
  }

  /**
   * @return array<string, mixed>
   */
  private function collapseItem(?int $delta): array {
    $item_value = [];
    foreach ($this->getSubSources($delta) as $sub_property_name => $sub_source) {
      if ($sub_source->isEmpty()) {
        continue;
      }
      $item_value[$sub_property_name] = $sub_source->getValue();
    }
    return $item_value;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(?FieldableEntityInterface $host_entity, bool $is_required): EvaluationResult {
    if ($this->cardinality === NULL) {
      $result = $this->evaluateItem(NULL, $host_entity);
      return $result === [] ? new EvaluationResult(NULL) : new EvaluationResult($result);
    }
    $items = [];
    foreach ($this->getItemDeltas() as $delta) {
      $item_result = $this->evaluateItem($delta, $host_entity);
      // A fully empty item is valid, but must not be rendered.
      if ($item_result !== []) {
        $items[] = $item_result;
      }
    }
    // The constructor hoists the nested per-item EvaluationResults.
    // @phpstan-ignore argument.type
    return new EvaluationResult($items);
  }

  /**
   * @return array<string, \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult>
   */
  private function evaluateItem(?int $delta, ?FieldableEntityInterface $host_entity): array {
    $result = [];
    foreach ($this->getSubSources($delta) as $sub_property_name => $sub_source) {
      if ($sub_source->isEmpty()) {
        continue;
      }
      // Sub-property requiredness is enforced by JSON Schema validation, not
      // during evaluation: a required sub-property of an optional group is
      // only enforced when any sub-property of the group (item) is populated.
      $result[$sub_property_name] = $sub_source->evaluate($host_entity, is_required: FALSE);
    }
    return $result;
  }

  /**
   * The composite counterpart of StaticPropSource::hasSameShapeAs().
   */
  public function hasSameShapeAs(ObjectPropsSource $other): bool {
    if ($this->cardinality !== $other->cardinality) {
      return FALSE;
    }
    if (\array_keys($this->subPrototypes) !== \array_keys($other->subPrototypes)) {
      return FALSE;
    }
    foreach ($this->subPrototypes as $sub_property_name => $prototype) {
      if (!$prototype->hasSameShapeAs($other->subPrototypes[$sub_property_name])) {
        return FALSE;
      }
    }
    return TRUE;
  }

  public function asChoice(): string {
    // Groups cannot be linked to a single structured data source; their
    // sub-properties can.
    // @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::matchEntityPropsForObjectUsingScalars()
    throw new \LogicException();
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    $dependencies = [];
    // Use the value-applied sub sources, so that `content` dependencies of
    // referenced entities (e.g. media referenced by an image sub-property)
    // are included.
    $deltas = $this->cardinality === NULL ? [NULL] : $this->getItemDeltas();
    // An empty multi-value group still depends on its sub-properties' config.
    if ($deltas === []) {
      $deltas = [NULL];
    }
    foreach ($deltas as $delta) {
      $sub_sources = $delta === NULL && $this->cardinality !== NULL
        ? $this->subPrototypes
        : $this->getSubSources($delta);
      foreach ($sub_sources as $sub_source) {
        $dependencies = NestedArray::mergeDeep($dependencies, $sub_source->calculateDependencies($host_entity));
      }
    }
    ksort($dependencies);
    return \array_map(static function ($values) {
      $values = array_unique($values);
      sort($values);
      return $values;
    }, $dependencies);
  }

  /**
   * The number of items: 1 for single-value groups.
   */
  public function countItems(): int {
    if ($this->cardinality === NULL) {
      return 1;
    }
    \assert(\is_array($this->value));
    return \count($this->value);
  }

}
