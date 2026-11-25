<?php

declare(strict_types=1);

namespace Drupal\canvas\Validation;

use Drupal\Core\Plugin\Component;
use Drupal\Core\Theme\Component\ComponentValidator;

/**
 * Validates an optional prop emptied to `''` as if it were unset.
 *
 * Editors empty an optional prop by clearing its value, which components then
 * receive as `''`. That value may fail the prop's schema, such as
 * `format: uri`, so the developer-only validation of props while rendering
 * would throw. The component still receives `''`, so it does not fall back to a
 * default value.
 *
 * @todo Remove this class once core treats optional `''` props as unset or allows skipping prop validation: https://www.drupal.org/project/drupal/issues/3639140
 *
 * @see https://www.drupal.org/node/3592347
 * @see \Drupal\Core\Template\ComponentsTwigExtension::validateProps()
 */
final class EmptyOptionalPropsComponentValidator extends ComponentValidator {

  /**
   * {@inheritdoc}
   */
  public function validateProps(array $context, Component $component): bool {
    $required = $component->metadata->schema['required'] ?? [];
    $context = \array_filter(
      $context,
      fn ($value, $prop) => $value !== '' || \in_array($prop, $required, TRUE),
      ARRAY_FILTER_USE_BOTH,
    );
    return parent::validateProps($context, $component);
  }

}
