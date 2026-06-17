<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates non-translatable component inputs match the default translation.
 *
 * In symmetrical translation mode, the component tree structure and
 * non-translatable input keys must be identical across all translations.
 * This constraint validates that non-translatable input keys in non-default
 * translations have the same values as the default translation.
 *
 * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer
 * @see \Drupal\content_translation\Plugin\Validation\Constraint\ContentTranslationSynchronizedFieldsConstraint
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Component tree symmetrical translation', [], ['context' => 'Validation']),
  type: ['entity'],
)]
class ComponentTreeSymmetricalTranslationConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'ComponentTreeSymmetricalTranslation';

  public string $message = "Non-translatable component input key '%key' in component '%uuid' differs from the default translation in the '%langcode' translation.";

}
