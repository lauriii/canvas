<?php

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\Core\Plugin\PluginBase;
use Drupal\canvas\PropShape\PropShape;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * @internal
 *
 * @phpstan-import-type JsonSchema from \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType
 */
abstract class AdapterBase extends PluginBase implements AdapterInterface {

  public function addInput(string $input, mixed $value): AdapterBase {
    if (array_key_exists($input, $this->getInputs())) {
      $json_schema_type = $this->getInputs()[$input];
      // @see \Drupal\Core\Theme\Component\ComponentValidator
      if (!$this->validateConformanceToJsonSchemaType($json_schema_type, $value)) {
        throw new \LogicException('…');
      }
      $this->$input = $value;
    }
    return $this;
  }

  public function getInputSchema(string $input): array {
    return PropShape::standardize($this->getInputs()[$input])->resolvedSchema;
  }

  /**
   * @return array<string, JsonSchema>
   */
  public function getInputs(): array {
    return is_array($this->getPluginDefinition()) ? (array) $this->getPluginDefinition()['inputs'] : [];
  }

  /**
   * @param JsonSchema $schema
   */
  public function matchesOutputSchema(array $schema): bool {
    $target = PropShape::standardize($schema)->resolvedSchema;
    return PropShape::normalizePropSchema($target) === PropShape::normalizePropSchema($this->getOutputSchema());
  }

  /**
   * @param JsonSchema $schema
   * @param mixed $value
   *
   * @return bool
   * @throws \Exception
   */
  public function validateConformanceToJsonSchemaType(array $schema, mixed $value): bool {
    $schema = Validator::arrayToObjectRecursive($schema);
    $validator = new Validator();
    $validator->validate($value, $schema, Constraint::CHECK_MODE_TYPE_CAST);
    $validator->getErrors();
    if ($validator->isValid()) {
      return TRUE;
    }

    $message_parts = array_map(
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
    \assert(is_array($this->getPluginDefinition()));
    \assert(array_key_exists('output', $this->getPluginDefinition()));
    return PropShape::standardize($this->getPluginDefinition()['output'])->resolvedSchema;
  }

  /**
   * @todo Determine whether there is a better way.
   */
  public function inputIsRequired(string $input): bool {
    \assert(is_array($this->getPluginDefinition()));
    \assert(array_key_exists('requiredInputs', $this->getPluginDefinition()));
    return in_array($input, $this->getPluginDefinition()['requiredInputs'], TRUE);
  }

}
