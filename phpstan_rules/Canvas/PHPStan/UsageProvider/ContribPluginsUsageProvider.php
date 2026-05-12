<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks canvas methods called by Drupal contrib plugin systems.
 *
 * Contrib modules that define plugin systems dispatch plugin methods in ways
 * ShipMonk cannot trace. This provider covers canvas classes that implement
 * contrib plugin interfaces.
 *
 * Covered patterns:
 *
 * 1. Search API Processor plugins: canvas classes in
 *    Drupal\canvas\Plugin\search_api\ implement ProcessorInterface;
 *    search_api calls methods like supportsIndex() and
 *    getPropertyDefinitions() via plugin dispatch.
 */
final class ContribPluginsUsageProvider extends ReflectionBasedMemberUsageProvider {

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    if (!$method->isConstructor()
      && $method->isPublic()
      && !str_starts_with($method->getName(), '__')
      && str_starts_with($method->getDeclaringClass()->getName(), 'Drupal\\canvas\\Plugin\\search_api\\')
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Called by search_api plugin dispatch: %s::%s().', $method->getDeclaringClass()->getShortName(), $method->getName()),
      );
    }

    return NULL;
  }

}
