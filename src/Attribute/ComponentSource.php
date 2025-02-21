<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an attribute for a component source.
 *
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceInterface
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceManager
 * @see \Drupal\experience_builder\ComponentSource\ComponentSourceBase
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ComponentSource extends Plugin {

  /**
   * @param string $id
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   * @param class-string|null $deriver
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly bool $supportsImplicitInputs,
    public readonly ?string $deriver = NULL,
  ) {
  }

}
