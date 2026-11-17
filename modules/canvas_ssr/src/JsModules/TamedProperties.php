<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules;

use Psr\Log\LoggerInterface;

/**
 * The properties the realm setup module tames before it freezes them.
 *
 * Freezing a built-in makes assigning to its properties throw, even on an
 * object that only inherits them, so a component that sets `error.name`
 * would fall back to CSR. A tamed property still lets such an object get an
 * own property of that name. docs/CONFIGURATION.md documents the format.
 *
 * They come from two container parameters, merged:
 * - canvas_ssr.tamed_properties: the defaults.
 * - canvas_ssr.additional_tamed_properties: a site's additions, empty by
 *   default. A site sets this one in its services.yml, so it keeps the
 *   defaults as they change.
 *
 * An entry that isn't in the format is skipped, with a warning.
 *
 * @see js/realm-setup.js
 */
final class TamedProperties {

  /**
   * A path to an object, from a global or a built-in.
   *
   * It's a global's name or a built-in's %Name%, followed by property names.
   */
  private const string GLOBAL_PATH = '/^(?:%[A-Za-z]+%|[A-Za-z_$][\w$]*)(?:\.[A-Za-z_$][\w$]*)*$/';

  /**
   * A path to an object a module exports.
   *
   * It's an export's name, followed by property names. A default export is
   * "default".
   */
  private const string EXPORT_PATH = '/^[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*$/';

  /**
   * A property, or a pattern for several.
   *
   * It's a name, a well-known symbol like "[Symbol.iterator]", "prefix*" for
   * every name with the prefix, or "*" for every own property.
   */
  private const string PROPERTY = '/^(?:\*|\[Symbol\.[A-Za-z]+\]|[A-Za-z_$][\w$]*\*?)$/';

  /**
   * The merged global properties, once validated.
   *
   * @var array<string, list<string>>|null
   */
  private ?array $globals = NULL;

  /**
   * The merged module properties, once validated.
   *
   * @var array<string, array<string, list<string>>>|null
   */
  private ?array $modules = NULL;

  /**
   * Constructs a TamedProperties.
   *
   * @param array<mixed> $defaults
   *   The canvas_ssr.tamed_properties parameter.
   * @param array<mixed> $additional
   *   The canvas_ssr.additional_tamed_properties parameter.
   * @param \Psr\Log\LoggerInterface $logger
   *   Where skipped entries are reported.
   */
  public function __construct(
    private readonly array $defaults,
    private readonly array $additional,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the global properties to tame.
   *
   * @return array<string, list<string>>
   *   The properties to tame, by the path to the object that owns them.
   */
  public function globals(): array {
    if ($this->globals === NULL) {
      $this->globals = [];
      foreach ([$this->defaults, $this->additional] as $parameter) {
        $this->globals = self::merge($this->globals, $this->paths($parameter['globals'] ?? [], self::GLOBAL_PATH, 'globals'));
      }
    }

    return $this->globals;
  }

  /**
   * Returns the module properties to tame.
   *
   * @return array<string, array<string, list<string>>>
   *   The properties to tame, by the path to the object that owns them, by
   *   the specifier of the module that exports it. A specifier is bare, or a
   *   URL, and resolves through the SSR import map.
   */
  public function modules(): array {
    if ($this->modules === NULL) {
      $tamed = [];
      foreach ([$this->defaults, $this->additional] as $parameter) {
        $modules = $parameter['modules'] ?? [];
        if (!\is_array($modules)) {
          $this->skip('modules', 'it is not a map of specifiers');
          continue;
        }
        foreach ($modules as $specifier => $paths) {
          $specifier = (string) $specifier;
          if ($specifier === '' || \str_starts_with($specifier, './') || \str_starts_with($specifier, '../')) {
            $this->skip("modules.$specifier", 'a relative specifier names no module from the page');
            continue;
          }
          $tamed[$specifier] = self::merge(
            $tamed[$specifier] ?? [],
            $this->paths($paths, self::EXPORT_PATH, "modules.$specifier"),
          );
        }
      }
      $this->modules = $tamed;
    }

    return $this->modules;
  }

  /**
   * Validates a map of paths to property lists.
   *
   * @param mixed $paths
   *   The map.
   * @param string $path_pattern
   *   The pattern every path must match.
   * @param string $where
   *   Where the map is, for warnings.
   *
   * @return array<string, list<string>>
   *   The valid entries.
   */
  private function paths(mixed $paths, string $path_pattern, string $where): array {
    if (!\is_array($paths)) {
      $this->skip($where, 'it is not a map of paths');
      return [];
    }
    $valid = [];
    foreach ($paths as $path => $properties) {
      // services.yml writes % as %%, and the container only unescapes
      // values, not keys.
      $path = \str_replace('%%', '%', (string) $path);
      if (!\preg_match($path_pattern, $path)) {
        $this->skip("$where.$path", 'it is not a path');
        continue;
      }
      if (!\is_array($properties) || !\array_is_list($properties)) {
        $this->skip("$where.$path", 'it is not a list of properties');
        continue;
      }
      foreach ($properties as $property) {
        if (!\is_string($property) || !\preg_match(self::PROPERTY, $property)) {
          $this->skip("$where.$path", \is_string($property) ? "\"$property\" is not a property" : 'a property is not a string');
          continue;
        }
        $valid[$path][] = $property;
      }
    }

    return $valid;
  }

  /**
   * Merges two maps of paths to property lists.
   *
   * @param array<string, list<string>> $a
   *   One map.
   * @param array<string, list<string>> $b
   *   The other.
   *
   * @return array<string, list<string>>
   *   Every path in either, with the properties either lists.
   */
  private static function merge(array $a, array $b): array {
    foreach ($b as $path => $properties) {
      $a[$path] = \array_values(\array_unique([...$a[$path] ?? [], ...$properties]));
    }

    return $a;
  }

  /**
   * Warns that an entry is skipped.
   *
   * @param string $where
   *   The entry.
   * @param string $why
   *   Why.
   */
  private function skip(string $where, string $why): void {
    $this->logger->warning('The tamed property entry at {where} is skipped, since {why}.', [
      'where' => $where,
      'why' => $why,
    ]);
  }

}
