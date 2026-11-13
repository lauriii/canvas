<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validation constraint for prop field definitions: scalar XOR composite.
 *
 * A prop field definition is either scalar (a single field type + widget +
 * expression + default value) or composite (a custom object prop aka "group":
 * one scalar definition per sub-property under `sub_definitions`). The scalar
 * keys are optional in config schema only to allow the composite variant; this
 * constraint restores their requiredness for scalar definitions.
 *
 * @see `type: canvas.json_schema_props`
 * @see docs/adr/0021-object-props-in-code-components.md
 */
#[Constraint(
  id: 'ValidPropFieldDefinition',
  type: 'mapping',
)]
final class ValidPropFieldDefinitionConstraint extends SymfonyConstraint {

  public string $missingScalarKeyMessage = "'%key' is a required key for prop field definitions without 'sub_definitions'.";

  public string $extraneousCompositeKeyMessage = "'%key' is an unknown key for prop field definitions with 'sub_definitions': composite prop field definitions have no single field type.";

  public string $emptySubDefinitionsMessage = "'sub_definitions' must contain at least one sub-property definition.";

}
