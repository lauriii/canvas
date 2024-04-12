<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

require_once 'PropExpressions.php';

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceTargetDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\Plugin\DataType\BooleanData;
use Drupal\Core\TypedData\Plugin\DataType\FloatData;
use Drupal\Core\TypedData\Plugin\DataType\IntegerData;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\Core\Validation\Plugin\Validation\Constraint\ComplexDataConstraint;

final class SdcPropToFieldTypePropMatcher {

  public function __construct(
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly ConstraintManager $constraintManager,
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
          // If a target specifies no bundles or multiple bundles,
          // consider the base fields. Otherwise (if a single bundle is
          // specified), consider all fields.
          if ($target->getBundles() !== NULL && count($target->getBundles()) === 1) {
            $available_field_definitions = $this->entityFieldManager->getFieldDefinitions($target->getEntityTypeId(), $target->getBundles()[0]);
          }
          else {
            $available_field_definitions = $this->entityFieldManager->getBaseFieldDefinitions($target->getEntityTypeId());
          }
          foreach ($available_field_definitions as $referenceable_field_definition) {
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

    return array_values(array_filter($storage_candidate_ftps, function ($ftp) use ($required_shape) {
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
      return $this->fieldItemPropertyMatchesShape($field_item, $field_property_expression, $required_shape);
    }));
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

  // The data definition alone does not define the final shape, each field item class can implement a ::getConstraints() method.
  // For the final shape, we need the TypedData object.
  // @see \Drupal\Core\TypedData\TypedDataInterface::getConstraints()
  private function fieldItemPropertyMatchesShape(FieldItemInterface $field_item, FieldTypePropExpression|FieldPropExpression $ftp, DataTypeShapeRequirements $required_shape): bool {
    // Gather all constraints that apply to this field item property.
    $property_level_constraints = $field_item->getProperties(TRUE)[$ftp->propName]->getConstraints();
    $complex_data_constraint = array_filter(
      $field_item->getConstraints(),
      fn ($c) => $c instanceof ComplexDataConstraint
    );
    if (!empty($complex_data_constraint)) {
      $field_item_level_constraints_indirect = reset($complex_data_constraint)
        ->properties[$ftp->propName] ?? [];
    }
    else {
      $field_item_level_constraints_indirect = [];
    }
    $field_item_level_constraints_direct = $field_item->getConstraints()[$ftp->propName] ?? [];
    // @todo Verify that property-level constraints indeed overrule field item-level constraints.
    $constraints = $property_level_constraints + $field_item_level_constraints_indirect + $field_item_level_constraints_direct;

    // Is the data shape requirement met?
    // 1. Constraint.
    $constraint_found = in_array(
      $this->constraintManager->create($required_shape->constraint, $required_shape->constraintOptions),
      $constraints
    );
    // 2. Optionally: the interface.
    $interface_found = $required_shape->interface === NULL
      || is_a($field_item->get($ftp->propName)->getDataDefinition()->getClass(), $required_shape->interface, TRUE);
    return $constraint_found && $interface_found;
  }

}
