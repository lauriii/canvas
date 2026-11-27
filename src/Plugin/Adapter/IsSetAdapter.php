<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adapts any value to a boolean: whether the value is set (non-empty).
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Is set / not set'),
  inputs: [
    'value' => [],
    'negate' => ['type' => 'boolean'],
  ],
  requiredInputs: ['value'],
  output: ['type' => 'boolean'],
  // An empty value is exactly what this adapter reports on: output is always
  // a boolean.
  emptyToleratingInputs: ['value', 'negate'],
)]
final class IsSetAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'is_set';

  protected mixed $value = NULL;

  protected ?bool $negate = NULL;

  public function adapt(): EvaluationResult {
    $is_set = !static::isEmptyValue($this->value);
    return new EvaluationResult($this->negate ? !$is_set : $is_set);
  }

}
