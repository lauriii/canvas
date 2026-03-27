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
 * Validates that values at specific YAML paths equal expected values.
 *
 * This plugin supports multiple path=value pairs separated by newlines.
 * Format for each line: "path.to.key=expected_value"
 */
#[AgentsParameterTest(
  id: 'yaml_path_equals',
  label: new TranslatableMarkup('YAML Path Equals'),
  description: new TranslatableMarkup('Checks if a YAML path equals the expected value.'),
)]
final class YamlPathEqualsTest extends AgentsParameterTestPluginBase implements ContainerFactoryPluginInterface {

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

    // Split the rule value into multiple path=value pairs by newline.
    $lines = array_filter(\array_map('trim', explode("\n", (string) $rule['value'])));

    foreach ($lines as $line) {
      $rule_parts = explode('=', $line, 2);
      if (count($rule_parts) < 2) {
        $this->errors[] = \sprintf('Invalid rule value format: %s. Expected path=value.', $line);
        continue;
      }

      $path = $rule_parts[0];
      $expected_value = $rule_parts[1];

      $result = $this->yamlHelper->getValueAtPath($yaml_string, $path);

      if ($result['error']) {
        $this->errors[] = 'Invalid YAML in component_structure parameter.';
        $this->details[] = $this->createDetailedResultRow(
          result: FALSE,
          object: "Tool: $tool_name, Parameter: $parameter_name",
          type: 'YamlPathEquals',
          expected: $line,
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
          type: 'YamlPathEquals',
          expected: $expected_value,
          actual: 'Not found',
          details: $error_msg,
        );
      }
      elseif ((string) $result['value'] !== (string) $expected_value) {
        $error_msg = \sprintf('Path %s expected value %s but got %s.', $path, $expected_value, (string) $result['value']);
        $this->errors[] = $error_msg;
        $this->details[] = $this->createDetailedResultRow(
          result: FALSE,
          object: "Tool: $tool_name, Parameter: $parameter_name, Path: $path",
          type: 'YamlPathEquals',
          expected: $expected_value,
          actual: (string) $result['value'],
          details: $error_msg,
        );
      }
      else {
        // Success case: Log success for the specific path-value pair.
        $success_msg = \sprintf('Path %s matched the expected value %s.', $path, $expected_value);
        $this->details[] = $this->createDetailedResultRow(
          result: TRUE,
          object: "Tool: $tool_name, Parameter: $parameter_name, Path: $path",
          type: 'YamlPathEquals',
          expected: $expected_value,
          actual: (string) $result['value'],
          details: $success_msg,
        );
      }
    }
  }

}
