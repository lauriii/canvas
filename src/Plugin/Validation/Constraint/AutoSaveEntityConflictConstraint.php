<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;

/**
 * Marker constraint for Canvas auto-save conflict violations.
 *
 * Used by the Canvas publish flow to mark an unresolved conflict detected for
 * an auto-save item during entity validation.
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Auto-save entity conflict', [], ['context' => 'Validation']),
  type: ['entity']
)]
class AutoSaveEntityConflictConstraint extends EntityChangedConstraint {

  public const string PLUGIN_ID = 'AutoSaveEntityConflict';

  /**
   * The default violation message.
   *
   * @var string
   */
  public $message = 'Conflict detected';

}
