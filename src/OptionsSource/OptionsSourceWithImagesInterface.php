<?php

declare(strict_types=1);

namespace Drupal\canvas\OptionsSource;

/**
 * Provides the options of a component prop from site data, with their images.
 *
 * The form shows the images when the prop offers its options as cards, with
 * `x-canvas-options-display: cards`.
 *
 * @see \Drupal\canvas\OptionsSource\OptionsSourceInterface::DISPLAY_KEY
 * @see ui/src/components/form/components/CardSelect.tsx
 */
interface OptionsSourceWithImagesInterface extends OptionsSourceInterface {

  /**
   * Gets the images of options for the current user.
   *
   * Runs, uncached, each time a component instance form with cards is built.
   *
   * @param list<string|int> $values
   *   Option values: keys of ::getOptions(), and stored values that are no
   *   longer an option.
   *
   * @return array<string|int, string>
   *   Image URLs, keyed by option value. The form shows them about 100 pixels
   *   wide. Leave out options without an image, and options whose image the
   *   current user may not see: their cards show a placeholder.
   */
  public function getOptionImages(array $values): array;

}
