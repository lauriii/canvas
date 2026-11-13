<?php

declare(strict_types=1);

namespace Drupal\canvas\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an attribute for a source of options for component props.
 *
 * @see \Drupal\canvas\OptionsSource\OptionsSourceInterface
 * @see \Drupal\canvas\OptionsSource\OptionsSourceManager
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class OptionsSource extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?string $deriver = NULL,
  ) {}

}
