<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Thrown when the base can't be defined, the same way until it changes.
 *
 * Every render relies on the providers' modules, so one that's not valid
 * UTF-8 leaves no base. It carries what the base depends on, so a CSR
 * fallback for it can be cached as a render, until that changes.
 *
 * @see \Drupal\canvas_ssr\JsModules\BaseModules::getDefinition()
 */
final class UnusableBaseException extends \UnexpectedValueException implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  /**
   * Constructs an UnusableBaseException.
   *
   * @param string $message
   *   Why.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the base depends on.
   */
  public function __construct(string $message, CacheableMetadata $cacheability) {
    parent::__construct($message);
    $this->setCacheability($cacheability);
  }

}
