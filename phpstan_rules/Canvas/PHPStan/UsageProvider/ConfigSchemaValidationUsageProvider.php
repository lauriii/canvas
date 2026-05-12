<?php

declare(strict_types=1);

namespace Canvas\PHPStan\UsageProvider;

use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use Symfony\Component\Yaml\Yaml;

/**
 * Marks static methods registered as schema Callback constraints as used.
 *
 * Canvas config schema files declare validation callbacks as:
 *
 *   callback: [ClassName, methodName]
 *
 * Drupal's TypedData system dispatches these at validation time — ShipMonk
 * cannot trace the YAML-based dispatch. This provider parses all canvas
 * *.schema.yml files to discover which static methods are registered this
 * way — no hard-coded list is needed.
 *
 * Note: field default value callbacks registered as PHP strings (e.g.
 * Page::getRequestTime via setDefaultValueCallback()) are suppressed inline
 * with @phpstan-ignore in the source class, since they are not discoverable
 * via YAML parsing.
 */
final class ConfigSchemaValidationUsageProvider extends ReflectionBasedMemberUsageProvider {

  /**
   * Schema callback methods keyed by class name, parsed from *.schema.yml.
   *
   * Populated lazily by getSchemaCallbackMethodsPerClass(). NULL before first
   * call.
   *
   * @var array<string, list<string>>|null
   */
  private static ?array $schemaCallbackMethodsPerClass = NULL;

  /**
   * Returns schema Callback constraint methods keyed by declaring class name.
   *
   * Parses all canvas *.schema.yml files (main module, submodules, and test
   * modules) and collects every callback: [ClassName, method] entry. Result is
   * cached statically.
   *
   * @return array<string, list<string>>
   */
  private static function getSchemaCallbackMethodsPerClass(): array {
    if (self::$schemaCallbackMethodsPerClass !== NULL) {
      return self::$schemaCallbackMethodsPerClass;
    }

    $moduleRoot = dirname(__DIR__, 4);
    $result = [];

    $schemaFiles = array_merge(
      glob($moduleRoot . '/config/schema/*.schema.yml') ?: [],
      glob($moduleRoot . '/modules/*/config/schema/*.schema.yml') ?: [],
      glob($moduleRoot . '/tests/modules/*/config/schema/*.schema.yml') ?: [],
    );

    foreach ($schemaFiles as $schemaFile) {
      $schema = Yaml::parseFile($schemaFile);
      if (!\is_array($schema)) {
        continue;
      }
      self::collectCallbacksFromSchema($schema, $result);
    }

    return self::$schemaCallbackMethodsPerClass = $result;
  }

  /**
   * Recursively collects callback: [ClassName, method] entries from YAML data.
   *
   * @param mixed $data
   * @param array<string, list<string>> $result
   */
  private static function collectCallbacksFromSchema(mixed $data, array &$result): void {
    if (!\is_array($data)) {
      return;
    }
    foreach ($data as $key => $value) {
      if ($key === 'callback'
        && \is_array($value)
        && count($value) === 2
        && \is_string($value[0])
        && \is_string($value[1])
      ) {
        $className = ltrim($value[0], '\\');
        $result[$className][] = $value[1];
      }
      else {
        self::collectCallbacksFromSchema($value, $result);
      }
    }
  }

  protected function shouldMarkMethodAsUsed(\ReflectionMethod $method): ?VirtualUsageData {
    $schemaCallbacks = self::getSchemaCallbackMethodsPerClass()[$method->getDeclaringClass()->getName()] ?? NULL;
    if ($schemaCallbacks !== NULL && \in_array($method->getName(), $schemaCallbacks, TRUE)) {
      return VirtualUsageData::withNote(
        \sprintf('Called by Drupal TypedData via Callback constraint in a canvas schema.yml: %s::%s().', $method->getDeclaringClass()->getShortName(), $method->getName()),
      );
    }

    return NULL;
  }

}
