<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Count days'),
  inputs: [
    'oldest' => ['type' => 'string', 'format' => 'date'],
    'newest' => ['type' => 'string', 'format' => 'date'],
  ],
  requiredInputs: ['oldest'],
  output: ['type' => 'integer'],
)]
final class DayCountAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'day_count';
  protected string $oldest;
  protected ?string $newest = NULL;

  public function adapt(): EvaluationResult {
    $utc = new \DateTimeZone("UTC");
    $oldest = \DateTime::createFromFormat('Y-m-d', $this->oldest, $utc);
    $newest = $this->newest
      ? \DateTime::createFromFormat('Y-m-d', $this->newest, $utc)
      : new \DateTimeImmutable("now", $utc);
    // Note: $oldest and $newest are already guaranteed to be valid, so this
    // assertion exists only to satisfy PHPStan.
    \assert($oldest !== FALSE && $newest !== FALSE);
    return new EvaluationResult($newest->diff($oldest)->days);
  }

}
