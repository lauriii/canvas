<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * @internal
 *
 * @phpstan-import-type JsonSchema from \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType
 */
interface AdapterInterface extends PluginInspectionInterface {

  /**
   * @param string $input
   * @param mixed $value
   *
   * @return self
   */
  public function addInput(string $input, mixed $value): self;

  /**
   * @return \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult
   */
  public function adapt(): EvaluationResult;

  /**
   * @param JsonSchema $schema
   *
   * @return bool
   */
  public function matchesOutputSchema(array $schema): bool;

  /**
   * @return array<string, JsonSchema>
   */
  public function getInputs(): array;

  /**
   * @param string $input
   *
   * @return bool
   */
  public function inputIsRequired(string $input): bool;

  /**
   * @param string $input
   *
   * @return JsonSchema
   */
  public function getInputSchema(string $input): array;

}
