<?php

declare(strict_types=1);

namespace Drupal\multi_frontend_test;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Trusted #access_callback implementations for tests.
 */
final class AccessCallbacks implements TrustedCallbackInterface {

  /**
   * Denies access to an element.
   */
  public static function deny(array $element): AccessResultInterface {
    return AccessResult::forbidden('Denied by an access callback.');
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['deny'];
  }

}
