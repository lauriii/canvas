<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an Adapter attribute object.
 *
 * Plugin Namespace: Plugin\Adapter
 *
 * @see \Drupal\canvas\Plugin\Adapter\AdapterInterface
 * @see \Drupal\canvas\Plugin\AdapterManager
 * @see plugin_api
 *
 * @phpstan-import-type JsonSchema from \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class Adapter extends Plugin {

  /**
   * @param string $id
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   * @param array<string, JsonSchema> $inputs
   *   The named inputs this adapter accepts. An empty array (`[]`) as an
   *   input's schema means "any shape": no JSON schema validation is applied
   *   to values for that input.
   * @param array<string> $requiredInputs
   * @param JsonSchema $output
   *   The declared output schema. Mutually exclusive with
   *   $outputMirrorsInputs: exactly one of the two must be non-empty.
   * @param array<string> $outputMirrorsInputs
   *   For parametric adapters: the names of the inputs whose shape mirrors the
   *   output shape (e.g. the `then`/`else` inputs of a conditional). Such
   *   adapters match any target prop shape; the mirroring inputs are then
   *   expected to be populated by sources matching that target shape.
   * @param array<string> $requiredInputsWhenOutputRequired
   *   Inputs that must additionally be configured when the targeted prop is
   *   required, because without them the adapter may produce an empty output
   *   (e.g. the `else` input of a conditional).
   * @param array<string> $emptyToleratingInputs
   *   Inputs whose empty value does not make the output empty, provided all
   *   (conditionally) required inputs are configured — e.g. the compared
   *   `value` of a conditional, whose emptiness merely selects the else
   *   branch. Inputs not listed here propagate their emptiness to the output,
   *   so when the targeted prop is required, only required fields are offered
   *   as their candidates.
   * @param class-string|null $deriver
   *
   * @see \Drupal\canvas\Plugin\Adapter\AdapterBase::matchesOutputSchema()
   * @see \Drupal\canvas\ShapeMatcher\PropSourceSuggester::buildAdapterSuggestions()
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    protected array $inputs,
    protected array $requiredInputs,
    protected array $output = [],
    protected array $outputMirrorsInputs = [],
    protected array $requiredInputsWhenOutputRequired = [],
    protected array $emptyToleratingInputs = [],
    public readonly ?string $deriver = NULL,
  ) {
    if (($output === []) === ($outputMirrorsInputs === [])) {
      throw new \LogicException(\sprintf('The `%s` adapter must declare exactly one of `output` or `outputMirrorsInputs`.', $id));
    }
    foreach (['outputMirrorsInputs' => $outputMirrorsInputs, 'requiredInputsWhenOutputRequired' => $requiredInputsWhenOutputRequired, 'emptyToleratingInputs' => $emptyToleratingInputs] as $list_name => $list) {
      if (\array_diff($list, \array_keys($inputs)) !== []) {
        throw new \LogicException(\sprintf('The `%s` adapter declares unknown input names in `%s`.', $id, $list_name));
      }
    }
  }

}
