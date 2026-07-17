<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adapts multiple text inputs into one, joined with a separator.
 *
 * Empty inputs are skipped along with their separator, so e.g. combining a
 * first name and an empty last name does not leave a dangling separator.
 *
 * Adapter inputs are a fixed, declared dictionary, so "variadic" is modeled
 * as ten optional slots (`text_1` … `text_10`); the editor UI reveals them
 * incrementally.
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Combine'),
  inputs: [
    'text_1' => ['type' => 'string'],
    'text_2' => ['type' => 'string'],
    'text_3' => ['type' => 'string'],
    'text_4' => ['type' => 'string'],
    'text_5' => ['type' => 'string'],
    'text_6' => ['type' => 'string'],
    'text_7' => ['type' => 'string'],
    'text_8' => ['type' => 'string'],
    'text_9' => ['type' => 'string'],
    'text_10' => ['type' => 'string'],
    'separator' => ['type' => 'string'],
  ],
  requiredInputs: ['text_1', 'text_2'],
  output: ['type' => 'string'],
  // Empty inputs are skipped; as long as `text_1` is non-empty the output is
  // non-empty, so all other slots tolerate emptiness.
  emptyToleratingInputs: [
    'text_2', 'text_3', 'text_4', 'text_5', 'text_6',
    'text_7', 'text_8', 'text_9', 'text_10', 'separator',
  ],
)]
final class CombineAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'combine';

  /**
   * The number of text input slots.
   */
  public const int SLOT_COUNT = 10;

  /**
   * The received text slot values, keyed by input name (`text_1`…`text_10`).
   *
   * @var array<string, string|null>
   */
  protected array $texts = [];

  protected ?string $separator = NULL;

  public function addInput(string $input, mixed $value): AdapterBase {
    // The text slots are collected into one array: their input names are not
    // valid property names per the coding standards.
    if (\str_starts_with($input, 'text_') && \array_key_exists($input, $this->getInputs())) {
      if ($value !== NULL && !$this->validateConformanceToJsonSchemaType($this->getInputs()[$input], $value)) {
        throw new \LogicException('…');
      }
      $this->texts[$input] = $value;
      return $this;
    }
    return parent::addInput($input, $value);
  }

  public function adapt(): EvaluationResult {
    $parts = [];
    for ($i = 1; $i <= self::SLOT_COUNT; $i++) {
      $part = $this->texts["text_$i"] ?? NULL;
      if (!static::isEmptyValue($part)) {
        $parts[] = $part;
      }
    }
    if ($parts === []) {
      return new EvaluationResult(NULL);
    }
    return new EvaluationResult(\implode($this->separator ?? ' ', $parts));
  }

}
