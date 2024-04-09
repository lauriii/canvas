<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

require_once 'PropExpressions.php';

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\TypedData\Plugin\DataType\BooleanData;
use Drupal\Core\TypedData\Plugin\DataType\FloatData;
use Drupal\Core\TypedData\Plugin\DataType\IntegerData;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Validation\ConstraintManager;

final class SdcPropToFieldTypePropMatcher {

  public function __construct(
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly ConstraintManager $constraintManager,
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
        $data_type_class = $property_definition->getClass();

        // Any data type that is more complex than a primitive is not accepted.
        // For example: `entity_reference`, `language_reference`, etc.
        // @see \Drupal\Core\Entity\Plugin\DataType\EntityReference
        if (!is_a($data_type_class, PrimitiveInterface::class, TRUE)) {
          // @todo In the future: explore how to allow following entity references?
          continue;
        }

        $field_primitive_types = match (TRUE) {
          is_a($data_type_class, StringData::class, TRUE) => [SdcPropJsonSchemaType::STRING],
          // TRICKY: a SDC prop that accepts number, can accept both an integer and a
          // float, but an SDC prop that accepts integer, can accept only integer.
          is_a($data_type_class, IntegerData::class, TRUE) => [SdcPropJsonSchemaType::INTEGER, SdcPropJsonSchemaType::NUMBER],
          is_a($data_type_class, FloatData::class, TRUE) => [SdcPropJsonSchemaType::NUMBER],
          is_a($data_type_class, BooleanData::class, TRUE) => [SdcPropJsonSchemaType::BOOLEAN],
          // @todo object + array
          TRUE => [],
        };

        // If the primitive type does not match, this is not a candidate.
        if (!in_array($json_schema_primitive_type, $field_primitive_types)) {
          continue;
        }

        // If it is required in SDC's JSON schema, it must be required in Drupal's
        // Typed Data too; otherwise there is a risk of violating SDC's schema.
        if ($is_required_in_json_schema && !$property_definition->isRequired()) {
          continue;
        }
        $candidates[] = new FieldTypePropExpression($field_type, $property_name);
      }
    }

    return $candidates;
  }

  function findFieldTypeFormatCandidates(SdcPropJsonSchemaType $primitive_type, bool $is_required_in_json_schema, array $schema) {
    $storage_candidate_ftps = $this->findFieldTypeStorageCandidates($primitive_type, $is_required_in_json_schema);
    assert(Inspector::assertAll(fn ($e) => $e instanceof FieldTypePropExpression, $storage_candidate_ftps));

    $required_shape = $primitive_type->toDataTypeShapeRequirements($schema);
    return array_filter($storage_candidate_ftps, function ($ftp) use ($required_shape) {
      // One of SdcPropJsonSchemaType, with no additional requirements.
      if ($required_shape === FALSE) {
        return TRUE;
      }
      if ($required_shape->constraint === 'NOT YET SUPPORTED') {
        return FALSE;
      }
      $field_item = $this->typedDataManager->createInstance("field_item:{$ftp->fieldType}", [
        'name' => NULL,
        'parent' => NULL,
        'data_definition' => $this->typedDataManager->createDataDefinition("field_item:{$ftp->fieldType}"),
      ]);

      // Gather all constraints that apply to this field item property.
      $property_level_constraints = $field_item->getProperties()[$ftp->propName]->getConstraints();
      $field_item_level_constraints = $field_item->getConstraints()[$ftp->propName] ?? [];
      // @todo Verify that property-level constraints indeed overrule field item-level constraints.
      $constraints = $property_level_constraints + $field_item_level_constraints;

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
    });
  }


}
