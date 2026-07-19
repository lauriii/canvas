<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\Labeler;
use Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\canvas\PropSource\EntityFieldPropSource;
use Drupal\canvas\PropSource\HostEntityUrlPropSource;
use Drupal\canvas\PropSource\LinkablePropSourceInterface;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Theme\Component\ComponentMetadata;

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
 * - EntityFieldPropSources — these suggest fields (on the host entity
 *   type+bundle)
 * - HostEntityUrlPropSources — these suggest (relative or absolute) URLs
 * - AdaptedPropSource — these suggest adapters
 *
 * @see \Drupal\Core\Theme\Component\ComponentMetadata
 * @see \Drupal\canvas\PropShape\PropShape
 * @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher
 * @see \Drupal\canvas\ShapeMatcher\AdaptedPropSourceMatcher
 * @see \Drupal\canvas\ShapeMatcher\HostEntityUrlPropSourceMatcher
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::getComponentInputsForMetadata()
 * @internal
 *
 * @phpstan-type AdapterSuggestionInput array{name: string, required: bool, mirrorsOutput: bool, schema: array<string, mixed>|null, candidates: list<array{id: string, label: string, source: array<string, mixed>}>, static: array<string, mixed>|null}
 * @phpstan-type AdapterSuggestion array{id: string, label: string, adapter: array{id: string, inputs: list<AdapterSuggestionInput>}}
 */
final readonly class PropSourceSuggester {

  public function __construct(
    private EntityFieldPropSourceMatcher $entityFieldPropSourceMatcher,
    private AdaptedPropSourceMatcher $adaptedPropSourceMatcher,
    private HostEntityUrlPropSourceMatcher $hostEntityUrlPropSourceMatcher,
    private HostEntityPropSourceMatcher $hostEntityPropSourceMatcher,
    private EntityDisplayRepositoryInterface $entityDisplayRepository,
    private Labeler $labeler,
    private EntityTypeManagerInterface $entityTypeManager,
    private PropShapeRepositoryInterface $propShapeRepository,
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
    if ($entity_type_id === 'node' && \in_array($expression_field_name, ['promote', 'sticky'], TRUE)) {
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
    // 4. never suggest content_translation's `content_translation_source` and
    //    `content_translation_outdated` base fields, added with fixed names to
    //    a content entity type when one of its bundles is translatable.
    // @see \Drupal\content_translation\ContentTranslationHandler::fieldStorageDefinitions()
    $content_entity_type_definition = $this->entityTypeManager->getDefinition($entity_type_id);
    \assert($content_entity_type_definition instanceof ContentEntityTypeInterface);
    $is_irrelevant = \in_array($expression_field_name, [
      $content_entity_type_definition->getKey('default_langcode'),
      $content_entity_type_definition->getRevisionMetadataKey('revision_default'),
      $content_entity_type_definition->getRevisionMetadataKey('revision_log_message'),
      'content_translation_source',
      'content_translation_outdated',
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
   * @return array<string, array{required: bool, entity-field: array<string, EntityFieldPropSource>, adapter: list<AdapterSuggestion>, host-entity-url: array<string, HostEntityUrlPropSource>, host-entity: array<string, \Drupal\canvas\PropSource\HostEntityPropSource>}>
   */
  public function suggest(string $component_plugin_id, ComponentMetadata $component_metadata, EntityDataDefinitionInterface $host_entity_type): array {
    $host_entity_type_id = $host_entity_type->getEntityTypeId();
    \assert(\is_string($host_entity_type_id));
    $bundles = $host_entity_type->getBundles();
    \assert(\is_array($bundles) && !empty($bundles));
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
        \array_keys($expected_order),
        [],
      );
      // Push each expression into the right (field) bucket, but only if
      // considered relevant.
      foreach ($m[PropSource::EntityField->value] as $s) {
        $expr = $s->expression;
        if ($this->isConsideredIrrelevant($expr)) {
          continue;
        }
        $bucketed_by_field[$expr->getFieldName()][] = $s;
      }
      // Keep only non-empty (field) buckets.
      $bucketed_by_field = \array_map('array_filter', $bucketed_by_field);
      $processed_matches[$cpe][PropSource::EntityField->value] = $bucketed_by_field;

      // @todo filtering
      $processed_matches[$cpe][PropSource::Adapter->value] = $m[PropSource::Adapter->value];

      // Nothing to do for HostEntityUrlPropSource matches.
      $processed_matches[$cpe][PropSource::HostEntityUrl->value] = $m[PropSource::HostEntityUrl->value];

      // Nothing to do for HostEntityPropSource matches.
      $processed_matches[$cpe][PropSource::HostEntity->value] = $m[PropSource::HostEntity->value];
    }

    // 3. Generate appropriate labels for each. And specify whether required.
    $suggestions = [];
    foreach ($processed_matches as $cpe => $m) {
      // Required property or not?
      $prop_name = ComponentPropExpression::fromString($cpe)->propName;
      /** @var array<string, mixed> $schema */
      $schema = $component_metadata->schema;
      $suggestions[$cpe]['required'] = \in_array($prop_name, $schema['required'] ?? [], TRUE);

      // Field instances.
      $suggestions[$cpe][PropSource::EntityField->value] = [];
      if (!empty($m[PropSource::EntityField->value])) {
        $dynamic_prop_sources_in_entity_form_display_order = NestedArray::mergeDeep(...$m[PropSource::EntityField->value]);
        $suggestions[$cpe][PropSource::EntityField->value] = array_combine(
          \array_map(
            fn (EntityFieldPropSource $s) => (string) Labeler::flatten($this->labeler->label($s->expression, $host_entity_type)),
            $dynamic_prop_sources_in_entity_form_display_order
          ),
          $dynamic_prop_sources_in_entity_form_display_order
        );
      }

      // Adapters: already client-ready representations, ordered by label.
      // @see ::buildAdapterSuggestions()
      $suggestions[$cpe][PropSource::Adapter->value] = $m[PropSource::Adapter->value];

      // Host entity URLs: generate labels, retain match order.
      $suggestions[$cpe][PropSource::HostEntityUrl->value] = array_combine(
        \array_map(
          fn (HostEntityUrlPropSource $s): string => (string) $s->label($host_entity_type),
          $m[PropSource::HostEntityUrl->value],
        ),
        $m[PropSource::HostEntityUrl->value],
      );

      // Host entity: at most one match by definition.
      // @see \Drupal\canvas\ShapeMatcher\HostEntityPropSourceMatcher::match()
      $suggestions[$cpe][PropSource::HostEntity->value] = [];
      $host_entity_match = $m[PropSource::HostEntity->value][0] ?? NULL;
      if ($host_entity_match !== NULL) {
        $label = (string) $host_entity_match->label($host_entity_type);
        $suggestions[$cpe][PropSource::HostEntity->value][$label] = $host_entity_match;
      }
    }

    return $suggestions;
  }

  /**
   * @return array<string, array{entity-field: array<EntityFieldPropSource>, adapter: list<AdapterSuggestion>, host-entity-url: array<HostEntityUrlPropSource>, host-entity: array<\Drupal\canvas\PropSource\HostEntityPropSource>}>
   */
  private function getRawMatches(string $component_plugin_id, ComponentMetadata $component_metadata, string $host_entity_type, string $host_entity_bundle): array {
    $raw_matches = [];
    // Memoizes computed field candidates and static source templates for the
    // adapter input slots, because the same shapes recur across the adapters
    // and props of a single suggestion request.
    $slot_memo = [];

    foreach (JsonSchemaPropsComponentSourceBase::getComponentInputsForMetadata($component_plugin_id, $component_metadata) as $cpe_string => $prop_shape) {
      $cpe = ComponentPropExpression::fromString($cpe_string);
      // @see https://json-schema.org/understanding-json-schema/reference/object#required
      // @see https://json-schema.org/learn/getting-started-step-by-step#required
      $is_required = \in_array($cpe->propName, $component_metadata->schema['required'] ?? [], TRUE);
      $schema = $prop_shape->resolvedSchema;

      $raw_matches[(string) $cpe][PropSource::EntityField->value] = $this->entityFieldPropSourceMatcher->match($is_required, $prop_shape, $host_entity_type, $host_entity_bundle);
      // @todo Remove these hard-coded bits with generic logic in https://www.drupal.org/project/canvas/issues/3563960
      if ($schema === ['type' => 'string', 'format' => 'date'] && $host_entity_type === 'node') {
        $created_as_date_string = (new EntityFieldPropSource(
          new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'created', NULL, 'value'),
        ))->withAdapter('unix_to_date');
        $changed_as_date_string = (new EntityFieldPropSource(
          expression: new FieldPropExpression(BetterEntityDataDefinition::create('node'), 'changed', NULL, 'value'),
        ))->withAdapter('unix_to_date');
        $raw_matches[(string) $cpe][PropSource::EntityField->value][] = $created_as_date_string;
        $raw_matches[(string) $cpe][PropSource::EntityField->value][] = $changed_as_date_string;
      }
      $raw_matches[(string) $cpe][PropSource::Adapter->value] = $this->buildAdapterSuggestions(
        $this->adaptedPropSourceMatcher->match($is_required, $prop_shape),
        $is_required,
        $prop_shape,
        $host_entity_type,
        $host_entity_bundle,
        $slot_memo,
      );
      $raw_matches[(string) $cpe][PropSource::HostEntityUrl->value] = $this->hostEntityUrlPropSourceMatcher->match($is_required, $prop_shape);
      $raw_matches[(string) $cpe][PropSource::HostEntity->value] = $this->hostEntityPropSourceMatcher->match($is_required, $prop_shape, $host_entity_type, $host_entity_bundle);
    }

    return $raw_matches;
  }

  /**
   * The shapes offered as field candidates for "any"-shaped adapter inputs.
   *
   * An input declared with an empty (`[]`) schema accepts any value; there is
   * no single shape to match fields against, so candidates are the union of
   * fields matching these primitive shapes.
   */
  private const ANY_INPUT_CANDIDATE_SCHEMAS = [
    ['type' => 'string'],
    ['type' => 'integer'],
    ['type' => 'number'],
    ['type' => 'boolean'],
  ];

  /**
   * Builds client-ready representations of adapter suggestions for one prop.
   *
   * Each representation carries everything the client needs to offer and
   * configure the adapter: its ID and label, plus one entry per input slot
   * with the slot's schema, whether it is required, whether it mirrors the
   * output (parametric adapters), the field candidates that can populate it,
   * and a template for populating it with a static (literal) value.
   *
   * Type awareness:
   * - An adapter is only offered when its primary input (the first required
   *   one — the input that carries the data being transformed) has at least
   *   one field candidate: e.g. no date conversion without date fields.
   * - Every (conditionally) required slot must be bindable — by a field
   *   candidate or a static literal — or the adapter is not offered.
   * - For a REQUIRED target prop, the transform must not produce an empty
   *   value: inputs listed in the adapter's requiredInputsWhenOutputRequired
   *   (e.g. a conditional's `else`) become required, and required inputs
   *   whose emptiness propagates to the output only offer required fields as
   *   candidates — mirroring how direct field matches behave.
   *
   * @param list<\Drupal\canvas\Plugin\Adapter\AdapterInterface> $adapters
   * @param array<string, mixed> $slot_memo
   *
   * @return list<AdapterSuggestion>
   */
  private function buildAdapterSuggestions(array $adapters, bool $is_required, PropShape $prop_shape, string $host_entity_type_id, string $host_entity_bundle, array &$slot_memo): array {
    $suggestions = [];
    foreach ($adapters as $adapter) {
      $definition = $adapter->getPluginDefinition();
      \assert(\is_array($definition));
      $mirroring_inputs = $adapter->getOutputMirroringInputs();

      $inputs = [];
      $offer = TRUE;
      $seen_primary = FALSE;
      foreach ($adapter->getInputs() as $input_name => $declared_schema) {
        $mirrors_output = \in_array($input_name, $mirroring_inputs, TRUE);
        // Determine the shape(s) whose matching fields are candidates for
        // this input slot: the target prop shape for mirroring inputs, a set
        // of primitive shapes for "any"-shaped inputs, the declared shape
        // otherwise.
        if ($mirrors_output) {
          $slot_shapes = [$prop_shape];
        }
        elseif ($declared_schema === []) {
          $slot_shapes = \array_map(PropShape::normalize(...), self::ANY_INPUT_CANDIDATE_SCHEMAS);
        }
        else {
          $slot_shapes = [PropShape::normalize($declared_schema)];
          // Datetime strings occur as both `format: date` and
          // `format: date-time`, depending on the field type and its
          // settings. A slot declared as `date-time` accepts both, so offer
          // both as candidates.
          // @see \Drupal\canvas\Plugin\Adapter\FormatDateAdapter::addInput()
          if (PropShape::normalizePropSchema($declared_schema) === ['type' => 'string', 'format' => 'date-time']) {
            $slot_shapes[] = PropShape::normalize(['type' => 'string', 'format' => 'date']);
          }
        }

        $slot_required = $adapter->inputIsRequired($input_name)
          || ($is_required && \in_array($input_name, $adapter->getRequiredInputsWhenOutputRequired(), TRUE));
        // A required target prop must never receive an empty value, so slots
        // whose emptiness propagates to the output only offer required
        // fields — exactly like direct field matches.
        $candidates_must_be_required = $is_required && $slot_required && !$adapter->inputToleratesEmpty($input_name);
        $candidates = $this->getSlotCandidates($slot_shapes, $candidates_must_be_required, $host_entity_type_id, $host_entity_bundle, $slot_memo);
        $static = $this->getStaticSourceTemplate($slot_shapes[0], $slot_memo);

        // The primary input — the first (unconditionally) required one — is
        // the input that carries the data being transformed: without a field
        // candidate for it, the adapter has nothing to transform here.
        if (!$seen_primary && $adapter->inputIsRequired($input_name)) {
          $seen_primary = TRUE;
          if ($candidates === []) {
            $offer = FALSE;
            break;
          }
        }
        // Any other required slot must be bindable one way or another.
        if ($slot_required && $candidates === [] && $static === NULL) {
          $offer = FALSE;
          break;
        }

        $inputs[] = [
          'name' => $input_name,
          'required' => $slot_required,
          'mirrorsOutput' => $mirrors_output,
          'schema' => $mirrors_output
            ? $prop_shape->resolvedSchema
            : ($declared_schema === [] ? NULL : $adapter->getInputSchema($input_name)),
          'candidates' => $candidates,
          // How the client should populate this slot with a literal value: a
          // StaticPropSource template whose `value` it fills in. For
          // "any"-shaped slots, literals are entered as text.
          'static' => $static,
        ];
      }
      if (!$offer) {
        continue;
      }

      $suggestions[] = [
        'id' => \hash('xxh64', PropSource::Adapter->value . ':' . $adapter->getPluginId()),
        'label' => (string) $definition['label'],
        'adapter' => [
          'id' => $adapter->getPluginId(),
          'inputs' => $inputs,
        ],
      ];
    }
    \usort($suggestions, fn (array $a, array $b): int => \strcasecmp($a['label'], $b['label']));
    return $suggestions;
  }

  /**
   * Computes the field candidates for an adapter input slot.
   *
   * @param list<\Drupal\canvas\PropShape\PropShape> $slot_shapes
   * @param bool $is_required
   *   Whether only required fields may populate this slot: TRUE for slots
   *   whose emptiness would propagate to a required target prop. FALSE
   *   otherwise: adapter inputs may generally be populated by optional
   *   fields even when the targeted prop is required — bridging that gap is
   *   e.g. the `fallback` adapter's purpose.
   * @param array<string, mixed> $slot_memo
   *
   * @return list<array{id: string, label: string, source: array<string, mixed>}>
   */
  private function getSlotCandidates(array $slot_shapes, bool $is_required, string $host_entity_type_id, string $host_entity_bundle, array &$slot_memo): array {
    $host_entity_type = BetterEntityDataDefinition::create($host_entity_type_id, $host_entity_bundle);
    $candidates = [];
    foreach ($slot_shapes as $shape) {
      $memo_key = 'candidates:' . (int) $is_required . ':' . $shape->uniquePropSchemaKey();
      if (!\array_key_exists($memo_key, $slot_memo)) {
        $entries = [];
        foreach ($this->entityFieldPropSourceMatcher->match($is_required, $shape, $host_entity_type_id, $host_entity_bundle) as $source) {
          if ($this->isConsideredIrrelevant($source->expression)) {
            continue;
          }
          // Keyed by expression to dedupe across the union of shapes.
          $entries[$source->asChoice()] = [
            'id' => \hash('xxh64', $source->asChoice()),
            'label' => (string) Labeler::flatten($this->labeler->label($source->expression, $host_entity_type)),
            'source' => $source->toArray(),
          ];
        }
        $slot_memo[$memo_key] = $entries;
      }
      $candidates += $slot_memo[$memo_key];
      // Integer timestamp fields hold datetime data too: offer them for
      // date-string slots, converted via the `unix_to_date` adapter.
      if (\in_array(PropShape::normalizePropSchema($shape->resolvedSchema), self::DATE_STRING_SCHEMAS, TRUE)) {
        $candidates += $this->getTimestampSlotCandidates($is_required, $host_entity_type_id, $host_entity_bundle, $slot_memo);
      }
    }
    return \array_values($candidates);
  }

  /**
   * The (canonicalized) slot schemas that accept a datetime string.
   */
  private const DATE_STRING_SCHEMAS = [
    ['type' => 'string', 'format' => 'date'],
    ['type' => 'string', 'format' => 'date-time'],
  ];

  /**
   * Field types whose (integer) main property is a UNIX timestamp.
   */
  private const TIMESTAMP_FIELD_TYPES = ['changed', 'created', 'timestamp'];

  /**
   * Computes timestamp-field candidates for date-string adapter input slots.
   *
   * Datetime data reaches a date-shaped adapter input in two field forms:
   * datetime string fields match the slot's shape directly, while integer
   * timestamp fields (e.g. an entity's created/changed) need a conversion
   * first. These candidates carry it built in, via the single-input `adapter`
   * shortcut on EntityFieldPropSource — the same mechanism the hard-coded
   * created/changed suggestions for `format: date` props use.
   *
   * @param bool $is_required
   *   Whether only never-empty fields may populate this slot. The `created`
   *   and `changed` field types are computed on save and always carry a
   *   value; a plain `timestamp` field only qualifies when it is required.
   * @param array<string, mixed> $slot_memo
   *
   * @return array<string, array{id: string, label: string, source: array<string, mixed>}>
   *   Candidate entries keyed by expression, ready to merge into a slot's
   *   candidate list.
   */
  private function getTimestampSlotCandidates(bool $is_required, string $host_entity_type_id, string $host_entity_bundle, array &$slot_memo): array {
    $memo_key = 'candidates-timestamp:' . (int) $is_required;
    if (!\array_key_exists($memo_key, $slot_memo)) {
      $host_entity_type = BetterEntityDataDefinition::create($host_entity_type_id, $host_entity_bundle);
      $entries = [];
      foreach ($host_entity_type->getPropertyDefinitions() as $field_name => $field_definition) {
        if (!$field_definition instanceof FieldDefinitionInterface || !\in_array($field_definition->getType(), self::TIMESTAMP_FIELD_TYPES, TRUE)) {
          continue;
        }
        if ($is_required && $field_definition->getType() === 'timestamp' && !$field_definition->isRequired()) {
          continue;
        }
        $source = (new EntityFieldPropSource(
          new FieldPropExpression($host_entity_type, $field_name, NULL, 'value'),
        ))->withAdapter('unix_to_date');
        if ($this->isConsideredIrrelevant($source->expression)) {
          continue;
        }
        $entries[$source->asChoice()] = [
          'id' => \hash('xxh64', $source->asChoice() . ':unix_to_date'),
          'label' => (string) Labeler::flatten($this->labeler->label($source->expression, $host_entity_type)),
          'source' => $source->toArray(),
        ];
      }
      $slot_memo[$memo_key] = $entries;
    }
    return $slot_memo[$memo_key];
  }

  /**
   * Computes the static prop source template for an adapter input slot.
   *
   * @param array<string, mixed> $slot_memo
   *
   * @return array<string, mixed>|null
   *   The array representation of an empty StaticPropSource for the given
   *   shape (the client fills in its `value`), or NULL if the shape is not
   *   storable.
   */
  private function getStaticSourceTemplate(PropShape $shape, array &$slot_memo): ?array {
    $memo_key = 'static:' . $shape->uniquePropSchemaKey();
    if (!\array_key_exists($memo_key, $slot_memo)) {
      $storable = $this->propShapeRepository->getStorablePropShape($shape);
      $slot_memo[$memo_key] = $storable?->toStaticPropSource()->toArray();
    }
    return $slot_memo[$memo_key];
  }

  public static function structureSuggestionsForResponse(array $suggestions): array {
    // @todo Remove this after refactoring ::suggest() in https://www.drupal.org/i/3523446 to stop returning a nested array keyed by prop source type, and instead return an array of prop source objects.
    $combined_suggestions = [];
    foreach ($suggestions as $key => $value) {
      $combined_suggestions[$key] = [
        ...$value[PropSource::EntityField->value],
        ...$value[PropSource::HostEntityUrl->value],
        ...$value[PropSource::HostEntity->value],
      ];
    }

    return array_combine(
      // Top-level keys: the prop names of the targeted component.
      \array_map(
        fn (string $key): string => ComponentPropExpression::fromString($key)->propName,
        \array_keys($suggestions),
      ),
      \array_map(
        // Second level keys: opaque identifiers for the suggestions to
        // populate the component prop.
        function (array $prop_sources, array $adapter_suggestions): array {
          $structured = array_combine(
            \array_map(
              fn (LinkablePropSourceInterface $prop_source): string => \hash('xxh64', $prop_source->asChoice()),
              array_values($prop_sources),
            ),
            // Values: objects with "label" and "source" keys, with:
            // - "label": the human-readable label that the Content Template UI
            //   should present to the human
            // - "source": the array representation of the prop source that, if
            //   selected by the human, the client should use verbatim as the
            //   source to populate this component instance's prop.
            \array_map(
              function (string $label, LinkablePropSourceInterface $prop_source) {
                return [
                  'label' => $label,
                  'source' => $prop_source->toArray(),
                ];
              },
              \array_keys($prop_sources),
              array_values($prop_sources),
            ),
          );
          // Adapter suggestions rank after all direct matches. Instead of a
          // ready-to-use "source", they carry an "adapter" key describing how
          // the client can let a human configure an AdaptedPropSource.
          // @see ::buildAdapterSuggestions()
          foreach ($adapter_suggestions as $adapter_suggestion) {
            $structured[$adapter_suggestion['id']] = [
              'label' => $adapter_suggestion['label'],
              'adapter' => $adapter_suggestion['adapter'],
            ];
          }
          return $structured;
        },
        $combined_suggestions,
        \array_map(
          fn (array $value): array => $value[PropSource::Adapter->value] ?? [],
          \array_values($suggestions),
        ),
      )
    );
  }

  private static function enrichSuggestion(array $suggestion): array {
    \assert(\array_key_exists('label', $suggestion));
    \assert(\array_key_exists('source', $suggestion));
    \assert(\is_array($suggestion['source']));
    \assert(\array_key_exists('sourceType', $suggestion['source']));
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
        // EntityFieldPropSources have hierarchy: infer depth from label;
        // determines hierarchy building order.
        PropSource::EntityField->value => $depth,
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
      if (\array_key_exists('items', $value)) {
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
      // 0. Set aside adapter suggestions: they have no hierarchy, and rank
      // after all direct matches.
      $adapter_suggestions = \array_filter(
        $suggestions,
        fn (array $suggestion): bool => \array_key_exists('adapter', $suggestion),
      );
      $suggestions = \array_diff_key($suggestions, $adapter_suggestions);

      // 1. Enrich this prop's suggestions. The sorting is already correct based
      // on the form display.
      $enriched_suggestions = \array_map(
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

      // 4. Append the adapter suggestions, after all direct matches.
      $hierarchical_response[$prop_name] = [
        ...$hierarchical_suggestions,
        ...\array_map(
          fn (string $opaque_id, array $suggestion): array => ['id' => $opaque_id, ...$suggestion],
          \array_keys($adapter_suggestions),
          \array_values($adapter_suggestions),
        ),
      ];
    }

    return $hierarchical_response;
  }

}
