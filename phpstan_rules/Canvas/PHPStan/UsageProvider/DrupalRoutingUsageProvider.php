<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use Drupal\canvas\Controller\ApiControllerBase;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use Symfony\Component\Yaml\Yaml;

/**
 * Marks Drupal routing-dispatched methods as used.
 *
 * Two false-positive patterns are covered:
 *
 * 1. AccessInterface: access() implementations called by Drupal routing via
 *    the _access routing parameter — ShipMonk cannot trace the routing
 *    dispatch.
 *
 * 2. Route controller methods: methods registered as _controller or
 *    _title_callback in any canvas *.routing.yml file (main module,
 *    submodules, and test modules). ApiControllerBase subclasses are detected
 *    via class hierarchy. All other controller classes are detected by parsing
 *    the routing files at analysis time — no hard-coded class list.
 */
final class DrupalRoutingUsageProvider extends ReflectionBasedMemberUsageProvider {

  /**
   * Controller class names parsed from all canvas *.routing.yml files.
   *
   * Populated lazily by getRouteControllerClassNames(). NULL before first call.
   *
   * @var list<string>|null
   */
  private static ?array $routeControllerClassNames = NULL;

  /**
   * Returns controller class names from all canvas *.routing.yml files.
   *
   * Parses the main module, submodules (modules/*), and test modules
   * (tests/modules/*) routing files to collect every class name that appears
   * as a _controller or _title_callback value. Result is cached statically.
   *
   * @return list<string>
   */
  private static function getRouteControllerClassNames(): array {
    if (self::$routeControllerClassNames !== NULL) {
      return self::$routeControllerClassNames;
    }

    $moduleRoot = dirname(__DIR__, 4);
    $classNames = [];

    $routingFiles = array_merge(
      glob($moduleRoot . '/*.routing.yml') ?: [],
      glob($moduleRoot . '/modules/*/*.routing.yml') ?: [],
      glob($moduleRoot . '/tests/modules/*/*.routing.yml') ?: [],
    );

    foreach ($routingFiles as $routingFile) {
      $routing = Yaml::parseFile($routingFile);
      if (!\is_array($routing)) {
        continue;
      }
      foreach ($routing as $routeData) {
        if (!\is_array($routeData) || !isset($routeData['defaults'])) {
          continue;
        }
        foreach (['_controller', '_title_callback'] as $key) {
          $callback = $routeData['defaults'][$key] ?? NULL;
          if (!\is_string($callback) || !str_contains($callback, '::')) {
            continue;
          }
          $classNames[] = ltrim(explode('::', $callback)[0], '\\');
        }
      }
    }

    return self::$routeControllerClassNames = array_values(array_unique($classNames));
  }

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    $declaringClass = $method->getDeclaringClass();

    // Access checker classes in Drupal\canvas\Access\: access() is invoked by
    // Drupal's routing system via the _access routing parameter.
    // ShipMonk cannot see this dispatch.
    if ($method->getName() === 'access'
      && str_starts_with($declaringClass->getName(), 'Drupal\\canvas\\Access\\')
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Called by Drupal routing system via _access parameter: %s::access().', $declaringClass->getName()),
      );
    }

    // ApiControllerBase subclasses: every public non-magic method is a route
    // callback registered in canvas.routing.yml as _controller or
    // _title_callback.
    if (!$method->isConstructor()
      && $method->isPublic()
      && !str_starts_with($method->getName(), '__')
      && $declaringClass->isSubclassOf(ApiControllerBase::class)
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Registered as _controller or _title_callback in canvas.routing.yml: %s::%s().', $declaringClass->getShortName(), $method->getName()),
      );
    }

    // Controller classes from canvas routing files: any class registered as
    // _controller or _title_callback in a canvas *.routing.yml has all its
    // public non-magic methods treated as route callbacks.
    if (!$method->isConstructor()
      && $method->isPublic()
      && !str_starts_with($method->getName(), '__')
      && \in_array($declaringClass->getName(), self::getRouteControllerClassNames(), TRUE)
    ) {
      return VirtualUsageData::withNote(
        \sprintf('Registered as _controller or _title_callback in a canvas routing file: %s::%s().', $declaringClass->getShortName(), $method->getName()),
      );
    }

    return NULL;
  }

}
