<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use Drupal\Core\Hook\Attribute\Hook;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks Drupal hook methods (and their constructors) as used.
 *
 * Methods annotated with #[Hook('hook_name')] are invoked by Drupal's hook
 * system via reflection — PHPStan cannot detect these calls statically.
 * Their classes are DIC services, so constructors are also called by the DIC.
 */
final class DrupalHookUsageProvider extends ReflectionBasedMemberUsageProvider {

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    if ($method->getAttributes(Hook::class) !== []) {
      return VirtualUsageData::withNote(
        \sprintf('Invoked by Drupal hook system via #[Hook(\'%s\')]', $method->getName()),
      );
    }

    // Constructors of hook classes are called by Drupal's DIC.
    if ($method->getName() === '__construct' && $this->classHasHookMethods($method)) {
      return VirtualUsageData::withNote('Called by Drupal DIC to instantiate hook service.');
    }

    return NULL;
  }

  private function classHasHookMethods(\ReflectionMethod $method): bool {
    foreach ($method->getDeclaringClass()->getMethods() as $classMethod) {
      if ($classMethod->getAttributes(Hook::class) !== []) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
