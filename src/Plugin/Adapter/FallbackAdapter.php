<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adapts an optional value to a required one by providing a default.
 *
 * Parametric: the output shape mirrors the `value` and `default` inputs, so
 * this adapter can populate a prop of any shape.
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Fallback'),
  inputs: [
    'value' => [],
    'default' => [],
  ],
  requiredInputs: ['value', 'default'],
  outputMirrorsInputs: ['value', 'default'],
)]
final class FallbackAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'fallback';

  protected mixed $value = NULL;

  protected mixed $default = NULL;

  public function adapt(): EvaluationResult {
    return new EvaluationResult(static::isEmptyValue($this->value) ? $this->default : $this->value);
  }

}
