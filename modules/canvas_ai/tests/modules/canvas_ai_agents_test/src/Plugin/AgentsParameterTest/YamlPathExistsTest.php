<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_agents_test\Plugin\AgentsParameterTest;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents_test\AgentsParameterTestPluginBase;
use Drupal\ai_agents_test\Attribute\AgentsParameterTest;
use Drupal\canvas_ai_agents_test\Service\YamlPathHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Validates that specific paths exist within a YAML-formatted parameter.
 *
 * This plugin supports multiple paths separated by newlines. It uses dot-notated
 * paths and supports escaping dots in keys using a backslash (e.g., "sdc\.theme_name\.component").
 */
#[AgentsParameterTest(
  id: 'yaml_path_exists',
  label: new TranslatableMarkup('YAML Path Exists'),
  description: new TranslatableMarkup('Checks if a YAML path exists in the component structure.'),
)]
final class YamlPathExistsTest extends AgentsParameterTestPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The YAML path helper service.
   *
   * @var \Drupal\canvas_ai_agents_test\Service\YamlPathHelper
   */
  protected YamlPathHelper $yamlHelper;

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\canvas_ai_agents_test\Service\YamlPathHelper $yaml_helper
   *   The YAML path helper service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, YamlPathHelper $yaml_helper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->yamlHelper = $yaml_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('canvas_ai_agents_test.yaml_path_helper')
    );
  }

  /**
   * Runs the test.
   *
   * @param \Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface $tool
   *   The tool to run the test on.
   * @param array $values
   *   All the values keyed by the parameter name.
   * @param string $parameter_name
   *   The name of the parameter to test.
   * @param array $rule
   *   The rule to test against, which may include a 'value' key.
   * @param string $tool_name
   *   The name of the tool being tested.
   */
  public function runTest(ExecutableFunctionCallInterface $tool, array $values, string $parameter_name, array $rule, string $tool_name): void {
    if ($parameter_name !== 'component_structure') {
      return;
    }

    $yaml_string = (string) ($values[$parameter_name] ?? '');

    // Split the rule value into multiple paths by newline and clean them.
    $paths = array_filter(\array_map('trim', explode("\n", (string) $rule['value'])));

    foreach ($paths as $path) {
      // Traverse the YAML for each specific path.
      $result = $this->yamlHelper->getValueAtPath($yaml_string, $path);

      if ($result['error']) {
        // Log YAML parsing error and stop processing this parameter.
        $this->errors[] = 'Invalid YAML in component_structure parameter.';
        $this->details[] = $this->createDetailedResultRow(
          result: FALSE,
          object: "Tool: $tool_name, Parameter: $parameter_name",
          type: 'YamlPathExists',
          expected: $path,
          actual: 'Invalid YAML',
          details: 'Invalid YAML in component_structure parameter.',
        );
        return;
      }

      if (!$result['exists']) {
        $error_msg = \sprintf('Path %s was not found in %s.', $path, $parameter_name);
        $this->errors[] = $error_msg;
        $this->details[] = $this->createDetailedResultRow(
          result: FALSE,
          object: "Tool: $tool_name, Parameter: $parameter_name, Path: $path",
          type: 'YamlPathExists',
          expected: $path,
          actual: 'Not found',
          details: $error_msg,
        );
      }
      else {
        // Log success for the specific path with a success message in details.
        $success_msg = \sprintf('Path %s was found in %s.', $path, $parameter_name);
        $this->details[] = $this->createDetailedResultRow(
          result: TRUE,
          object: "Tool: $tool_name, Parameter: $parameter_name, Path: $path",
          type: 'YamlPathExists',
          expected: $path,
          actual: $path,
          details: $success_msg,
        );
      }
    }
  }

}
