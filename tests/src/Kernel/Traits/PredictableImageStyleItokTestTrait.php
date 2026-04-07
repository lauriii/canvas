<?php

declare(strict_types=1);

// cspell:ignore oauth

namespace Drupal\Tests\canvas\Kernel\Traits;

use Drupal\Core\Site\Settings;

/**
 * Ensures the image style `itok` is predictable for kernel tests.
 */
trait PredictableImageStyleItokTestTrait {

  /**
   * Ensures the image style `itok` is predictable for kernel tests.
   */
  protected function setupPredictableItok(): void {
    $this->container->get('state')->set('system.private_key', 'dynamic_image_style_private_key');

    $settings_class = new \ReflectionClass(Settings::class);
    $instance_property = $settings_class->getProperty('instance');
    $settings = new Settings([
      'hash_salt' => 'dynamic_image_style_hash_salt_large_enough_for_simple_oauth',
    ]);
    $instance_property->setValue(NULL, $settings);
  }

}
