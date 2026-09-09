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

  public string $unknownComponentMessage = 'The component %id does not exist in the tree.';

  public string $missingFieldMessage = 'The exposed slot %field must be backed by a component tree field of that machine name on the bundle.';

  public string $undefinedSlotMessage = 'The component %id does not have a %slot slot.';

  public string $nestedSlotMessage = 'The %slot slot of component %id cannot be exposed because that component is inside another exposed slot.';

  public string $duplicateTargetMessage = 'The %slot slot of component %id is already exposed as %alias.';

  public string $viewModeMismatchMessage = 'Exposed slots are only allowed in the %mode view mode.';

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): string {
    return 'viewMode';
  }

}
