<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates an exposed slot', [], ['context' => 'Validation']),
)]
final class ValidExposedSlotConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'ValidExposedSlot';

  /**
   * The view mode in which the exposed slots will be used.
   *
   * @var string
   */
  public string $viewMode = 'full';

  /**
   * Whether the exposed slot must be empty (no template default content).
   *
   * Content templates allow template content in an exposed slot to become that
   * slot's per-entity-overridable default, so this is FALSE for them. Consumers
   * that require an empty slot (page variants' content slot) set it to TRUE.
   *
   * @var bool
   */
  public bool $requireEmpty = FALSE;

  public string $unknownComponentMessage = 'The component %id does not exist in the tree.';

  public string $slotNotEmptyMessage = 'The %slot slot must be empty.';

  public string $duplicateTargetMessage = 'The %slot slot is already exposed by another exposed slot.';

  public string $undefinedSlotMessage = 'The component %id does not have a %slot slot.';

  public string $viewModeMismatchMessage = 'Exposed slots are only allowed in the %mode view mode.';

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): string {
    return 'viewMode';
  }

}
