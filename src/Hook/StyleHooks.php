<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Entity\StyleLibrary;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Theme\Style\StyleDefinition;

/**
 * Registers Canvas-managed style libraries with core's Style API.
 *
 * A deriver is not possible: derivative IDs contain `:`, which core's style
 * ID rule rejects. IDs stay unchanged so files move between a CLI project and
 * a theme.
 *
 * @see https://www.drupal.org/project/drupal/issues/3517033
 */
final class StyleHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_style_alter().
   *
   * Extensions win on collisions: their definition is kept, and the library
   * entry is reported as shadowed by data health.
   *
   * @param array<\Drupal\Core\Theme\Style\StyleDefinitionInterface> $definitions
   *   Core's style definitions, keyed by style ID.
   */
  #[Hook('style_alter')]
  public function styleAlter(array &$definitions): void {
    foreach (StyleLibrary::rawDefinitions($this->configFactory) as $style_id => $definition) {
      $definitions[$style_id] ??= new StyleDefinition($definition);
    }
  }

}
