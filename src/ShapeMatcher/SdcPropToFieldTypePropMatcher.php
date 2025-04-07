<?php

declare(strict_types=1);

namespace Drupal\experience_builder\ShapeMatcher;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;
use Drupal\Core\TypedData\Plugin\DataType\BooleanData;
use Drupal\Core\TypedData\Plugin\DataType\FloatData;
use Drupal\Core\TypedData\Plugin\DataType\IntegerData;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint;
use Drupal\experience_builder\JsonSchemaInterpreter\SdcPropJsonSchemaType;
use Drupal\experience_builder\Plugin\AdapterManager;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Drupal\file\Plugin\Field\FieldType\FileUriItem;
use Symfony\Component\Validator\Constraint;

// phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
// phpcs:disable Drupal.Commenting.ClassComment.Missing
// phpcs:disable Drupal.Commenting.DocComment.MissingShort
// phpcs:disable Drupal.Commenting.FunctionComment.Missing
// phpcs:disable Drupal.Commenting.FunctionComment.MissingParamComment
// phpcs:disable Drupal.Files.LineLength.TooLong
// phpcs:disable Drupal.Semantics.FunctionTriggerError.TriggerErrorTextLayoutRelaxed
/**
 * @phpstan-import-type JsonSchema from \Drupal\experience_builder\JsonSchemaInterpreter\SdcPropJsonSchemaType
 */
final class SdcPropToFieldTypePropMatcher {

  public function __construct(
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly ConstraintManager $constraintManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly AdapterManager $adapterManager,
  ) {}

  /**
   * @see https://json-schema.org/understanding-json-schema/reference/type
   * TRICKY: relying on \Drupal\Core\TypedData\Type\*Interface is not possible
   * because that interface conveys semantics, not storage mechanism. For
   * example: DurationInterface has 2 implementations in Drupal core:
   * - \Drupal\Core\TypedData\Plugin\DataType\TimeSpan, which is an integer
   * - \Drupal\Core\TypedData\Plugin\DataType\DurationIso8601, which is a string
   *
   * @param JsonSchema $sub_schema
   *
   * @return array<int, \Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression>
   */

  /**
   * @param JsonSchema $schema
   *
   * @return array<int, \Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression>
   */

  /**
   * @param JsonSchema $schema
   */
  public function iterateJsonSchema(array $schema): \Generator {
    $schema = self::resolveSchemaReferences($schema);
    $primitive_type = SdcPropJsonSchemaType::from(
    // TRICKY: SDC always allowed `object` for Twig integration reasons.
    // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
      is_array($schema['type']) ? $schema['type'][0] : $schema['type']
    );

    if (!$primitive_type->isIterable()) {
      throw new \LogicException('Can only iterate iterable JSON schema types: array or object.');
    }

    if ($primitive_type === SdcPropJsonSchemaType::OBJECT) {
      foreach ($schema['properties'] ?? [] as $prop_name => $prop_schema) {
        yield $prop_name => [
          // @see https://json-schema.org/understanding-json-schema/reference/object#required
          // @see https://json-schema.org/learn/getting-started-step-by-step#required
          'required' => in_array($prop_name, $schema['required'] ?? [], TRUE),
          'schema' => self::resolveSchemaReferences($prop_schema),
        ];
      }
    }
    else {
      throw new \LogicException('Support for "array" props is not yet implemented.');
    }
  }

  /**
   * @todo Make *recursive* references work in justinrainbow/schema, see https://git.drupalcode.org/project/ui_patterns/-/blob/28cf60dd776fb349d9520377afa510b0d85f3334/src/SchemaManager/ReferencesResolver.php
   *
   * @param JsonSchema $schema
   * @return JsonSchema
   *
   * @see \Drupal\experience_builder\Plugin\Adapter\AdapterBase::resolveSchemaReferences
   */
  private static function resolveSchemaReferences(array $schema): array {
    if (isset($schema['$ref'])) {
      // Perform the same schema resolving as `justinrainbow/json-schema`.
      // @todo Delete this method, actually use `justinrainbow/json-schema`.
      $schema = json_decode(file_get_contents($schema['$ref']) ?: '{}', TRUE);
    }
    return $schema;
  }

  /**
   * @param JsonSchema $schema
   * @return ($levels_to_recurse is positive-int ? array<int, \Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression> : array<int, \Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression>)
   */
  private function matchEntityProps(EntityDataDefinitionInterface $entity_data_definition, int $levels_to_recurse, SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, ?array $schema): array {
    if ($primitive_type->isScalar()) {
      return $this->matchEntityPropsForScalar($entity_data_definition, $levels_to_recurse, $primitive_type, $is_required_in_json_schema, $schema);
    }
    else {
      assert(is_array($schema));
      return $this->matchEntityPropsForIterable($entity_data_definition, $levels_to_recurse, $primitive_type, $is_required_in_json_schema, $schema);
    }
  }

  /**
   * @param JsonSchema $schema
   * @return array<int, \Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression>
   */
  private function matchEntityPropsForIterable(EntityDataDefinitionInterface $entity_data_definition, int $levels_to_recurse, SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, array $schema): array {
    if (!$primitive_type->isIterable()) {
      throw new \LogicException();
    }

    $required_object_props = [];
    $all_object_props = [];
    $object_prop_matches = [];
    foreach ($this->iterateJsonSchema($schema) as $name => ['required' => $sub_required, 'schema' => $sub_schema]) {
      $all_object_props[] = $name;
      if ($sub_required) {
        $required_object_props[] = $name;
      }
      $object_prop_matches[$name] = $this->matchEntityProps($entity_data_definition, $levels_to_recurse, SdcPropJsonSchemaType::from($sub_schema['type']), $sub_required, $sub_schema);
    }

    // Invert $object_prop_matches to determine different match types.
    $inverted = [];
    foreach (array_keys($object_prop_matches) as $object_prop_name) {
      foreach ($object_prop_matches[$object_prop_name] as $field_prop_expr) {
        $field_name = match (get_class($field_prop_expr)) {
          FieldPropExpression::class => $field_prop_expr->fieldName,
          ReferenceFieldPropExpression::class => $field_prop_expr->referencer->fieldName,
          default => throw new \LogicException('Unhandled.'),
        };
        // The same field name prop should never be used multiple times; best
        // match is selected in object prop order.
        if (in_array($field_prop_expr, $inverted[$field_name] ?? [], FALSE)) {
          continue;
        }
        // Pick the first match, except:
        if (isset($inverted[$field_name][$object_prop_name])) {
          // 1. prefer non-reference matches on the field.
          if ($inverted[$field_name][$object_prop_name] instanceof ReferenceFieldPropExpression && $field_prop_expr instanceof FieldPropExpression) {
            $inverted[$field_name][$object_prop_name] = $field_prop_expr;
          }
          // 2. prefer a precise match between the SDC object prop name and the
          //    the field prop name
          elseif ($field_prop_expr instanceof FieldPropExpression && $object_prop_name === $field_prop_expr->propName) {
            $inverted[$field_name][$object_prop_name] = $field_prop_expr;
          }
          elseif ($field_prop_expr instanceof ReferenceFieldPropExpression && $object_prop_name === $field_prop_expr->referencer->propName) {
            $inverted[$field_name][$object_prop_name] = $field_prop_expr;
          }
        }
        else {
          $inverted[$field_name][$object_prop_name] = $field_prop_expr;
        }
      }
    }

    // The minimal match: all required object props are present.
    $matches_minimal = array_filter(
      $inverted,
      fn ($supported_object_props) => empty(array_diff($required_object_props, array_keys($supported_object_props)))
    );
    ksort($matches_minimal);

    // The complete match: the complete set of object props is present.
    $matches_complete = array_filter(
      $inverted,
      fn ($supported_object_props) => array_keys($supported_object_props) == $all_object_props
    );
    ksort($matches_complete);

    $matches = [];
    // Prefer complete matches: list complete matches before minimal matches.
    foreach ($matches_complete + $matches_minimal as $field_name => $mapping) {
      // @todo Support nested/recursive/chained FieldObjectPropsExpression?
      /** @var array<string, \Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression> $mapping */
      $matches[] = new FieldObjectPropsExpression($entity_data_definition, $field_name, NULL, $mapping);
    }
    return $matches;
  }

  /**
   * @param JsonSchema $schema
   * @return array<int, \Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression>
   */
  private function matchEntityPropsForScalar(EntityDataDefinitionInterface $entity_data_definition, int $levels_to_recurse, SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, ?array $schema): array {
    if (!$primitive_type->isScalar()) {
      throw new \LogicException();
    }

    $matches = [];
    $field_definitions = $this->recurseDataDefinitionInterface($entity_data_definition);
    foreach ($field_definitions as $field_definition) {
      assert($field_definition instanceof FieldDefinitionInterface);
      if ($is_required_in_json_schema && !$field_definition->isRequired()) {
        continue;
      }
      $properties = $this->recurseDataDefinitionInterface($field_definition);
      foreach ($properties as $property_name => $property_definition) {
        // Never match properties that are:
        // 1. DataReferenceTargetDefinitions: these are the internal
        //    implementation detail (typically named `target_id`) powering the
        //    twin DataReferenceDefinitionInterface (typically named `entity`)
        // 2. explicitly marked as internal (which means ::isInternal() cannot
        //    be used, due to its fallback to ::isComputed())
        // 3. on read-only non-computed base fields: these store non-user data such as the
        //    monotonically increasing integer entity ID, bundle name, entity
        //    UUID and so on.
        //    For now, the "uuid" field, to allow testing that SDC prop shape.
        // @phpstan-ignore-next-line
        if ($property_definition instanceof DataReferenceTargetDefinition || $property_definition['internal'] === TRUE) {
          continue;
        }
        if ($field_definition instanceof BaseFieldDefinition && $field_definition->getName() !== 'uuid' && $field_definition->isReadOnly() && !$property_definition->isComputed()) {
          continue;
        }
        $is_reference = $this->dataLeafIsReference($property_definition);
        if ($is_reference === NULL) {
          // Neither a reference nor a primitive.
          continue;
        }
        $current_entity_field_prop = new FieldPropExpression(
          $entity_data_definition,
          $field_definition->getName(),
          NULL,
          $property_name,
        );
        if ($is_reference) {
          if ($levels_to_recurse === 0) {
            continue;
          }
          // Only follow entity references, as deep as specified.
          // @see ::findFieldTypeStorageCandidates()
          if ($property_definition instanceof DataReferenceDefinitionInterface && is_a($property_definition->getClass(), EntityReference::class, TRUE)) {
            $target = $property_definition->getTargetDefinition();
            assert($target instanceof EntityDataDefinitionInterface);
            // When referencing an entity, enrich the EntityDataDefinition with
            // constraints that are imposed by the entity reference field, to
            // narrow the matching.
            // @todo Generalize this so it works for all entity reference field types that do not allow *any* entity of the target entity type to be selected
            if (is_a($field_definition->getItemDefinition()->getClass(), FileItem::class, TRUE)) {
              $field_item = $this->typedDataManager->createInstance("field_item:" . $field_definition->getType(), [
                'name' => $field_definition->getName(),
                'parent' => NULL,
                'data_definition' => $field_definition->getItemDefinition(),
              ]);
              assert($field_item instanceof FileItem);
              $target->addConstraint('FileExtension', $field_item->getUploadValidators()['FileExtension']);
            }
            $referenced_matches = $this->matchEntityProps($target, $levels_to_recurse - 1, $primitive_type, $is_required_in_json_schema, $schema);
            foreach ($referenced_matches as $referenced_match) {
              $matches[] = new ReferenceFieldPropExpression($current_entity_field_prop, $referenced_match);
            }
          }
        }
        else {
          $entity_constraints = $entity_data_definition->getConstraints();
          if (!empty($entity_constraints)) {
            // Transform an entity-level `FileExtension` constraint to
            // corresponding property-level constraint.
            // @see \Drupal\file\Plugin\Validation\Constraint\FileExtensionConstraintValidator
            if (array_key_exists('FileExtension', $entity_data_definition->getConstraints()) && is_a($field_definition->getItemDefinition()->getClass(), FileUriItem::class, TRUE)) {
              // Clone to avoid polluting any static caches.
              // @todo verify if truly necessary?
              $transformed_property_data_definition = clone $property_definition;
              // @todo JSON schema does not support case-insensitive matching!!!! https://json-schema.org/understanding-json-schema/reference/regular_expressions
              $trailing_uri_regex_pattern = '\.(' . preg_replace('/ +/', '|', preg_quote($entity_constraints['FileExtension']['extensions'])) . ')(\?.*)?(#.*)?$';
              // If a `Regex` constraint exists, expand it to also match the trailing part.
              // @todo verify the regex constraint currently only matches the leading part.
              if ($regex_constraint = $transformed_property_data_definition->getConstraint('Regex')) {
                assert(str_starts_with($regex_constraint['pattern'], '/^'));
                // Because we are concatenating the regex pattern with another
                // pattern that applies to the end of the line the existing
                // pattern cannot contain a `$` which is the end of line
                // metacharacter.
                // @todo Make this check smarter to handle cases like:
                //   '\$/': should not match because this is literal '$'
                //   '\\$/': should match because '$' is an end of line
                if (str_ends_with($regex_constraint['pattern'], '$/')) {
                  throw new \LogicException(sprintf('The property %s for the field %s uses Regex constraint pattern, %s, that includes an end-of-line metacharacter, `$`,  which is not allowed when also using a FileExtension constraint', $property_name, $regex_constraint['pattern'], $field_definition->getName()));
                }
                assert(str_ends_with($regex_constraint['pattern'], '/'));
                // Trim the trailing slash away. (Using `rtrim()` is incorrect:
                // it would trim _all_ trailing slashes away.)
                $regex_constraint['pattern'] = substr($regex_constraint['pattern'], 0, -1);
                $regex_constraint['pattern'] .= '.*' . $trailing_uri_regex_pattern . '/';
                $transformed_property_data_definition->addConstraint('Regex', $regex_constraint);
              }
              else {
                $transformed_property_data_definition->addConstraint('Regex', [
                  'pattern' => $trailing_uri_regex_pattern,
                ]);
              }
              $property_definition = $transformed_property_data_definition;
            }
          }
          assert(is_a($property_definition->getClass(), PrimitiveInterface::class, TRUE));
          $field_item = $this->typedDataManager->createInstance("field_item:" . $field_definition->getType(), [
            'name' => NULL,
            'parent' => NULL,
            'data_definition' => $field_definition->getItemDefinition(),
          ]);
          $property = $this->typedDataManager->create(
            $property_definition,
            NULL,
            $property_name,
            $field_item,
          );
          if ($this->dataLeafMatchesFormat($property, $primitive_type, $is_required_in_json_schema, $schema)) {
            $matches[] = $current_entity_field_prop;
          }
        }
      }
    }
    return $matches;
  }

  /**
   * @param JsonSchema $schema
   * @return array<int, \Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression>
   */
  public function findFieldInstanceFormatMatches(SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, array $schema): array {
    $entity_type_bundles = $this->entityTypeBundleInfo->getAllBundleInfo();
    $matches = [];
    foreach ($entity_type_bundles as $entity_type_id => $bundles) {
      foreach (array_keys($bundles) as $bundle) {
        $entity_data_definition = EntityDataDefinition::createFromDataType("entity:$entity_type_id:$bundle");
        $matches = [
          ...$matches,
          ...$this->matchEntityProps($entity_data_definition, 1, $primitive_type, $is_required_in_json_schema, $schema),
        ];
      }
    }
    /** @var array<\Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression> */
    $keyed_by_string = array_combine(array_map(fn ($e) => (string) $e, $matches), $matches);
    ksort($keyed_by_string);
    return array_values($keyed_by_string);
  }

  private function dataDefinitionMatchesPrimitiveType(DataDefinitionInterface $data_definition, SdcPropJsonSchemaType $json_schema_primitive_type, bool $is_required_in_json_schema): bool {
    $data_type_class = $data_definition->getClass();

    // Any data type that is more complex than a primitive is not accepted.
    // For example: `entity_reference`, `language_reference`, etc.
    // @see \Drupal\Core\Entity\Plugin\DataType\EntityReference
    if (!is_a($data_type_class, PrimitiveInterface::class, TRUE)) {
      throw new \LogicException();
    }

    $field_primitive_types = match (TRUE) {
      is_a($data_type_class, StringData::class, TRUE) => [SdcPropJsonSchemaType::STRING],
      // TRICKY: a SDC prop that accepts number, can accept both an integer and a
      // float, but an SDC prop that accepts integer, can accept only integer.
      is_a($data_type_class, IntegerData::class, TRUE) => [SdcPropJsonSchemaType::INTEGER, SdcPropJsonSchemaType::NUMBER],
      is_a($data_type_class, FloatData::class, TRUE) => [SdcPropJsonSchemaType::NUMBER],
      is_a($data_type_class, BooleanData::class, TRUE) => [SdcPropJsonSchemaType::BOOLEAN],
      // @todo object + array
      // - for object: initially support only a single level of nesting, then we can expect HERE a ComplexDataInterface with only primitives underneath (hence all leaves)
      // - for array: ListDefinitionInterface
      TRUE => [],
    };

    // If the primitive type does not match, this is not a candidate.
    if (!in_array($json_schema_primitive_type, $field_primitive_types)) {
      return FALSE;
    }

    // If it is required in SDC's JSON schema, it must be required in Drupal's
    // Typed Data too; otherwise there is a risk of violating SDC's schema.
    if ($is_required_in_json_schema && !$data_definition->isRequired()) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * @param JsonSchema $schema
   */
  private function dataLeafMatchesFormat(TypedDataInterface $data, SdcPropJsonSchemaType $json_schema_primitive_type, bool $is_required_in_json_schema, ?array $schema): bool {
    if (!$data->getParent()) {
      throw new \LogicException('must be a property with a field item as context for format checking');
    }
    $property_data_definition = $data->getDataDefinition();
    if (!$this->dataDefinitionMatchesPrimitiveType($property_data_definition, $json_schema_primitive_type, $is_required_in_json_schema)) {
      return FALSE;
    }

    // If the precise JSON schema is not specified, this only needs to match the
    // primitive type.
    if ($schema === NULL) {
      return TRUE;
    }

    $required_shape = $json_schema_primitive_type->toDataTypeShapeRequirements($schema);

    // One of SdcPropJsonSchemaType, with no additional requirements.
    if ($required_shape === FALSE) {
      return TRUE;
    }

    $field_item = $data->getParent();
    assert($field_item instanceof FieldItemInterface);
    $field_property_name = $data->getName();

    // TRICKY: to correctly merge these, these arrays must be rekeyed to allow
    // the field type to override default property-level constraints.
    $rekey = function (array $constraints) {
      return array_combine(
        array_map(
          fn (Constraint $c): string => get_class($c),
          $constraints,
        ),
        $constraints
      );
    };

    // Gather all constraints that apply to this field item property. Note:
    // 1. all field item properties are DataType plugin instances
    // 2. DataType plugin definitions can define constraints
    // 3. all FieldType plugins defines which properties they contain and what
    //    DataType plugins they use in its `::propertyDefinitions()`
    // 4. in that `::propertyDefinitions()`, FieldType plugins can override the
    //    default constraints
    // 5. (per `DataDefinitionInterface::getConstraints()`, each constraint can
    //    be used only once — hence only overriding is possible)
    // 6. FieldType plugins can can narrow a particular use of a DataType
    //    further based on configuration in their `::getConstraints()` method by
    //    adding a `ComplexData` constraint; any constraint added here trumps a
    //    constraint defined at the property level
    //    e.g.: \Drupal\Core\Field\Plugin\Field\FieldType\NumericItemBase::getConstraints()
    // 7. EntityType plugins can similarly narrow the use of a DataType by
    //    calling `::addPropertyConstraints()` in their
    //    `::baseFieldDefinitions()`
    //   e.g.: \Drupal\path_alias\Entity\PathAlias::baseFieldDefinitions()
    // @see \Drupal\Core\TypedData\DataDefinition::addConstraint()
    // @see \Drupal\Core\Field\BaseFieldDefinition::addPropertyConstraints()
    // @see \Drupal\Core\Field\FieldConfigInterface::addPropertyConstraints()
    // @see \Drupal\Core\Field\FieldItemInterface::propertyDefinitions()
    // @see \Drupal\Core\TypedData\DataDefinitionInterface::getConstraints()
    // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint
    // @see \Drupal\Core\Field\Plugin\Field\FieldType\NumericItemBase::getConstraints()
    $property_level_constraints = $rekey($data->getConstraints());
    $field_item_level_constraints = [];
    foreach ($field_item->getConstraints() as $field_item_constraint) {
      if ($field_item_constraint instanceof ComplexDataConstraint) {
        $field_item_level_constraints += $rekey($field_item_constraint->properties[$field_property_name] ?? []);
      }
    }
    $constraints = $field_item_level_constraints + $property_level_constraints;

    if ($required_shape instanceof DataTypeShapeRequirement) {
      if ($required_shape->constraint === 'NOT YET SUPPORTED') {
        @trigger_error(sprintf("NOT YET SUPPORTED: a `%s` Drupal field data type that matches the JSON schema %s.", $json_schema_primitive_type->value, json_encode($schema)), E_USER_DEPRECATED);
        return FALSE;
      }

      return $this->dataTypeShapeRequirementMatchesFinalConstraintSet($required_shape, $property_data_definition, $constraints);
    }
    else {
      // If there's >1 requirement, they must all be met.
      foreach ($required_shape->requirements as $r) {
        if (!$this->dataTypeShapeRequirementMatchesFinalConstraintSet($r, $property_data_definition, $constraints)) {
          if ($r->constraint === 'NOT YET SUPPORTED') {
            @trigger_error(sprintf("NOT YET SUPPORTED: a `%s` Drupal field data type that matches the JSON schema %s.", $json_schema_primitive_type->value, json_encode($schema)), E_USER_DEPRECATED);
            return FALSE;
          }
          return FALSE;
        }
      }
      return TRUE;
    }
  }

  /**
   * @param array<string, \Symfony\Component\Validator\Constraint> $constraints
   */
  private function dataTypeShapeRequirementMatchesFinalConstraintSet(DataTypeShapeRequirement $required_shape, DataDefinitionInterface $property_data_definition, array $constraints): bool {
    // Any data type that is more complex than a primitive is not accepted.
    // For example: `entity_reference`, `language_reference`, etc.
    // @see \Drupal\Core\Entity\Plugin\DataType\EntityReference
    if (!is_a($property_data_definition->getClass(), PrimitiveInterface::class, TRUE)) {
      throw new \LogicException();
    }

    // Is the data shape requirement met?
    // 1. Constraint.
    $constraint_found = in_array(
      $this->constraintManager->create($required_shape->constraint, $required_shape->constraintOptions),
      $constraints
    );
    // 2. Optionally: the interface.
    $interface_found = $required_shape->interface === NULL
      || is_a($property_data_definition->getClass(), $required_shape->interface, TRUE);
    return $constraint_found && $interface_found;
  }

  /**
   * @return \Drupal\Core\TypedData\DataDefinitionInterface[]
   */
  private function recurseDataDefinitionInterface(DataDefinitionInterface $dd): array {
    return match (TRUE) {
      // Entity level.
      $dd instanceof EntityDataDefinitionInterface => (function ($dd) {
        if ($dd->getClass() === ConfigEntityAdapter::class) {
          // @todo load config entity type, look at export properties?
          return [];
        }
        assert($dd->getClass() === EntityAdapter::class);
        $entity_type_id = $dd->getEntityTypeId();
        assert(is_string($entity_type_id));
        // If no bundles or multiple bundles are specified, inspect the base
        // fields. Otherwise (if a single bundle is specified), inspect all
        // fields.
        if ($dd->getBundles() !== NULL && count($dd->getBundles()) === 1) {
          return $this->entityFieldManager->getFieldDefinitions($entity_type_id, $dd->getBundles()[0]);
        }
        return $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);
      })($dd),
      // Field level.
      $dd instanceof FieldDefinitionInterface => $this->recurseDataDefinitionInterface($dd->getItemDefinition()),
      $dd instanceof FieldItemDataDefinitionInterface => $dd->getPropertyDefinitions(),
      default => throw new \LogicException('Unhandled.'),
    };
  }

  private function dataLeafIsReference(TypedDataInterface|DataDefinitionInterface $td_or_dd): ?bool {
    if ($td_or_dd instanceof TypedDataInterface && !$td_or_dd->getParent() instanceof FieldItemInterface) {
      throw new \LogicException(__METHOD__ . ' was given a non-leaf.');
    }
    $dd = $td_or_dd instanceof TypedDataInterface
      ? $td_or_dd->getDataDefinition()
      : $td_or_dd;
    return match(TRUE) {
      $dd instanceof DataReferenceDefinitionInterface => TRUE,
      is_a($dd->getClass(), PrimitiveInterface::class, TRUE) => FALSE,
      // Anything else cannot be handled and merits logging.
      TRUE => (function ($td_or_dd) {
        match (TRUE) {
          // PHPStan does not like this because getParent()->getDataDefinition()
          // only has a less specific type guarantee. But … this is just for
          // logging unhandled cases. This is sufficient as-is.
          // @phpstan-ignore-next-line
          $td_or_dd instanceof TypedDataInterface => @trigger_error(sprintf("Unhandled data type class: `%s` Drupal field type `%s` property uses `%s` data type class that is not yet supported", $td_or_dd->getParent()->getDataDefinition()->getFieldDefinition()->getType(), $td_or_dd->getName(), $td_or_dd->getDataDefinition()->getClass()), E_USER_DEPRECATED),
          $td_or_dd instanceof DataDefinitionInterface => @trigger_error(sprintf("Unhandled data type class: `%s` data type class that is not yet supported", $td_or_dd->getClass()), E_USER_DEPRECATED),
        };
        return NULL;
      })($td_or_dd),
    };
  }

  /**
   * @param JsonSchema $schema
   * @return \Drupal\experience_builder\Plugin\Adapter\AdapterInterface[]
   */
  public function findAdaptersByMatchingOutput(array $schema): array {
    return $this->adapterManager->getDefinitionsByOutputSchema($schema);
  }

}
