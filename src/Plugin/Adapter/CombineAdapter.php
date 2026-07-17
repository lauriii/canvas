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
)]
final class CombineAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'combine';

  /**
   * The number of text input slots.
   */
  public const int SLOT_COUNT = 10;

  protected ?string $text_1 = NULL;

  protected ?string $text_2 = NULL;

  protected ?string $text_3 = NULL;

  protected ?string $text_4 = NULL;

  protected ?string $text_5 = NULL;

  protected ?string $text_6 = NULL;

  protected ?string $text_7 = NULL;

  protected ?string $text_8 = NULL;

  protected ?string $text_9 = NULL;

  protected ?string $text_10 = NULL;

  protected ?string $separator = NULL;

  public function adapt(): EvaluationResult {
    $parts = [];
    for ($i = 1; $i <= self::SLOT_COUNT; $i++) {
      $part = $this->{"text_$i"};
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
