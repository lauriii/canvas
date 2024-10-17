<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * @see \Drupal\Core\Validation\Plugin\Validation\Constraint\ValidKeysConstraint
 * @internal
 */
#[Constraint(
  id: 'KeyForEveryThemeRegion',
  label: new TranslatableMarkup('@todo', [], ['context' => 'Validation']),
  type: ['sequence']
)]
class ThemeRegionKeysConstraint extends SymfonyConstraint {

  /**
   * The error message if a key is invalid.
   */
  public string $invalidKeyMessage = '%key is an unknown region.';

  /**
   * The error message if a key is missing.
   */
  public string $missingRequiredKeyMessage = 'Configuration for the region "%key_label" (%key) is missing.';

  /**
   * The machine name of the theme whose regions must be present as keys on a `type: mapping`.
   */
  public string $theme;

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['theme'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOption(): ?string {
    return 'theme';
  }

}
