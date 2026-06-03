<?php

declare(strict_types=1);

namespace Drupal\canvas\Attribute;

/**
 * Opts a Component-updating CanvasConfigUpdater method out of preSave wiring.
 *
 * Most CanvasConfigUpdater methods that take a Component must run on every save
 * (see \Drupal\canvas\Attribute\ComponentPreSaveUpdate). A method that
 * deliberately must NOT — e.g. a one-time migration that is unsafe to re-run,
 * or one whose effect is irrelevant on save — carries this attribute instead,
 * so the decision is explicit rather than forgotten.
 *
 * \Canvas\PHPStan\Rules\ComponentConfigUpdaterMustDeclarePreSaveIntentRule
 * requires every such method to carry exactly one of the two attributes.
 *
 * @see \Drupal\canvas\Attribute\ComponentPreSaveUpdate
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class NotAppliedOnComponentPreSave {

  /**
   * @param string $reason
   *   Why this Component-updating method must not run from preSave().
   */
  public function __construct(
    public readonly string $reason,
  ) {}

}
