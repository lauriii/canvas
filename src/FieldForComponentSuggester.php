<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Plugin\Adapter\AdapterInterface;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;

/**
 * @todo Rename things for clarity: this handles all props for an SDC simultaneously, SdcPropToFieldTypePropMatcher handles a single prop at a time
 */
final class FieldForComponentSuggester {

  public function __construct(
    private readonly SdcPropToFieldTypePropMatcher $propMatcher,
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
  ) {}

  /**
   * @param string $component_plugin_id
   *
   * @return array<string, array{required: bool, types: array<string, \Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression>, instances: array<string, \Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression>, adapters: array<AdapterInterface>}>
   */
  public function suggest(string $component_plugin_id, EntityDataDefinitionInterface $host_entity_type): array {
    $host_entity_type_id = $host_entity_type->getEntityTypeId();
    assert(is_string($host_entity_type_id));
    $bundles = $host_entity_type->getBundles();
    assert(is_array($bundles) && array_key_exists(0, $bundles));
    $host_entity_type_bundle = $bundles[0];

    // 1. Get raw matches.
    $raw_matches = $this->getRawMatches($component_plugin_id);

    // 2. Process (filter and order) matches based on context and what Drupal
    //    considers best practices.
    $processed_matches = [];
    foreach ($raw_matches as $cpe => $m) {
      // Filter field type matches:
      $field_type_prop_expressions = array_filter($m['types'], fn ($e) => $e instanceof FieldTypePropExpression);
      $field_type_object_prop_expressions = array_filter($m['types'], fn ($e) => $e instanceof FieldTypeObjectPropsExpression);
      $reference_field_type_prop_expressions = array_filter($m['types'], fn ($e) => $e instanceof ReferenceFieldTypePropExpression);
      // - among the FieldTypePropExpressions: filter to the ones matching
      //   narrowly, i.e. with as few additional properties as possible
      if (!empty($field_type_prop_expressions)) {
        $field_type_property_counts = array_map(
          // @phpstan-ignore-next-line
          fn(FieldTypePropExpression $e) => count($this->typedDataManager->createDataDefinition("field_item:{$e->fieldType}")
            ->getPropertyDefinitions()),
          $field_type_prop_expressions
        );
        arsort($field_type_property_counts);
        $keys_of_expressions_with_minimal_property_count = array_intersect($field_type_property_counts, [min($field_type_property_counts)]);
        $field_type_prop_expressions = array_intersect_key($field_type_prop_expressions, $keys_of_expressions_with_minimal_property_count);
      }
      // - @todo filter/order FieldTypeObjectPropsExpression
      // Order field type matches:
      // - first suggest the reference field types, to encourage reusing
      //   existing structured data
      // - then everything else
      $processed_matches[$cpe]['types'] = [
        ...$reference_field_type_prop_expressions,
        ...$field_type_prop_expressions,
        ...$field_type_object_prop_expressions,
      ];

      // Instance matches: filter to the ones matching the current host entity
      // type + bundle.
      $processed_matches[$cpe]['instances'] = array_filter(
        $m['instances'],
        fn (FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $e) => $e instanceof ReferenceFieldPropExpression
          ? $e->referencer->entityType->getDataType() === $host_entity_type->getDataType()
          : $e->entityType->getDataType() === $host_entity_type->getDataType()
      );

      // @todo filtering
      $processed_matches[$cpe]['adapters'] = $m['adapters'];
    }

    // 3. Generate appropriate labels for each. And specify whether required.
    $suggestions = [];
    foreach ($processed_matches as $cpe => $m) {
      // Required property or not?
      $prop_name = ComponentPropExpression::fromString($cpe)->propName;
      $component = $this->componentPluginManager->find($component_plugin_id);
      /** @var array<string, mixed> $schema */
      $schema = $component->metadata->schema;
      $suggestions[$cpe]['required'] = in_array($prop_name, $schema['required'], TRUE);

      // Field types.
      // @todo Ensure these expressions do not break: https://www.drupal.org/project/experience_builder/issues/3450957
      $suggestions[$cpe]['types'] = array_combine(
        array_map(
          // @todo Defensive edge case: multiple field types with the same label.
          fn (FieldTypePropExpression|FieldTypeObjectPropsExpression|ReferenceFieldTypePropExpression $e) => $this->fieldTypePluginManager->getDefinition(
            $e instanceof ReferenceFieldTypePropExpression
              ? $e->referencer->fieldType
              : $e->fieldType
          )['label'],
          $m['types']
        ),
        $m['types']
      );

      // Field instances.
      // @todo Ensure these expressions do not break: https://www.drupal.org/project/experience_builder/issues/3452848
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($host_entity_type_id, $host_entity_type_bundle);
      $suggestions[$cpe]['instances'] = array_combine(
        array_map(
          // @todo Defensive edge case: multiple field instances with the same label.
          function (FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $e) use ($field_definitions, $host_entity_type_id, $host_entity_type_bundle) {
            $field_name = $e instanceof ReferenceFieldPropExpression
              ? $e->referencer->fieldName
              : $e->fieldName;
            $field_definition = $field_definitions[$field_name];
            assert($field_definition instanceof FieldDefinitionInterface);
            return (string) t("This @entity's @field-label", [
              '@entity' => $this->entityTypeBundleInfo->getBundleInfo($host_entity_type_id)[$host_entity_type_bundle]['label'],
              '@field-label' => $field_definition->getLabel(),
            ]);
          },
          $m['instances']
        ),
        $m['instances']
      );

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
   * @return array<string, array{types: array<int, \Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression>, instances: array<int, \Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression>, adapters: array<\Drupal\experience_builder\Plugin\Adapter\AdapterInterface>}>
   */
  private function getRawMatches(string $component_plugin_id): array {
    $raw_matches = [];

    $component = $this->componentPluginManager->find($component_plugin_id);
    /** @var array<string, mixed> $schema */
    $schema = $component->metadata->schema;
    foreach ($this->propMatcher->iterateJsonSchema($schema) as $prop_name => [
      'required' => $is_required,
      'schema' => $schema,
    ]) {
      $cpe = new ComponentPropExpression($component_plugin_id, $prop_name);

      // TRICKY: `attributes` is a special case — it is kind of a reserved
      // prop.
      // @see \Drupal\sdc\Twig\TwigExtension::mergeAdditionalRenderContext()
      // @see https://www.drupal.org/project/drupal/issues/3352063#comment-15277820
      if ($prop_name === 'attributes') {
        assert($schema['type'][0] === Attribute::class);
        continue;
      }

      if (isset($schema['$ref'])) {
        // Prove a $ref URL can be transformed into a publicly accessible URL.
        $stream_wrapper = $this->streamWrapperManager->getViaUri($schema['$ref']);
        assert($stream_wrapper instanceof StreamWrapperInterface);
        $public_ref_url = $stream_wrapper->getExternalUrl();
        assert(str_ends_with($public_ref_url, '/experience_builder/schema.json#defs/' . basename($schema['$ref'])));
      }

      $primitive_type = SdcPropJsonSchemaType::from(
      // TRICKY: SDC always allowed `object` for Twig integration reasons.
      // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
        is_array($schema['type']) ? $schema['type'][0] : $schema['type']
      );

      $format_candidates_main_prop = $this->propMatcher->findFieldTypeFormatCandidates($primitive_type, $is_required, $schema, TRUE);
      $instance_candidates = $this->propMatcher->findFieldInstanceFormatMatches($primitive_type, $is_required, $schema);
      $adapter_candidates = $this->propMatcher->findAdaptersByMatchingOutput($schema);
      $raw_matches[(string) $cpe]['types'] = $format_candidates_main_prop;
      $raw_matches[(string) $cpe]['instances'] = $instance_candidates;
      $raw_matches[(string) $cpe]['adapters'] = $adapter_candidates;
    }

    return $raw_matches;
  }

}
