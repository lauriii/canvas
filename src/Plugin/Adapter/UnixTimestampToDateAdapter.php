<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('UNIX timestamp to date'),
  inputs: [
    'unix' => ['type' => 'integer'],
  ],
  requiredInputs: ['unix'],
  output: ['type' => 'string', 'format' => 'date'],
)]
final class UnixTimestampToDateAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'unix_to_date';

  protected string $unix;

  public function adapt(): EvaluationResult {
    // @todo Ensure that the `unix` input is constrained to the appropriate range.
    $datetime = \DateTime::createFromFormat('U', $this->unix);
    \assert($datetime !== FALSE);
    return new EvaluationResult($datetime->format('Y-m-d'));
  }

}
