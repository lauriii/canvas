<?php

declare(strict_types=1);

namespace Drupal\Core\Theme\DesignToken;

use Drupal\Core\Plugin\ConfigurablePluginBase;

/**
 * Stand-in for core's design token value base class, on core < 11.5.
 *
 * PHPStan cannot ignore "extends unknown class", so analysis on the minimum
 * core version needs this class to exist.
 *
 * @see \Drupal\canvas\DesignToken\ColorValue
 * @todo Remove once Canvas requires Drupal 11.5.
 */
abstract class DesignTokenValuePluginBase extends ConfigurablePluginBase {

  public static function prepareConfiguration(mixed $configuration): array {
    return \is_array($configuration) ? $configuration : ['value' => $configuration];
  }

}
