<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adapts a scalar value to text with a configured prefix and/or suffix.
 */
#[Adapter(
  id: self::PLUGIN_ID,
  // TRICKY: no "/" in the label: the linked-prop badge renders labels as
  // slash-separated paths and would truncate at the slash.
  label: new TranslatableMarkup('Prefix and suffix'),
  inputs: [
    'value' => [],
    'prefix' => ['type' => 'string'],
    'suffix' => ['type' => 'string'],
  ],
  requiredInputs: ['value'],
  output: ['type' => 'string'],
)]
final class PrefixSuffixAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'prefix_suffix';

  protected mixed $value = NULL;

  protected ?string $prefix = NULL;

  protected ?string $suffix = NULL;

  public function adapt(): EvaluationResult {
    if (static::isEmptyValue($this->value)) {
      return new EvaluationResult(NULL);
    }
    if (!\is_scalar($this->value) && !$this->value instanceof \Stringable) {
      throw new \LogicException('The `value` input must be a scalar to prefix/suffix it.');
    }
    return new EvaluationResult(($this->prefix ?? '') . $this->value . ($this->suffix ?? ''));
  }

}
