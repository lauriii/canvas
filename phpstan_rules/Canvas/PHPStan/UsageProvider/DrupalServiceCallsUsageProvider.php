<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use Symfony\Component\Yaml\Yaml;

/**
 * Marks methods called by the Drupal DIC via calls: in *.services.yml.
 *
 * Drupal's service container supports setter injection via the calls:
 * directive in *.services.yml. These methods are called at service
 * initialization time but ShipMonk cannot trace the DIC dispatch. This
 * provider parses all canvas *.services.yml files to discover which class
 * methods are called this way — no hard-coded list is needed.
 */
final class DrupalServiceCallsUsageProvider extends ReflectionBasedMemberUsageProvider {

  /**
   * Methods called via calls: in services.yml, keyed by class name.
   *
   * Populated lazily by getServiceCallMethodsPerClass(). NULL before first
   * call.
   *
   * @var array<string, list<string>>|null
   */
  private static ?array $serviceCallMethodsPerClass = NULL;

  /**
   * Returns methods called via calls: in canvas *.services.yml files.
   *
   * Parses all canvas *.services.yml files (main module and submodules) and
   * collects every method named in a calls: entry. Result is cached
   * statically.
   *
   * @return array<string, list<string>>
   */
  private static function getServiceCallMethodsPerClass(): array {
    if (self::$serviceCallMethodsPerClass !== NULL) {
      return self::$serviceCallMethodsPerClass;
    }

    $moduleRoot = dirname(__DIR__, 4);
    $result = [];

    $servicesFiles = array_merge(
      glob($moduleRoot . '/*.services.yml') ?: [],
      glob($moduleRoot . '/modules/*/*.services.yml') ?: [],
      glob($moduleRoot . '/modules/*/tests/modules/*/*.services.yml') ?: [],
      glob($moduleRoot . '/tests/modules/*/*.services.yml') ?: [],
    );

    foreach ($servicesFiles as $servicesFile) {
      $yaml = Yaml::parseFile($servicesFile);
      if (!\is_array($yaml) || !isset($yaml['services']) || !\is_array($yaml['services'])) {
        continue;
      }
      foreach ($yaml['services'] as $serviceDefinition) {
        if (!\is_array($serviceDefinition)
          || !isset($serviceDefinition['class'])
          || !\is_string($serviceDefinition['class'])
          || !isset($serviceDefinition['calls'])
          || !\is_array($serviceDefinition['calls'])
        ) {
          continue;
        }
        $className = ltrim($serviceDefinition['class'], '\\');
        foreach ($serviceDefinition['calls'] as $call) {
          if (\is_array($call) && isset($call[0]) && \is_string($call[0])) {
            $result[$className][] = $call[0];
          }
        }
      }
    }

    return self::$serviceCallMethodsPerClass = $result;
  }

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    $declaringClass = $method->getDeclaringClass();
    $methodName = $method->getName();

    foreach (self::getServiceCallMethodsPerClass() as $serviceClassName => $calledMethods) {
      if (!\in_array($methodName, $calledMethods, TRUE)) {
        continue;
      }
      if ($serviceClassName === $declaringClass->getName()) {
        return VirtualUsageData::withNote(
          \sprintf('Called by Drupal DIC via calls: in a canvas services.yml: %s::%s().', $declaringClass->getShortName(), $methodName),
        );
      }
      // The services.yml may list a concrete subclass while the method is
      // declared on an abstract base — check the inheritance chain.
      try {
        if ((new \ReflectionClass($serviceClassName))->isSubclassOf($declaringClass->getName())) {
          return VirtualUsageData::withNote(
            \sprintf('Called by Drupal DIC via calls: in a canvas services.yml on subclass %s: %s::%s().', $serviceClassName, $declaringClass->getShortName(), $methodName),
          );
        }
      }
      catch (\ReflectionException) {
        // Class not autoloadable during analysis — skip.
      }
    }

    return NULL;
  }

}
