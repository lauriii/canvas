<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Traits;

// cspell:ignore oauth

use Drupal\Component\Datetime\Time;
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

    // The `itok` takes the source URI as input. Managed file fields default
    // their `file_directory` to a date-tokenized value (e.g. `[year]-[month]`).
    $this->container->set('datetime.time', new PredictableImageStyleItokTime());
  }

}

/**
 * Fixed time service so date-tokenized file directories are deterministic.
 */
final class PredictableImageStyleItokTime extends Time {

  // 2026-04-23 00:00:00 UTC. Yields `2026-04` for the file_directory's token.
  public const int FIXED_TIMESTAMP = 1776902400;

  public function getRequestTime(): int {
    return self::FIXED_TIMESTAMP;
  }

}
