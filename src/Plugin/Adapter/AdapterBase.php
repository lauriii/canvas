<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropShape\PropShape;
use Drupal\Core\Plugin\PluginBase;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * @internal
 *
 * @phpstan-import-type JsonSchema from \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType
 */
abstract class AdapterBase extends PluginBase implements AdapterInterface {

  public function addInput(string $input, mixed $value): AdapterBase {
    if (\array_key_exists($input, $this->getInputs())) {
      $json_schema_type = $this->getInputs()[$input];
      // An empty schema means "any shape": accept the value as-is.
      // @see \Drupal\canvas\Plugin\Adapter\Adapter::__construct()
      // @see \Drupal\Core\Theme\Component\ComponentValidator
      if ($json_schema_type !== [] && !$this->validateConformanceToJsonSchemaType($json_schema_type, $value)) {
        throw new \LogicException('…');
      }
      $this->$input = $value;
    }
    return $this;
  }

  public function getInputSchema(string $input): array {
    // An empty schema means "any shape"; it cannot be standardized.
    if ($this->getInputs()[$input] === []) {
      return [];
    }
    return PropShape::standardize($this->getInputs()[$input])->resolvedSchema;
  }

  /**
   * @return array<string, JsonSchema>
   */
  public function getInputs(): array {
    return \is_array($this->getPluginDefinition()) ? (array) $this->getPluginDefinition()['inputs'] : [];
  }

  /**
   * @param JsonSchema $schema
   */
  public function matchesOutputSchema(array $schema): bool {
    // Parametric adapters produce whatever shape their mirroring inputs are
    // populated with, so they match any target shape.
    // @see \Drupal\canvas\Plugin\Adapter\Adapter::__construct()
    if ($this->getOutputMirroringInputs() !== []) {
      return TRUE;
    }
    $target = PropShape::standardize($schema)->resolvedSchema;
    return PropShape::normalizePropSchema($target) === PropShape::normalizePropSchema($this->getOutputSchema());
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputMirroringInputs(): array {
    \assert(\is_array($this->getPluginDefinition()));
    return $this->getPluginDefinition()['outputMirrorsInputs'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getRequiredInputsWhenOutputRequired(): array {
    \assert(\is_array($this->getPluginDefinition()));
    return $this->getPluginDefinition()['requiredInputsWhenOutputRequired'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function inputToleratesEmpty(string $input): bool {
    \assert(\is_array($this->getPluginDefinition()));
    return \in_array($input, $this->getPluginDefinition()['emptyToleratingInputs'] ?? [], TRUE);
  }

  /**
   * @param JsonSchema $schema
   * @param mixed $value
   *
   * @return bool
   * @throws \Exception
   */
  public static function validateConformanceToJsonSchemaType(array $schema, mixed $value): bool {
    $schema = Validator::arrayToObjectRecursive($schema);
    $validator = new Validator();
    $validator->validate($value, $schema, Constraint::CHECK_MODE_TYPE_CAST);
    $validator->getErrors();
    if ($validator->isValid()) {
      return TRUE;
    }

    $message_parts = \array_map(
      static function (array $error): string {
        return \sprintf("[%s] %s", $error['property'], $error['message']);
      },
      $validator->getErrors()
    );
    $message = implode("/n", $message_parts);
    throw new \Exception($message);
  }

  /**
   * @return JsonSchema
   */
  public function getOutputSchema(): array {
    \assert(\is_array($this->getPluginDefinition()));
    \assert(\array_key_exists('output', $this->getPluginDefinition()));
    // Parametric adapters have no static output schema.
    // @see ::getOutputMirroringInputs()
    if ($this->getPluginDefinition()['output'] === []) {
      return [];
    }
    return PropShape::standardize($this->getPluginDefinition()['output'])->resolvedSchema;
  }

  /**
   * Whether an evaluated input value is considered empty.
   *
   * Adapters treating "no value" specially (e.g. `is_set`, `fallback`,
   * `combine`) share this definition of emptiness.
   */
  protected static function isEmptyValue(mixed $value): bool {
    return $value === NULL || $value === '' || $value === [];
  }

  /**
   * @todo Determine whether there is a better way.
   */
  public function inputIsRequired(string $input): bool {
    \assert(\is_array($this->getPluginDefinition()));
    \assert(\array_key_exists('requiredInputs', $this->getPluginDefinition()));
    return \in_array($input, $this->getPluginDefinition()['requiredInputs'], TRUE);
  }

}
