<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

require_once 'PropExpressions.php';

use Drupal\Component\Assertion\Inspector;
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
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceInterface;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\Plugin\DataType\BooleanData;
use Drupal\Core\TypedData\Plugin\DataType\FloatData;
use Drupal\Core\TypedData\Plugin\DataType\IntegerData;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint;
use Symfony\Component\Validator\Constraint;

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
  /**
   * @param \Drupal\experience_builder\SdcPropJsonSchemaType $json_schema_primitive_type
   * @param bool $is_required_in_json_schema
   *
   * @return \Drupal\experience_builder\FieldTypePropExpression[]
   *   A list of field type props.
   */
  function findFieldTypeStorageCandidates(SdcPropJsonSchemaType $json_schema_primitive_type, bool $is_required_in_json_schema) : array {
    $candidates = [];

    $field_types = $this->fieldTypePluginManager->getDefinitions();
    foreach (array_keys($field_types) as $field_type) {
      // Rather than instantiating a field type using the field type plugin
      // manager, which assumes a field definition etc exist, bypass that and go
      // directly to the DataType-associated-with-FieldType level.
      // @see \Drupal\Core\Field\FieldTypePluginManager::createInstance()
      $field_item_definition = $this->typedDataManager->createDataDefinition("field_item:$field_type");
      $property_definitions = $field_item_definition->getPropertyDefinitions();

      foreach ($property_definitions as $property_name => $property_definition) {
        // References must be followed.
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
          if ($field_item_definition->getFieldDefinition()->getType() ===  'entity_reference' && $field_item_definition->getFieldDefinition()->getTargetEntityTypeId() === NULL) {
            continue;
          }
          foreach ($this->recurseDataDefinitionInterface($target) as $referenceable_field_definition) {
            // Only support single-cardinality indirect
            if ($referenceable_field_definition->getCardinality() !== 1) {
              continue;
            }
            $id = $referenceable_field_definition->getItemDefinition();
            $field_item = $this->typedDataManager->createInstance($id->getDataType(), [
              'name' => $referenceable_field_definition->getName(),
              'parent' => NULL,
              'data_definition' => $id,
            ]);
            assert($field_item instanceof FieldItemInterface);
            $property_definitions_sub = $id->getPropertyDefinitions();
            foreach ($property_definitions_sub as $sub_prop_name => $sub_prop_definition) {
              if (is_a($sub_prop_definition->getClass(), PrimitiveInterface::class, TRUE)) {
                if ($this->dataDefinitionMatchesPrimitiveType($sub_prop_definition, $json_schema_primitive_type, $is_required_in_json_schema)) {
                  $candidates[] = new ReferenceFieldTypePropExpression($field_type, $property_name, new FieldPropExpression($target, $referenceable_field_definition->getName(), 0, $sub_prop_name));
                }
              }
            }
          }
        }
        // Primitives may match.
        elseif (is_a($property_definition->getClass(), PrimitiveInterface::class, TRUE)) {
          if ($this->dataDefinitionMatchesPrimitiveType($property_definition, $json_schema_primitive_type, $is_required_in_json_schema)) {
            $candidates[] = new FieldTypePropExpression($field_type, $property_name);
          }
        }
        // Anything else cannot be handled and merits logging.
        else {
          @trigger_error(sprintf("Unhandled data type class: `%s` Drupal field type `%s` property uses `%s` data type class that is not yet supported", $field_type, $property_name, $property_definition->getClass()), E_USER_DEPRECATED);
        }
      }
    }

    return $candidates;
  }

  function findFieldTypeFormatCandidates(SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, array $schema) {
    $storage_candidate_ftps = $this->findFieldTypeStorageCandidates($primitive_type, $is_required_in_json_schema);
    assert(Inspector::assertAll(fn ($e) => $e instanceof FieldTypePropExpression, $storage_candidate_ftps));

    $required_shape = $primitive_type->toDataTypeShapeRequirements($schema);
    // One of SdcPropJsonSchemaType, with no additional requirements.
    if ($required_shape === FALSE) {
      return $storage_candidate_ftps;
    }
    if ($required_shape->constraint === 'NOT YET SUPPORTED') {
      @trigger_error(sprintf("NOT YET SUPPORTED: a `%s` Drupal field data type that matches the JSON schema %s.", $primitive_type->value, json_encode($schema)), E_USER_DEPRECATED);
      return [];
    }

    return array_values(array_filter($storage_candidate_ftps, function ($ftp) use ($primitive_type, $required_shape, $is_required_in_json_schema, $schema) {
      if ($ftp instanceof ReferenceFieldTypePropExpression) {
        $field_property_expression = $ftp->referenced;
        if (!$field_property_expression instanceof FieldPropExpression) {
          throw new \LogicException('Multiple levels of indirection are not (yet) supported.');
        }
        // load the field item for the entiy type
        $bundle = $ftp->referenced->entityType->getBundles();
        if (is_array($bundle)) {
          if (count($bundle) > 1) {
            throw new \LogicException('This should not be possible due to earlier validation');
          }
          $bundle = reset($bundle);
        }
        assert($ftp->referenced->entityType->getBundles() === NULL || count($ftp->referenced->entityType->getBundles()) === 1);
        $field_item_data_definition = \Drupal::service(EntityFieldManagerInterface::class)
          ->getFieldDefinitions($ftp->referenced->entityType->getEntityTypeId(), $bundle)[$ftp->referenced->fieldName]
          ->getItemDefinition();
      }
      else {
        $field_property_expression = $ftp;
        $field_item_data_definition = $this->typedDataManager->createDataDefinition("field_item:{$ftp->fieldType}");
      }
      $field_item = $this->typedDataManager->createInstance("field_item:{$ftp->fieldType}", [
        'name' => $ftp instanceof ReferenceFieldTypePropExpression
          // Use the referenced entity type field name.
          ? $ftp->referenced->fieldName
          // Otherwise: this is an unnamed, uninstantiated field type.
          : NULL,
        'parent' => NULL,
        'data_definition' => $field_item_data_definition,
      ]);
      assert($field_item instanceof FieldItemInterface);
      $property = $this->recurseTypedDataInterface($field_item)[$field_property_expression->propName];
      return $this->dataLeafMatchesFormat($property, $primitive_type, $is_required_in_json_schema, $schema);
    }));
  }

  function matchEntityProps(EntityDataDefinition $entity_data_definition, int $levels_to_recurse, SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, array $schema): array {
    $matches = [];
    $field_definitions = $this->recurseDataDefinitionInterface($entity_data_definition);
    foreach ($field_definitions as $field_definition) {
      assert($field_definition instanceof FieldDefinitionInterface);
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
            $referenced_matches = $this->matchEntityProps($property->getTargetDefinition(), $levels_to_recurse - 1, $primitive_type, $is_required_in_json_schema, $schema);
            foreach ($referenced_matches as $referenced_match) {
              $matches[] = new ReferenceFieldPropExpression($current_entity_field_prop, $referenced_match);
            }
          }
        }
        else {
          if ($this->dataLeafMatchesFormat($property, $primitive_type, $is_required_in_json_schema, $schema)) {
            $matches[] = $current_entity_field_prop;
          }
        }
      }
    }
    return $matches;
  }

  function findFieldInstanceFormatMatches(SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, array $schema): array {
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
    return $matches;
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

  private function dataLeafMatchesFormat(TypedDataInterface $data, SdcPropJsonSchemaType $json_schema_primitive_type, bool $is_required_in_json_schema, array $schema): bool {
    if (!$data->getParent()) {
      throw new \LogicException('must be a property with a field item as context for format checking');
    }
    $property_data_definition = $data->getDataDefinition();
    if (!$this->dataDefinitionMatchesPrimitiveType($property_data_definition, $json_schema_primitive_type, $is_required_in_json_schema)) {
      return FALSE;
    }
    $required_shape = $json_schema_primitive_type->toDataTypeShapeRequirements($schema);

    // One of SdcPropJsonSchemaType, with no additional requirements.
    if ($required_shape === FALSE) {
      return TRUE;
    }
    if ($required_shape->constraint === 'NOT YET SUPPORTED') {
      @trigger_error(sprintf("NOT YET SUPPORTED: a `%s` Drupal field data type that matches the JSON schema %s.", $json_schema_primitive_type->value, json_encode($schema)), E_USER_DEPRECATED);
      return FALSE;
    }

    $field_item = $data->getParent();
    assert($field_item instanceof FieldItemInterface);
    $field_property_name = $data->getName();

    // Gather all constraints that apply to this field item property.
    $property_level_constraints = $field_item->getProperties(TRUE)[$field_property_name]->getConstraints();
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
        // If no bundles or multiple bundles are specified, inspect the base fields.
        // Otherwise (if a single bundle is specified), inspect all fields.
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
      TRUE => function () {
        @trigger_error(sprintf("Unhandled data type class: `%s` Drupal field type contains `%s` data type that is not yet supported", 'tbd', $dd->getClass()), E_USER_DEPRECATED);
      },
    };
  }

  private function dataLeafIsReference(TypedDataInterface $td): ?bool {
    if (!$td->getParent() instanceof FieldItemInterface) {
      throw new \LogicException(__METHOD__ . ' was given a non-leaf.');
    }
    return match(TRUE) {
      $td instanceof DataReferenceInterface => TRUE,
      $td instanceof PrimitiveInterface => FALSE,
      TRUE => (function ($td) {
        @trigger_error(sprintf("Unhandled data type class: `%s` Drupal field type `%s` property uses `%s` data type class that is not yet supported", $td->getParent()->getDataDefinition()->getFieldDefinition()->getType(), $td->getName(), $td->getDataDefinition()->getClass()), E_USER_DEPRECATED);
        return NULL;
      })($td),
    };
  }

}
