<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

require_once 'PropExpressions.php';

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\Plugin\DataType\ConfigEntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\Plugin\DataType\BooleanData;
use Drupal\Core\TypedData\Plugin\DataType\FloatData;
use Drupal\Core\TypedData\Plugin\DataType\IntegerData;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint;
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
final class SdcPropToFieldTypePropMatcher {

  public function __construct(
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly ConstraintManager $constraintManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  // @see https://json-schema.org/understanding-json-schema/reference/type
  // @todo Add caching at the appropriate layer: this is guaranteed to return the same within the same request; it depends only on code in enabled modules, not configuration
  // TRICKY: relying on \Drupal\Core\TypedData\Type\*Interface is not possible
  // because that interface conveys semantics, not storage mechanism. For
  // example: DurationInterface has 2 implementations in Drupal core:
  // - \Drupal\Core\TypedData\Plugin\DataType\TimeSpan, which is an integer
  // - \Drupal\Core\TypedData\Plugin\DataType\DurationIso8601, which is a string
  public function findFieldTypeStorageCandidates(SdcPropJsonSchemaType $json_schema_primitive_type, bool $is_required_in_json_schema, ?array $subschema) : array {
    return $this->findFieldTypeProps($json_schema_primitive_type, $is_required_in_json_schema, $subschema, FALSE);
  }

  public function findFieldTypeFormatCandidates(SdcPropJsonSchemaType $json_schema_primitive_type, bool $is_required_in_json_schema, array $schema, bool $main_property_only) {
    return $this->findFieldTypeProps($json_schema_primitive_type, $is_required_in_json_schema, $schema, $main_property_only);
  }

  public function iterateJsonSchema(array $schema): \Generator {
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
          'required' =>  in_array($prop_name, $schema['required'] ?? [], TRUE),
          'schema' => $prop_schema,
        ];
      }
    }
    else {
      throw new \LogicException('Support for "array" props is not yet implemented.');
    }
  }

  public function findFieldTypeProps(SdcPropJsonSchemaType $json_schema_primitive_type, bool $is_required_in_json_schema, ?array $schema, bool $main_property_only) : array {
    return match ($json_schema_primitive_type->isScalar()) {
      TRUE => $this->findFieldTypePropsForScalar($json_schema_primitive_type, $is_required_in_json_schema, $schema, $main_property_only),
      FALSE => $this->findFieldTypePropsForIterable($json_schema_primitive_type, $schema),
    };
  }

  public function findFieldTypePropsForIterable(SdcPropJsonSchemaType $json_schema_primitive_type, ?array $schema) : array {
    if (!$json_schema_primitive_type->isIterable()) {
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
      $object_prop_matches[$name] = $this->findFieldTypeProps(SdcPropJsonSchemaType::from($sub_schema['type']), $sub_required, $sub_schema, FALSE);
    }

    // invert $object_prop_matches to determine different match types
    $inverted = [];
    foreach (array_keys($object_prop_matches) as $object_prop_name) {
      foreach ($object_prop_matches[$object_prop_name] as $field_type_prop_expr) {
        assert($field_type_prop_expr instanceof FieldTypePropExpression || $field_type_prop_expr instanceof ReferenceFieldTypePropExpression);
        // Pick the first match, except:
        if (isset($inverted[$field_type_prop_expr->fieldType][$object_prop_name])) {
          // 1. prefer non-reference matches on the field type.
          if ($inverted[$field_type_prop_expr->fieldType][$object_prop_name] instanceof ReferenceFieldTypePropExpression && $field_type_prop_expr instanceof FieldTypePropExpression) {
            $inverted[$field_type_prop_expr->fieldType][$object_prop_name] = $field_type_prop_expr;
          }
          // 2. prefer a precise match between the SDC object prop name and the
          //    the field type prop name
          elseif ($object_prop_name === $field_type_prop_expr->propName) {
            $inverted[$field_type_prop_expr->fieldType][$object_prop_name] = $field_type_prop_expr;
          }
        }
        else {
          $inverted[$field_type_prop_expr->fieldType][$object_prop_name] = $field_type_prop_expr;
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
    foreach ($matches_complete + $matches_minimal as $field_type => $mapping) {
      $matches[] = new FieldTypeObjectPropsExpression($field_type, $mapping);
    }
    return $matches;
  }

  public function findFieldTypePropsForScalar(SdcPropJsonSchemaType $json_schema_primitive_type, bool $is_required_in_json_schema, ?array $schema, bool $main_property_only) : array {
    if (!$json_schema_primitive_type->isScalar()) {
      throw new \LogicException();
    }

    $candidates = [];

    $field_types = $this->fieldTypePluginManager->getDefinitions();
    foreach (array_keys($field_types) as $field_type) {
      // Rather than instantiating a field type using the field type plugin
      // manager, which assumes a field definition etc exist, bypass that and go
      // directly to the DataType-associated-with-FieldType level.
      // @see \Drupal\Core\Field\FieldTypePluginManager::createInstance()
      $field_item_definition = $this->typedDataManager->createDataDefinition("field_item:$field_type");
      $property_definitions = $this->recurseDataDefinitionInterface($field_item_definition);

      foreach ($property_definitions as $property_name => $property_definition) {
        $is_reference = $this->dataLeafIsReference($property_definition);
        if ($is_reference === NULL) {
          // Neither a reference nor a primitive.
          continue;
        }
        if ($is_reference) {
          // Only follow entity references, as deep as specified.
          // @see ::findFieldTypeStorageCandidates()
          if ($property_definition instanceof DataReferenceDefinitionInterface) {
            $target = $property_definition->getTargetDefinition();
            // Only entity targets are supported.
            if (!$target instanceof EntityDataDefinitionInterface) {
              @trigger_error(sprintf("Unhandled data type class: `%s` Drupal field type contains REFERENCEABLE `%s` data type that is not yet supported", $field_type, $target->getClass()), E_USER_DEPRECATED);
              continue;
            }
            // … but only "simple" entity targets, so not entity revisions or
            // anything else.
            if (!$target instanceof EntityDataDefinition) {
              continue;
            }
            // Finally, avoid interpreting the configurable `entity_reference`
            // field type's default settings as the definitive settings. Only an
            // *instance* of such a field (i.e. attached to an entity type) will
            // have its definitive settings.
            // @see \Drupal\Core\Field\TypedData\FieldItemDataDefinition::createFromDataType()
            // @see \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem::defaultStorageSettings()
            // @todo allow this to be detected automatically by expanding core infrastructure?
            if ($field_item_definition->getFieldDefinition()->getType() === 'entity_reference' && $field_item_definition->getFieldDefinition()->getTargetEntityTypeId() === NULL) {
              continue;
            }
            // When referencing an entity, enrich the EntityDataDefinition with
            // constraints that are imposed by the entity reference field, to
            // narrow the matching.
            // @todo Generalize this so it works for all entity reference field types that do not allow *any* entity of the target entity type to be selected
            if ($field_item instanceof FileItem) {
              $target->addConstraint('FileExtension', $field_item->getUploadValidators()['FileExtension']);
            }
            $referenced_matches = $this->matchEntityProps($target, 0, $json_schema_primitive_type, $is_required_in_json_schema, $schema);
            foreach ($referenced_matches as $referenced_match) {
              $candidates[] = new ReferenceFieldTypePropExpression($field_type, $property_name, $referenced_match->withDelta(0));
            }
          }
        }
        else {
          // For non-reference fields, only allow the main property if that is
          // requested.
          if ($main_property_only && $property_name !== $field_item_definition->getMainPropertyName()) {
            continue;
          }

          assert(is_a($property_definition->getClass(), PrimitiveInterface::class, TRUE));
          $field_item = $this->typedDataManager->createInstance("field_item:$field_type", [
            'name' => NULL,
            'parent' => NULL,
            'data_definition' => $field_item_definition,
          ]);
          assert($field_item instanceof FieldItemInterface);
          $property = $this->recurseTypedDataInterface($field_item)[$property_name];
          if ($this->dataLeafMatchesFormat($property, $json_schema_primitive_type, $is_required_in_json_schema, $schema)) {
            $candidates[] = new FieldTypePropExpression($field_type, $property_name);
          }
        }
      }
    }

    $keyed_by_string = array_combine(array_map(fn ($e) => (string) $e, $candidates), $candidates);
    ksort($keyed_by_string);
    return array_values($keyed_by_string);
  }

  private function matchEntityProps(EntityDataDefinition $entity_data_definition, int $levels_to_recurse, SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, ?array $schema): array {
    return match ($primitive_type->isScalar()) {
      TRUE => $this->matchEntityPropsForScalar($entity_data_definition, $levels_to_recurse, $primitive_type, $is_required_in_json_schema, $schema),
      FALSE => $this->matchEntityPropsForIterable($entity_data_definition, $levels_to_recurse, $primitive_type, $is_required_in_json_schema, $schema),
    };
  }

  private function matchEntityPropsForIterable(EntityDataDefinition $entity_data_definition, int $levels_to_recurse, SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, ?array $schema): array {
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

    // invert $object_prop_matches to determine different match types
    $inverted = [];
    foreach (array_keys($object_prop_matches) as $object_prop_name) {
      foreach ($object_prop_matches[$object_prop_name] as $field_prop_expr) {
        $field_name = match (get_class($field_prop_expr)) {
          FieldPropExpression::class => $field_prop_expr->fieldName,
          ReferenceFieldPropExpression::class => $field_prop_expr->referencer->fieldName,
        };
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
      $matches[] = new FieldObjectPropsExpression($entity_data_definition, $field_name, NULL, $mapping);
    }
    return $matches;
  }

  private function matchEntityPropsForScalar(EntityDataDefinition $entity_data_definition, int $levels_to_recurse, SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, ?array $schema): array {
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
      foreach ($properties as $property) {
        $is_reference = $this->dataLeafIsReference($property);
        if ($is_reference === NULL) {
          // Neither a reference nor a primitive.
          continue;
        }
        $current_entity_field_prop = new FieldPropExpression(
          $entity_data_definition,
          $field_definition->getName(),
          NULL,
          $property->getName(),
        );
        if ($is_reference) {
          if ($levels_to_recurse === 0) {
            continue;
          }
          // Only follow entity references, as deep as specified.
          // @see ::findFieldTypeStorageCandidates()
          if ($property instanceof EntityReference) {
            $target = $property->getTargetDefinition();
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
              assert($field_item instanceof FieldItemInterface);
              $target->addConstraint('FileExtension', $field_item->getUploadValidators()['FileExtension']);
            }
            $referenced_matches = $this->matchEntityProps($target, $levels_to_recurse - 1, $primitive_type, $is_required_in_json_schema, $schema);
            foreach ($referenced_matches as $referenced_match) {
              $matches[] = new ReferenceFieldPropExpression($current_entity_field_prop, $referenced_match);
            }
          }
        }
        else {
          $transformed_property_data_definition = FALSE;
          $entity_constraints = $entity_data_definition->getConstraints();
          if (!empty($entity_constraints)) {
            // Transform an entity-level `FileExtension` constraint to
            // corresponding property-level constraint.
            // @see \Drupal\file\Plugin\Validation\Constraint\FileExtensionConstraintValidator
            if (array_key_exists('FileExtension', $entity_data_definition->getConstraints()) && $property->getParent() instanceof FileUriItem) {
              // Clone to avoid polluting any static caches.
              // @todo verify if truly necessary?
              $transformed_property_data_definition = clone $property->getDataDefinition();
              // @todo JSON schema does not support case-insensitive matching!!!! https://json-schema.org/understanding-json-schema/reference/regular_expressions
              $transformed_property_data_definition->addConstraint('Regex', [
                'pattern' => '\.(' . preg_replace('/ +/', '|', preg_quote($entity_constraints['FileExtension']['extensions'])) . ')$',
              ]);
            }
            // Recreate the property instance if the data definition
            // transformed, to ensure ::dataLeafMatchesFormat() evaluates it
            // using the transformed property data definition.
            if ($transformed_property_data_definition) {
              $property = $this->typedDataManager->create(
                $transformed_property_data_definition,
                $property->getValue(),
                $property->getName(),
                $property->getParent(),
              );
            }
          }
          if ($this->dataLeafMatchesFormat($property, $primitive_type, $is_required_in_json_schema, $schema)) {
            $matches[] = $current_entity_field_prop;
          }
        }
      }
    }
    return $matches;
  }

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

    // Gather all constraints that apply to this field item property.
    $property_level_constraints = $data->getConstraints();
    $complex_data_constraint = array_filter(
      $field_item->getConstraints(),
      fn ($c) => $c instanceof ComplexDataConstraint
    );
    if (!empty($complex_data_constraint)) {
      $field_item_level_constraints_indirect = reset($complex_data_constraint)
        ->properties[$field_property_name] ?? [];
    }
    else {
      $field_item_level_constraints_indirect = [];
    }
    $field_item_level_constraints_direct = $field_item->getConstraints()[$field_property_name] ?? [];
    // @todo Field item-level indirect vs direct constraints should not override each other. Investigate in Drupal core, this seems to be an oversight?
    // Field item-level constraints override property-level constraints.
    // TRICKY: to correctly merge these, these arrays must be rekeyed to allow
    // overriding of default property-level constraints.
    $rekey = function (array $constraints) {
      return array_combine(
        array_map(
          fn (Constraint $c): string => get_class($c),
          $constraints,
        ),
        $constraints
      );
    };
    $constraints = $rekey($field_item_level_constraints_indirect)
      + $rekey($field_item_level_constraints_direct)
      + $rekey($property_level_constraints);

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

  private function dataTypeShapeRequirementMatchesFinalConstraintSet(DataTypeShapeRequirement $required_shape, DataDefinitionInterface $property_data_definition, array $constraints): mixed {
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
        // If no bundles or multiple bundles are specified, inspect the base
        // fields. Otherwise (if a single bundle is specified), inspect all
        // fields.
        if ($dd->getBundles() !== NULL && count($dd->getBundles()) === 1) {
          return $this->entityFieldManager->getFieldDefinitions($entity_type_id, $dd->getBundles()[0]);
        }
        return $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);
      })($dd),
      // Field level.
      $dd instanceof FieldDefinitionInterface => $this->recurseTypedDataInterface($this->typedDataManager->createInstance(
        $dd->getDataType(),
        [
          'name' => $dd->getName(),
          'parent' => NULL,
          'data_definition' => $dd->getItemDefinition(),
        ]
      )),
      $dd instanceof FieldItemDataDefinitionInterface => $dd->getPropertyDefinitions(),
    };
  }

  private function recurseTypedDataInterface(TypedDataInterface $td): array|bool {
    return match (TRUE) {
      $td instanceof FieldItemInterface => $td->getProperties(TRUE),
      // Anything else is not supported: fall back to logging.
      TRUE => function () use ($td) {
        @trigger_error(sprintf("Unhandled TypedData class: `%s`.", get_class($td)), E_USER_DEPRECATED);
      },
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
          $td_or_dd instanceof TypedDataInterface => @trigger_error(sprintf("Unhandled data type class: `%s` Drupal field type `%s` property uses `%s` data type class that is not yet supported", $td_or_dd->getParent()->getDataDefinition()->getFieldDefinition()->getType(), $td_or_dd->getName(), $td_or_dd->getDataDefinition()->getClass()), E_USER_DEPRECATED),
          $td_or_dd instanceof DataDefinitionInterface => @trigger_error(sprintf("Unhandled data type class: `%s` data type class that is not yet supported", $td_or_dd->getClass()), E_USER_DEPRECATED),
        };
      })($td_or_dd),
    };
  }

}
