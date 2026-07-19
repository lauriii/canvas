<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adapts text conditionally: if it contains a needle, then/else results.
 *
 * The `position` input selects the match position: `contains` (default),
 * `starts_with`, or `ends_with`. With `negate`, this also covers the
 * "does not contain / start with / end with" conditions. Parametric: the
 * output shape mirrors the `then` and `else` inputs, so this adapter can
 * populate a prop of any shape.
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Contains'),
  inputs: [
    'text' => ['type' => 'string'],
    'needle' => ['type' => 'string'],
    'position' => ['type' => 'string', 'enum' => ['contains', 'starts_with', 'ends_with']],
    'negate' => ['type' => 'boolean'],
    'then' => [],
    'else' => [],
  ],
  requiredInputs: ['text', 'needle', 'then'],
  outputMirrorsInputs: ['then', 'else'],
  // Without an else branch, non-matching text yields an empty output.
  requiredInputsWhenOutputRequired: ['else'],
  // Empty matched text merely selects the else branch.
  emptyToleratingInputs: ['text', 'needle', 'position', 'negate'],
)]
final class ContainsAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'contains';

  protected ?string $text = NULL;

  protected ?string $needle = NULL;

  protected ?string $position = NULL;

  protected ?bool $negate = NULL;

  protected mixed $then = NULL;

  protected mixed $else = NULL;

  public function adapt(): EvaluationResult {
    $text = $this->text ?? '';
    $needle = $this->needle ?? '';
    $matches = match ($this->position ?? 'contains') {
      'starts_with' => \str_starts_with($text, $needle),
      'ends_with' => \str_ends_with($text, $needle),
      default => \str_contains($text, $needle),
    };
    if ($this->negate) {
      $matches = !$matches;
    }
    return new EvaluationResult($matches ? $this->then : $this->else);
  }

}
