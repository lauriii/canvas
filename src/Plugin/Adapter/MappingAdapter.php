<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adapts a value by looking it up in a configured list of case/output pairs.
 *
 * The `cases` input is a JSON object mapping case values (as strings) to
 * outputs, e.g. `{"blue": "primary", "red": "danger"}`. This keeps the
 * variable-length mapping table representable as a single (static, exportable)
 * text input; the editor UI presents it as case/output rows. Unmatched values
 * yield the `default` input's value. Parametric: the output shape mirrors the
 * `default` input (the case outputs are expected to have that same shape).
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Mapping'),
  inputs: [
    'value' => [],
    'cases' => ['type' => 'string'],
    'default' => [],
  ],
  requiredInputs: ['value', 'cases'],
  outputMirrorsInputs: ['default'],
  // Without a default, an unmatched value yields an empty output.
  requiredInputsWhenOutputRequired: ['default'],
  // An empty looked-up value merely falls back to the default.
  emptyToleratingInputs: ['value', 'cases'],
)]
final class MappingAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'mapping';

  protected mixed $value = NULL;

  protected ?string $cases = NULL;

  protected mixed $default = NULL;

  public function adapt(): EvaluationResult {
    $map = \json_decode($this->cases ?? '', TRUE);
    if (!\is_array($map)) {
      return new EvaluationResult($this->default);
    }
    // Case keys in JSON are always strings; compare against the string
    // representation of the value (TRUE => "1", FALSE => "0").
    $key = match (TRUE) {
      \is_bool($this->value) => $this->value ? '1' : '0',
      \is_scalar($this->value), $this->value instanceof \Stringable => (string) $this->value,
      default => NULL,
    };
    if ($key !== NULL && \array_key_exists($key, $map)) {
      return new EvaluationResult($map[$key]);
    }
    return new EvaluationResult($this->default);
  }

}
