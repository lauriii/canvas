<?php

declare(strict_types = 1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Checks a component ID consists of specific parts found in the parent mapping.
 *
 * Provides the ability to ignore certain source plugin IDs.
 */
#[Constraint(
  id: "ComponentIdParts",
  label: new TranslatableMarkup("Component ID consists of specific parts", [], ['context' => 'Validation'])
)]
class ComponentIdPartsConstraint extends StringPartsConstraint {

  /**
   * Source plugins to ignore.
   *
   * Make use of this in the case where e.g. a fallback or broken plugin is
   * allowed to bypass the validation rule.
   */
  public array $ignoreSources = [];

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return \array_merge(parent::getRequiredOptions(), ['ignoreSources']);
  }

}
