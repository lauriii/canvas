<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks style guide drafts: staged `canvasDesignTokenValue` actions.
 *
 * Runs on every auto-save, and again in publish's validation pass before
 * anything is saved.
 *
 * @see \Drupal\canvas\Plugin\ConfigAction\DesignTokenValue
 */
#[Constraint(
  id: 'DesignTokenStagedValue',
  label: new TranslatableMarkup('Valid style guide draft', [], ['context' => 'Validation']),
)]
final class DesignTokenStagedValueConstraint extends SymfonyConstraint {

  public string $targetMessage = 'Style guide drafts must target @target.';
  public string $idMessage = 'The ID of a style guide draft must be @id.';
  public string $disabledMessage = 'Turn on the style guide to publish this.';
  public string $notListedMessage = 'This design value is not defined by the theme, or cannot be saved.';
  public string $shapeMessage = 'Expected exactly path, type, value and base.';
  public string $typeMessage = 'The theme defines this design value as @type.';
  public string $changedElsewhereMessage = 'Changed elsewhere since you edited it.';

}
