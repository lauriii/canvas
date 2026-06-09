<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Marks methods on Canvas service decorator classes as used.
 *
 * When a Canvas class decorates a Drupal core service via
 * setDecoratedService(), it implements the same interface and its public
 * methods are called by the DIC at runtime. ShipMonk cannot trace these
 * calls because they go through the service container.
 *
 * This provider marks all public interface methods on Canvas decorator classes
 * as used. Private methods called from those public methods will be
 * transitively recognized as used by ShipMonk.
 *
 * Because these decorators are registered conditionally (behind
 * $container->hasDefinition() guards) in CanvasServiceProvider::alter(), there
 * is no static *.services.yml entry to parse — the list must be hardcoded.
 *
 * @see \Drupal\canvas\CanvasServiceProvider::alter()
 */
final class DrupalServiceDecoratorUsageProvider extends ReflectionBasedMemberUsageProvider {

  /**
   * Canvas classes registered as service decorators via setDecoratedService().
   *
   * Must be kept in sync with CanvasServiceProvider::alter().
   *
   * @var list<class-string>
   */
  private const DECORATOR_CLASSES = [
    ComponentTreeFieldSymmetricalTranslationSynchronizer::class,
  ];

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    $declaringClass = $method->getDeclaringClass();

    if (!$method->isPublic() || $method->isConstructor()) {
      return NULL;
    }

    foreach (self::DECORATOR_CLASSES as $decoratorClass) {
      if ($declaringClass->getName() === $decoratorClass) {
        return VirtualUsageData::withNote(
          \sprintf('Called by Drupal DIC via service decorator pattern: %s::%s().', $declaringClass->getShortName(), $method->getName()),
        );
      }
    }

    return NULL;
  }

}
