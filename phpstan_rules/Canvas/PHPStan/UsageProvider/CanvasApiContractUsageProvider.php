<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks canvas interface and abstract class API contract members as used.
 *
 * Three false-positive patterns are covered:
 *
 * 1. Canvas interface methods define API contracts. ShipMonk records calls
 *    against the concrete type when PHPStan narrows via assertInstanceOf(),
 *    leaving the interface method with zero apparent callers. All methods on
 *    canvas interfaces are treated as used — if an interface were truly dead,
 *    the interface class itself would be flagged.
 *
 * 2. Concrete implementations of canvas interface methods: VirtualUsageData
 *    for interface methods does not propagate to implementing classes. Any
 *    class implementing a Drupal\canvas\ interface method must also be marked
 *    used independently.
 *
 * 3. Canvas abstract class methods declare API contracts. VirtualUsageData on
 *    an abstract method does not propagate to concrete overrides, so concrete
 *    implementations are also checked here.
 *
 * Additionally, canvas interface constants are marked used:
 *
 * 4. Canvas interface constants define API contracts. VirtualUsageData does
 *    not propagate from the interface constant to classes that inherit it.
 */
final class CanvasApiContractUsageProvider extends ReflectionBasedMemberUsageProvider {

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    $declaringClass = $method->getDeclaringClass();

    // Canvas interface methods define API contracts.
    if ($declaringClass->isInterface()
      && str_starts_with($declaringClass->getName(), 'Drupal\\canvas\\')
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Defines canvas API contract: %s::%s().', $declaringClass->getShortName(), $method->getName()),
      );
    }

    // Concrete implementations of canvas interface methods.
    foreach ($declaringClass->getInterfaces() as $interface) {
      if (str_starts_with($interface->getName(), 'Drupal\\canvas\\')
        && $interface->hasMethod($method->getName())
      ) {
        return VirtualUsageData::withNote(
          \sprintf('Implements canvas API contract %s::%s().', $interface->getShortName(), $method->getName()),
        );
      }
    }

    // Abstract canvas class methods define API contracts.
    if ($method->isAbstract()
      && str_starts_with($declaringClass->getName(), 'Drupal\\canvas\\')
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Defines canvas abstract class API contract: %s::%s().', $declaringClass->getShortName(), $method->getName()),
      );
    }

    // Concrete overrides of canvas abstract class methods.
    $parent = $declaringClass->getParentClass();
    while ($parent !== FALSE) {
      if (str_starts_with($parent->getName(), 'Drupal\\canvas\\')
        && $parent->hasMethod($method->getName())
        && $parent->getMethod($method->getName())->isAbstract()
      ) {
        return VirtualUsageData::withNote(
          \sprintf('Implements canvas abstract class API contract %s::%s().', $parent->getShortName(), $method->getName()),
        );
      }
      $parent = $parent->getParentClass();
    }

    return NULL;
  }

  protected function shouldMarkConstantAsUsed(\ReflectionClassConstant $constant): ?VirtualUsageData {
    $declaringClass = $constant->getDeclaringClass();

    // Canvas interface constants define API contracts.
    if ($declaringClass->isInterface()
      && str_starts_with($declaringClass->getName(), 'Drupal\\canvas\\')
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Defines canvas API contract: %s::%s.', $declaringClass->getShortName(), $constant->getName()),
      );
    }

    return NULL;
  }

}
