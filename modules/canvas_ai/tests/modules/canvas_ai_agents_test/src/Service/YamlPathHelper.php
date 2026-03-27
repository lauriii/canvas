<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_agents_test\Service;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Service to help with YAML parsing and path traversal.
 */
final class YamlPathHelper {

  /**
   * Parses YAML and retrieves a value at a dot-notated path.
   *
   * Supports escaping literal dots in keys using a backslash (\.).
   * Example: "operations.0.components.sdc\.theme_name\.component.props.title"
   *
   * @param string $yaml_string
   *   The YAML string to parse.
   * @param string $path
   *   The dot-notated path to traverse.
   *
   * @return array
   *   An array containing:
   *   - 'exists' (bool): TRUE if the path was found.
   *   - 'value' (mixed): The value at the path if it exists.
   *   - 'error' (string|null): A message if parsing failed.
   */
  public function getValueAtPath(string $yaml_string, string $path): array {
    try {
      $data = Yaml::parse($yaml_string);
    }
    catch (ParseException $e) {
      return [
        'exists' => FALSE,
        'value' => NULL,
        'error' => 'Invalid YAML format: ' . $e->getMessage(),
      ];
    }

    // Split the path by dots, but only if they are not preceded by a backslash.
    // This allows keys to contain literal dots.
    $segments = preg_split('/(?<!\\\\)\./', $path);
    if ($segments === FALSE) {
      return [
        'exists' => FALSE,
        'value' => NULL,
        'error' => 'Invalid path format.',
      ];
    }

    $current = $data;

    foreach ($segments as $segment) {
      // Unescape any dots that were escaped with a backslash.
      $key = str_replace('\.', '.', $segment);

      if (\is_array($current) && \array_key_exists($key, $current)) {
        $current = $current[$key];
      }
      else {
        return [
          'exists' => FALSE,
          'value' => NULL,
          'error' => NULL,
        ];
      }
    }

    return [
      'exists' => TRUE,
      'value' => $current,
      'error' => NULL,
    ];
  }

}
