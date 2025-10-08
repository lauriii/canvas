<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropExpressions\StructuredData\Labeler;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;
use Drupal\canvas\PropSource\DynamicPropSource;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;

/**
 * @todo Rename things for clarity: this handles all props for an SDC simultaneously, JsonSchemaFieldInstanceMatcher handles a single prop at a time
 */
final class FieldForComponentSuggester {

  use StringTranslationTrait;

  public function __construct(
    private readonly JsonSchemaFieldInstanceMatcher $propMatcher,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    private readonly Labeler $labeler,
  ) {}

  /**
   * @param string $component_plugin_id
   * @param \Drupal\Core\Theme\Component\ComponentMetadata $component_metadata
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface|null $host_entity_type
   *   Host entity type, if the given component is being used in the context of
   *   an entity.
   *
   * @return array<string, array{required: bool, instances: array<string, \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression|\Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression|\Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression>, adapters: array<AdapterInterface>}>
   */
  public function suggest(string $component_plugin_id, ComponentMetadata $component_metadata, ?EntityDataDefinitionInterface $host_entity_type): array {
    $host_entity_type_bundle = $host_entity_type_id = NULL;
    if ($host_entity_type) {
      $host_entity_type_id = $host_entity_type->getEntityTypeId();
      assert(is_string($host_entity_type_id));
      $bundles = $host_entity_type->getBundles();
      assert(is_array($bundles) && array_key_exists(0, $bundles));
      $host_entity_type_bundle = $bundles[0];
    }

    // 1. Get raw matches.
    $raw_matches = $this->getRawMatches($component_plugin_id, $component_metadata, $host_entity_type_id, $host_entity_type_bundle);

    // 2. Process (filter and order) matches based on context and what Drupal
    //    considers best practices.
    $processed_matches = [];
    foreach ($raw_matches as $cpe => $m) {
      // Instance matches: filter to the ones matching the current host entity
      // type + bundle.
      if ($host_entity_type) {
        $m['instances'] = array_filter(
          $m['instances'],
          fn($expr) => $expr->getHostEntityDataDefinition()->getDataType() === $host_entity_type->getDataType(),
        );
      }

      // Bucket the raw matches by entity type ID, bundle and field name.
      // The field name order is determined by the form display, to ensure a
      // familiar order for site builders.
      $bucketed = [];
      foreach ($m['instances'] as $expr) {
        $expr_entity_data_definition = $expr->getHostEntityDataDefinition();
        $expr_entity_data_type = $expr_entity_data_definition->getDataType();

        // When first encountering a new entity type + bundle, generate an empty
        // array structure in which to fit all of the raw matches, keyed by
        // field, in the order of the entity form display. (Later, filter away
        // empty ones).
        if (!array_key_exists($expr_entity_data_type, $bucketed)) {
          assert(is_string($expr_entity_data_definition->getEntityTypeId()));
          assert(is_array($expr_entity_data_definition->getBundles()));
          assert(count($expr_entity_data_definition->getBundles()) === 1);
          $expected_order = $this->entityDisplayRepository->getFormDisplay(
            $expr_entity_data_definition->getEntityTypeId(),
            $expr_entity_data_definition->getBundles()[0],
          )->getComponents();
          uasort($expected_order, SortArray::sortByWeightElement(...));
          $bucketed[$expr_entity_data_type] = array_fill_keys(
            array_keys($expected_order),
            [],
          );
        }

        // Push each expression into the right (field) bucket.
        $bucketed[$expr_entity_data_type][Labeler::getFieldName($expr, $expr_entity_data_definition)][] = $expr;
      }
      // Keep only non-empty (field) buckets.
      $bucketed = array_map('array_filter', $bucketed);
      $processed_matches[$cpe]['instances'] = $bucketed;

      // @todo filtering
      $processed_matches[$cpe]['adapters'] = $m['adapters'];
    }

    // 3. Generate appropriate labels for each. And specify whether required.
    $suggestions = [];
    foreach ($processed_matches as $cpe => $m) {
      // Required property or not?
      $prop_name = ComponentPropExpression::fromString($cpe)->propName;
      /** @var array<string, mixed> $schema */
      $schema = $component_metadata->schema;
      $suggestions[$cpe]['required'] = in_array($prop_name, $schema['required'] ?? [], TRUE);

      // Field instances.
      $suggestions[$cpe]['instances'] = [];
      if ($host_entity_type && !empty($m['instances'])) {
        assert([$host_entity_type->getDataType()] === array_keys($m['instances']));
        $debucketed = NestedArray::mergeDeep(...$m['instances'][$host_entity_type->getDataType()]);
        $suggestions[$cpe]['instances'] = array_combine(
          array_map(
            fn (FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $e) =>
            (string) Labeler::flatten($this->labeler->label($e, $host_entity_type)),
            $debucketed
          ),
          $debucketed
        );
      }

      // Adapters.
      $suggestions[$cpe]['adapters'] = array_combine(
      // @todo Introduce a plugin definition class that provides a guaranteed label, which will allow removing the PHPStan ignore instruction.
      // @phpstan-ignore-next-line
        array_map(fn (AdapterInterface $a): string => (string) $a->getPluginDefinition()['label'], $m['adapters']),
        $m['adapters']
      );
      // Sort alphabetically by label.
      ksort($suggestions[$cpe]['adapters']);
    }

    return $suggestions;
  }

  /**
   * @return array<string, array{instances: array<int, \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression|\Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression|\Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression>, adapters: array<\Drupal\canvas\Plugin\Adapter\AdapterInterface>}>
   */
  private function getRawMatches(string $component_plugin_id, ComponentMetadata $component_metadata, ?string $host_entity_type, ?string $host_entity_bundle): array {
    $raw_matches = [];

    foreach (GeneratedFieldExplicitInputUxComponentSourceBase::getComponentInputsForMetadata($component_plugin_id, $component_metadata) as $cpe_string => $prop_shape) {
      $cpe = ComponentPropExpression::fromString($cpe_string);
      // @see https://json-schema.org/understanding-json-schema/reference/object#required
      // @see https://json-schema.org/learn/getting-started-step-by-step#required
      $is_required = in_array($cpe->propName, $component_metadata->schema['required'] ?? [], TRUE);
      $schema = $prop_shape->resolvedSchema;

      $primitive_type = JsonSchemaType::from($schema['type']);

      $instance_candidates = $this->propMatcher->findFieldInstanceFormatMatches($primitive_type, $is_required, $schema, $host_entity_type, $host_entity_bundle);
      $adapter_candidates = $this->propMatcher->findAdaptersByMatchingOutput($schema);
      $raw_matches[(string) $cpe]['instances'] = $instance_candidates;
      $raw_matches[(string) $cpe]['adapters'] = $adapter_candidates;
    }

    return $raw_matches;
  }

  public static function structureSuggestionsForResponse(array $suggestions): array {
    return array_combine(
    // Top-level keys: the prop names of the targeted component.
      array_map(
        fn (string $key): string => ComponentPropExpression::fromString($key)->propName,
        array_keys($suggestions),
      ),
      array_map(
        fn (array $instances): array => array_combine(
        // Second level keys: opaque identifiers for the suggestions to
        // populate the component prop.
          array_map(
            fn (StructuredDataPropExpressionInterface $expr): string => \hash('xxh64', (string) $expr),
            array_values($instances),
          ),
          // Values: objects with "label" and "source" keys, with:
          // - "label": the human-readable label that the Content Template UI
          //   should present to the human
          // - "source": the array representation of the DynamicPropSource that,
          //   if selected by the human, the client should use verbatim as the
          //   source to populate this component instance's prop.
          array_map(
            function (string $label, StructuredDataPropExpressionInterface $expr) {
              return [
                'label' => $label,
                // @phpstan-ignore-next-line argument.type
                'source' => (new DynamicPropSource($expr))->toArray(),
              ];
            },
            array_keys($instances),
            array_values($instances),
          ),
        ),
        array_column($suggestions, 'instances'),
      ),
    );
  }

  private static function enrichSuggestion(array $suggestion): array {
    \assert(array_key_exists('label', $suggestion));
    \assert(array_key_exists('source', $suggestion));
    $label = $suggestion['label'];

    $label_parts = explode(' → ', $label);
    $depth = count($label_parts) - 1;

    // Transform `$label_parts` from `['a', 'b']` to ` ['a', 'items', 'b']`:
    // interleave every part with "items". The result is the path at which this
    // suggestion will be hierarchically positioned.
    $hierarchy_parts = $label_parts;
    array_walk($hierarchy_parts, function (string &$hierarchy_part, int $index): void {
      $hierarchy_part = $index > 0 ? "items|$hierarchy_part" : $hierarchy_part;
    });
    $path = explode('|', implode('|', $hierarchy_parts));

    return [
      ...$suggestion,
      // Infer depth from label; determines hierarchy building order.
      'depth' => $depth,
      // Compute hierarchy path from label; determines location in hierarchy.
      'path' => $path,
    ];
  }

  private static function walkAndPopulateHierarchicalSuggestions(array &$hierarchical_suggestions): void {
    foreach ($hierarchical_suggestions as $key => $value) {
      if (array_key_exists('items', $value)) {
        self::walkAndPopulateHierarchicalSuggestions($value['items']);
      }
      unset($hierarchical_suggestions[$key]);
      $hierarchical_suggestions[] = [...$value, 'label' => $key];
    }
  }

  public static function structureSuggestionsForHierarchicalResponse(array $suggestions): array {
    $flat_response_structure = self::structureSuggestionsForResponse($suggestions);

    $hierarchical_response = [];
    foreach ($flat_response_structure as $prop_name => &$suggestions) {
      // 1. Enrich and sort this prop's suggestions.
      $enriched_suggestions = array_map(
        [self::class, 'enrichSuggestion'],
        $suggestions,
      );
      $original_order = array_keys($suggestions);

      // 2. Sort this prop's suggestions from shallow to deep. This retains the
      // relative ordering between those suggestions that have the same depth.
      array_multisort(
        array_column($enriched_suggestions, 'depth'), SORT_ASC,
        $original_order, SORT_ASC,
        $enriched_suggestions,
      );

      // 3. Walk the depth-sorted suggestions and generate a hierarchy according
      // to the label parts.
      $hierarchical_suggestions = [];
      array_walk($enriched_suggestions, function ($enriched_suggestion, string $opaque_id) use (&$hierarchical_suggestions) {
        $hierarchical_suggestion = [
          'id' => $opaque_id,
          'source' => $enriched_suggestion['source'],
        ];
        NestedArray::setValue($hierarchical_suggestions, $enriched_suggestion['path'], $hierarchical_suggestion);
      });

      // 4. Recursively process the hierarchical suggestions: move the label
      // parts that were used in step 3 from array keys into a `label` key-value
      // pair in each node in the tree. Replace them with numerical indexes,
      // respecting the original sort order.
      // TRICKY: \array_walk_recursive() cannot be used because it operates only
      // on leaf nodes!
      self::walkAndPopulateHierarchicalSuggestions($hierarchical_suggestions);

      $hierarchical_response[$prop_name] = $hierarchical_suggestions;
    }

    return $hierarchical_response;
  }

}
