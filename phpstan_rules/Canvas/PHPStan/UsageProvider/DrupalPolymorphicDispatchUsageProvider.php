<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use Drupal\media_library\MediaLibraryFieldWidgetOpener;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks canvas methods used via polymorphic dispatch of vendor/Drupal bases.
 *
 * When a canvas class overrides a method defined on a vendor or Drupal base
 * class, and that base class calls the method via $this->method() (polymorphic
 * dispatch), ShipMonk cannot trace the call. This provider maps method names
 * to base classes so that any canvas subclass overriding the method is marked
 * used.
 *
 * Covered patterns:
 *
 * 1. MediaLibraryCanvasPropOpener::checkAccess(): overrides
 *    MediaLibraryFieldWidgetOpener::checkAccess() called via polymorphic
 *    dispatch by the media library opener system.
 */
final class DrupalPolymorphicDispatchUsageProvider extends ReflectionBasedMemberUsageProvider {

  /**
   * Maps method name → base class(es) whose subclasses receive the dispatch.
   *
   * @var array<string, list<class-string>>
   */
  private const METHOD_TO_BASE_CLASSES = [
    // MediaLibraryFieldWidgetOpener::checkAccess() is overridden by
    // MediaLibraryCanvasPropOpener; the media library opener system calls it
    // via $this->checkAccess() polymorphic dispatch.
    'checkAccess' => [MediaLibraryFieldWidgetOpener::class],
  ];

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    $baseClasses = self::METHOD_TO_BASE_CLASSES[$method->getName()] ?? NULL;
    if ($baseClasses !== NULL) {
      $declaringClass = $method->getDeclaringClass();
      foreach ($baseClasses as $baseClass) {
        if ($declaringClass->isSubclassOf($baseClass)) {
          return VirtualUsageData::withNote(
            \sprintf('Called by %s via $this->%s() polymorphic dispatch.', $baseClass, $method->getName()),
          );
        }
      }
    }

    return NULL;
  }

}
