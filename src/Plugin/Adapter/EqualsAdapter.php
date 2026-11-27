<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adapts a value conditionally: if it equals a comparison, then/else results.
 *
 * With `negate`, this also covers "does not equal". Parametric: the output
 * shape mirrors the `then` and `else` inputs, so this adapter can populate a
 * prop of any shape.
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Equals'),
  inputs: [
    'value' => [],
    'comparison' => [],
    'then' => [],
    'else' => [],
    'negate' => ['type' => 'boolean'],
  ],
  requiredInputs: ['value', 'comparison', 'then'],
  outputMirrorsInputs: ['then', 'else'],
  // Without an else branch, a non-matching value yields an empty output.
  requiredInputsWhenOutputRequired: ['else'],
  // An empty compared value merely selects the else branch.
  emptyToleratingInputs: ['value', 'comparison', 'negate'],
)]
final class EqualsAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'equals';

  protected mixed $value = NULL;

  protected mixed $comparison = NULL;

  protected mixed $then = NULL;

  protected mixed $else = NULL;

  protected ?bool $negate = NULL;

  public function adapt(): EvaluationResult {
    // Loose comparison, on purpose: the compared value typically comes from a
    // typed field (e.g. integer 0) while the comparison value is entered as
    // text in the UI (e.g. "0"). PHP 8 no longer considers unrelated
    // string/number pairs equal, so this stays predictable.
    // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators.DisallowedEqualOperator
    $matches = $this->value == $this->comparison;
    if ($this->negate) {
      $matches = !$matches;
    }
    return new EvaluationResult($matches ? $this->then : $this->else);
  }

}
