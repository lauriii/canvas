<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates an exposed slot of a content template.
 *
 * Exposed slots are only allowed in templates of one entity type (`node`) and
 * one view mode (`full`). Both are constraint options, set in the config
 * schema.
 *
 * @see \Drupal\canvas\Plugin\Validation\Constraint\ValidExposedSlotConstraintValidator
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates an exposed slot', [], ['context' => 'Validation']),
)]
final class ValidExposedSlotConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'ValidExposedSlot';

  /**
   * The only view mode whose content templates can expose slots.
   */
  public const string EXPOSED_SLOTS_VIEW_MODE = 'full';

  /**
   * The only entity type whose content templates can expose slots.
   */
  public const string EXPOSED_SLOTS_ENTITY_TYPE_ID = 'node';

  #[HasNamedArguments]
  public function __construct(
    // The view mode in which the exposed slots will be used.
    public string $viewMode = self::EXPOSED_SLOTS_VIEW_MODE,
    // The entity type whose content templates the exposed slots are used in.
    public string $entityTypeId = self::EXPOSED_SLOTS_ENTITY_TYPE_ID,
    // The violation message when the component instance does not exist in the
    // tree.
    public string $unknownComponentMessage = 'The component %id does not exist in the tree.',
    // The violation message when no component tree field backs the exposed
    // slot.
    public string $missingFieldMessage = 'The exposed slot %field must be backed by a component tree field of that machine name on the bundle.',
    // The violation message when a component does not have a slot.
    public string $undefinedSlotMessage = 'The component %id does not have a %slot slot.',
    // The violation message when the slot's component is inside another exposed
    // slot.
    public string $nestedSlotMessage = 'The %slot slot of component %id cannot be exposed because that component is inside another exposed slot.',
    // The violation message when the slot is already exposed under another
    // name.
    public string $duplicateTargetMessage = 'The %slot slot of component %id is already exposed as %alias.',
    // The violation message when the exposed slot is defined for a disallowed
    // view mode.
    public string $viewModeMismatchMessage = 'Exposed slots are only allowed in the %mode view mode.',
    // The violation message when the exposed slot is defined for a disallowed
    // entity type.
    public string $entityTypeMismatchMessage = 'Exposed slots are only allowed for the %type entity type.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
