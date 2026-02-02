<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\Labeler;
use Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropSource\DynamicPropSource;
use Drupal\canvas\PropSource\HostEntityUrlPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\Plugin\Adapter\AdapterInterface;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;

/**
 * Suggests prop sources for a component's props in a host entity type + bundle.
 *
 * For all props of an SDC (or equivalent, described using JSON Schema)
 * - find all viable structured prop sources that match the prop's shape
 * - generate human-readable labels
 *
 * The following prop source types should be suggested, based on shape matches,
 * with guarantees that each suggestion can indeed correctly populate the given
 * component's props:
 * - DynamicPropSources — these suggest fields (on the host entity type+bundle)
 * - HostEntityUrlPropSources — these suggest (relative or absolute) URLs
 * - AdaptedPropSource — these suggest adapters
 *
 * @see \Drupal\Core\Theme\Component\ComponentMetadata
 * @see \Drupal\canvas\PropShape\PropShape
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::getComponentInputsForMetadata()
 * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase
 * @internal
 *
 * @todo Rename things for clarity: this handles all props for an SDC simultaneously, JsonSchemaFieldInstanceMatcher handles a single prop at a time
 */
final class PropSourceSuggester {

  use StringTranslationTrait;

  public function __construct(
    private readonly JsonSchemaFieldInstanceMatcher $propMatcher,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    private readonly Labeler $labeler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Whether the expression uses a field/field property considered irrelevant.
   *
   * These are subjective decisions, intended to improve the UX.
   *
   * For example:
   * - an entity's revision log message is very unlikely to ever be displayed
   * - a reference to a File entity is very unlikely to ever need to display the
   *   owner of the File
   * - et cetera
   *
   * @todo Refactor after https://www.drupal.org/project/drupal/issues/3557353
   */
  private function isConsideredIrrelevant(EntityFieldBasedPropExpressionInterface $expression): bool {
    $entity_type_id = $expression->getHostEntityDataDefinition()->getEntityTypeId();
    \assert(\is_string($entity_type_id));
    $expression_field_name = $expression->getFieldName();
    $referenced_entity_type_id = $expression instanceof ReferencePropExpressionInterface
      ? $expression->getTargetExpression()->getHostEntityDataDefinition()->getEntityTypeId()
      : NULL;
    $referenced_expression_field_name = $expression instanceof ReferencePropExpressionInterface
      ? $expression->getTargetExpression()->getFieldName()
      : NULL;

    // Node-specific heuristics:
    // 1. never suggest `promote` base field
    // 2. never suggest `sticky` base field
    if ($entity_type_id === 'node' && in_array($expression_field_name, ['promote', 'sticky'], TRUE)) {
      return TRUE;
    }

    // File-specific heuristics:
    // 1. do not suggest `uid` base field if the File entity was referenced
    if ($referenced_entity_type_id === 'file' && $expression instanceof ReferencePropExpressionInterface && $referenced_expression_field_name === 'uid') {
      return TRUE;
    }

    // Generic heuristics:
    // 1. never suggest `default_langcode` base field
    // 2. never suggest `revision_log_message` base field
    // 3. never suggest `revision_default` base field
    $content_entity_type_definition = $this->entityTypeManager->getDefinition($entity_type_id);
    \assert($content_entity_type_definition instanceof ContentEntityTypeInterface);
    $is_irrelevant = in_array($expression_field_name, [
      $content_entity_type_definition->getKey('default_langcode'),
      $content_entity_type_definition->getRevisionMetadataKey('revision_default'),
      $content_entity_type_definition->getRevisionMetadataKey('revision_log_message'),
    ], TRUE);
    if ($is_irrelevant) {
      return TRUE;
    }

    // Recurse, if needed.
    return match (TRUE) {
      $expression instanceof ReferencePropExpressionInterface => $this->isConsideredIrrelevant($expression->getTargetExpression()),
      $expression instanceof ObjectPropExpressionInterface => array_any(
        $expression->getObjectExpressions(),
        // PHPStan incorrectly flags this error. It fails to conclude that the
        // function argument already is of the correct type.
        // @phpstan-ignore argument.type
        $this->isConsideredIrrelevant(...),
      ),
      default => FALSE,
    };
  }

  /**
   * @param string $component_plugin_id
   * @param \Drupal\Core\Theme\Component\ComponentMetadata $component_metadata
   * @param \Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface $host_entity_type
   *   Host entity type + bundle, necessary to suggest certain types of prop
   *   sources.
   *
   * @return array<string, array{required: bool, instances: array<string, DynamicPropSource>, adapters: array<AdapterInterface>, host_entity_urls: array<string, HostEntityUrlPropSource>}>
   */
  public function suggest(string $component_plugin_id, ComponentMetadata $component_metadata, EntityDataDefinitionInterface $host_entity_type): array {
    $host_entity_type_id = $host_entity_type->getEntityTypeId();
    \assert(is_string($host_entity_type_id));
    $bundles = $host_entity_type->getBundles();
    \assert(is_array($bundles) && !empty($bundles));
    $host_entity_type_bundle = reset($bundles);

    // 1. Get raw matches.
    $raw_matches = $this->getRawMatches($component_plugin_id, $component_metadata, $host_entity_type_id, $host_entity_type_bundle);

    // 2. Process (filter and order) matches based on context and what Drupal
    //    considers best practices.
    $processed_matches = [];
    foreach ($raw_matches as $cpe => $m) {
      // Bucket the raw matches by field name. The field name order is
      // determined by the form display, to ensure a familiar order for Site
      // Builders. (Later, filter away empty ones).
      $expected_order = $this->entityDisplayRepository->getFormDisplay(
        $host_entity_type_id,
        $host_entity_type_bundle
      )->getComponents();
      uasort($expected_order, SortArray::sortByWeightElement(...));
      $bucketed_by_field = array_fill_keys(
        array_keys($expected_order),
        [],
      );
      // Push each expression into the right (field) bucket, but only if
      // considered relevant.
      foreach ($m['instances'] as $s) {
        $expr = $s->expression;
        if ($this->isConsideredIrrelevant($expr)) {
          continue;
        }
        $bucketed_by_field[$expr->getFieldName()][] = $s;
      }
      // Keep only non-empty (field) buckets.
      $bucketed_by_field = array_map('array_filter', $bucketed_by_field);
      $processed_matches[$cpe]['instances'] = $bucketed_by_field;

      // @todo filtering
      $processed_matches[$cpe]['adapters'] = $m['adapters'];

      // Nothing to do for HostEntityUrlPropSource matches.
      $processed_matches[$cpe]['host_entity_urls'] = $m['host_entity_urls'];
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
      if (!empty($m['instances'])) {
        $dynamic_prop_sources_in_entity_form_display_order = NestedArray::mergeDeep(...$m['instances']);
        $suggestions[$cpe]['instances'] = array_combine(
          array_map(
            fn (DynamicPropSource $s) => (string) Labeler::flatten($this->labeler->label($s->expression, $host_entity_type)),
            $dynamic_prop_sources_in_entity_form_display_order
          ),
          $dynamic_prop_sources_in_entity_form_display_order
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

      // Host entity URLs: generate labels, retain match order.
      $suggestions[$cpe]['host_entity_urls'] = array_combine(
        array_map(
          fn (HostEntityUrlPropSource $s): string => (string) $s->label(),
          $m['host_entity_urls'],
        ),
        $m['host_entity_urls'],
      );
    }

    return $suggestions;
  }

  /**
   * @return array<string, array{instances: array<DynamicPropSource>, adapters: array<\Drupal\canvas\Plugin\Adapter\AdapterInterface>, host_entity_urls: array<HostEntityUrlPropSource>}>
   */
  private function getRawMatches(string $component_plugin_id, ComponentMetadata $component_metadata, string $host_entity_type, string $host_entity_bundle): array {
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
      $raw_matches[(string) $cpe]['instances'] = array_map(fn ($expr): DynamicPropSource => new DynamicPropSource($expr), $instance_candidates);
      // @todo Remove these hard-coded bits with generic logic in https://www.drupal.org/project/canvas/issues/3563960
      if ($schema === ['type' => 'string', 'format' => 'date'] && $host_entity_type === 'node') {
        $created_as_date_string = (new DynamicPropSource(
          new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'created', NULL, 'value'),
        ))->withAdapter('unix_to_date');
        $changed_as_date_string = (new DynamicPropSource(
          expression: new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'changed', NULL, 'value'),
        ))->withAdapter('unix_to_date');
        $raw_matches[(string) $cpe]['instances'][] = $created_as_date_string;
        $raw_matches[(string) $cpe]['instances'][] = $changed_as_date_string;
      }
      $raw_matches[(string) $cpe]['adapters'] = $adapter_candidates;
      $raw_matches[(string) $cpe]['host_entity_urls'] = self::matchHostEntityUrlPropSources($prop_shape) ?? [];
    }

    return $raw_matches;
  }

  /**
   * Finds matching HostEntityUrlPropSources for the given prop shape.
   *
   * @param \Drupal\canvas\PropShape\PropShape $shape
   *
   * @return array<HostEntityUrlPropSource>
   */
  private static function matchHostEntityUrlPropSources(PropShape $shape) : ?array {
    if ($shape->getType() !== JsonSchemaType::String) {
      return NULL;
    }

    $schema = $shape->resolvedSchema;
    if (!array_key_exists('format', $schema)) {
      return NULL;
    }

    $string_format = JsonSchemaStringFormat::from($schema['format']);

    $allowed_string_formats = [
      JsonSchemaStringFormat::Uri,
      JsonSchemaStringFormat::UriReference,
      JsonSchemaStringFormat::Iri,
      JsonSchemaStringFormat::IriReference,
    ];

    // HostEntityUrlPropSources can only populate URI prop shapes (and its
    // supersets).
    if (!in_array($string_format, $allowed_string_formats, TRUE)) {
      return NULL;
    }
    $supports_only_absolute_urls = in_array($string_format, [JsonSchemaStringFormat::Uri, JsonSchemaStringFormat::Iri], TRUE);

    // If an `x-allowed-schemes` shape restriction is present, and it doesn't
    // allow HTTP nor HTTPS, then no viable HostEntityUrlPropSource can exist.
    // @see \Drupal\canvas\Validation\JsonSchema\UriSchemeAwareFormatConstraint
    if (
      array_key_exists('x-allowed-schemes', $schema)
      && empty(array_intersect($schema['x-allowed-schemes'], ['http', 'https']))
    ) {
      return NULL;
    }

    // If any `contentMediaType` shape restriction is present, then no viable
    // HostEntityUrlPropSource can exist (because these always point to
    // `text/html` resources).
    if (array_key_exists('contentMediaType', $schema)) {
      return NULL;
    }

    $matches = [];
    // @todo Offer `canonical` vs `edit-form` vs … (and check whether the given entity type actually contains such a link template).
    $matches[] = new HostEntityUrlPropSource(absolute: $supports_only_absolute_urls);
    return $matches;
  }

  public static function structureSuggestionsForResponse(array $suggestions): array {
    // @todo Remove this after refactoring ::suggest() in https://www.drupal.org/i/3523446 to stop returning a nested array keys by 'instances', 'host_entity_urls' etc, and instead return an array of prop source objects.
    $combined_suggestions = [];
    foreach ($suggestions as $key => $value) {
      $combined_suggestions[$key] = [...$value['instances'], ...$value['host_entity_urls']];
    }

    return array_combine(
      // Top-level keys: the prop names of the targeted component.
      array_map(
        fn (string $key): string => ComponentPropExpression::fromString($key)->propName,
        array_keys($suggestions),
      ),
      array_map(
        // Second level keys: opaque identifiers for the suggestions to
        // populate the component prop.
        fn (array $prop_sources): array => array_combine(
          array_map(
            fn (DynamicPropSource|HostEntityUrlPropSource $prop_source): string => \hash('xxh64', $prop_source->asChoice()),
            array_values($prop_sources),
          ),
          // Values: objects with "label" and "source" keys, with:
          // - "label": the human-readable label that the Content Template UI
          //   should present to the human
          // - "source": the array representation of the prop source that, if
          //   selected by the human, the client should use verbatim as the
          //   source to populate this component instance's prop.
          array_map(
            function (string $label, DynamicPropSource|HostEntityUrlPropSource $prop_source) {
              return [
                'label' => $label,
                'source' => $prop_source->toArray(),
              ];
            },
            array_keys($prop_sources),
            array_values($prop_sources),
          ),
        ),
        $combined_suggestions,
      )
    );
  }

  private static function enrichSuggestion(array $suggestion): array {
    \assert(array_key_exists('label', $suggestion));
    \assert(array_key_exists('source', $suggestion));
    \assert(is_array($suggestion['source']));
    \assert(array_key_exists('sourceType', $suggestion['source']));
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
      'depth' => match ($suggestion['source']['sourceType']) {
        // DynamicPropSources have hierarchy: infer depth from label; determines
        // hierarchy building order.
        PropSource::Dynamic->value => $depth,
        // All other PropSources: keep outside the hierarchy and list first by
        // generating an artificially impossibly low depth.
        default => -1,
      },
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
      // 1. Enrich this prop's suggestions. The sorting is already correct based
      // on the form display.
      $enriched_suggestions = array_map(
        [self::class, 'enrichSuggestion'],
        $suggestions,
      );

      // 2. Walk the depth-sorted suggestions and generate a hierarchy according
      // to the label parts.
      $hierarchical_suggestions = [];
      array_walk($enriched_suggestions, function ($enriched_suggestion, string $opaque_id) use (&$hierarchical_suggestions) {
        $hierarchical_suggestion = [
          'id' => $opaque_id,
          'source' => $enriched_suggestion['source'],
        ];
        NestedArray::setValue($hierarchical_suggestions, $enriched_suggestion['path'], $hierarchical_suggestion);
      });

      // 3. Recursively process the hierarchical suggestions: move the label
      // parts that were used in step 2 from array keys into a `label` key-value
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
