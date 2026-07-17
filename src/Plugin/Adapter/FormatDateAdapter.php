<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Adapter;

use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\FormattedDateDiff;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adapts a datetime string to human-readable text.
 *
 * Supports two modes, controlled by the `format` input:
 * - a date format config entity ID (e.g. `medium`): absolute display, using
 *   the site's (localizable) date format configuration
 * - the special value `relative`: relative display, e.g. "2 days ago"
 *
 * Complements the `unix_to_date` adapter, which converts integer timestamps
 * (e.g. an entity's created/changed fields) to date strings that this adapter
 * accepts as input.
 *
 * @see \Drupal\canvas\Plugin\Adapter\UnixTimestampToDateAdapter
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Date conversion'),
  inputs: [
    'date' => ['type' => 'string', 'format' => 'date-time'],
    'format' => ['type' => 'string'],
  ],
  requiredInputs: ['date', 'format'],
  output: ['type' => 'string'],
)]
final class FormatDateAdapter extends AdapterBase implements ContainerFactoryPluginInterface {

  public const string PLUGIN_ID = 'format_date';

  /**
   * The `format` input value that selects relative display.
   */
  public const string FORMAT_RELATIVE = 'relative';

  protected ?string $date = NULL;

  protected ?string $format = NULL;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    // TRICKY: not private readonly: PluginBase uses
    // DependencySerializationTrait, which supports neither.
    protected DateFormatterInterface $dateFormatter,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter'),
      $container->get(EntityTypeManagerInterface::class),
      $container->get(TimeInterface::class),
    );
  }

  public function addInput(string $input, mixed $value): AdapterBase {
    // Datetime strings occur as both JSON Schema `format: date` (e.g.
    // `2024-05-06`) and `format: date-time` (e.g. `2024-05-06T10:00:00`),
    // depending on the field type and its settings. Accept both instead of
    // strictly validating the declared `date-time` format, so that e.g.
    // date-only fields and chained `unix_to_date` output remain adaptable.
    if ($input === 'date') {
      if ($value !== NULL && !\is_string($value)) {
        throw new \LogicException('The `date` input must be a datetime string.');
      }
      $this->date = $value;
      return $this;
    }
    return parent::addInput($input, $value);
  }

  public function adapt(): EvaluationResult {
    if (static::isEmptyValue($this->date) || static::isEmptyValue($this->format)) {
      return new EvaluationResult(NULL);
    }
    \assert(\is_string($this->date) && \is_string($this->format) && $this->format !== '');

    try {
      // TRICKY: date-only strings ("Y-m-d") get a midnight UTC time; datetime
      // field values are stored in UTC, which \DateTimeImmutable defaults to
      // only if no timezone is embedded in the string.
      $datetime = new \DateTimeImmutable($this->date, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      // Unparseable input: adapt to nothing rather than failing the render.
      return new EvaluationResult(NULL);
    }
    $timestamp = $datetime->getTimestamp();

    if ($this->format === self::FORMAT_RELATIVE) {
      $now = $this->time->getRequestTime();
      $options = ['granularity' => 1, 'return_as_object' => TRUE];
      $diff = $timestamp > $now
        ? $this->dateFormatter->formatTimeDiffUntil($timestamp, $options)
        : $this->dateFormatter->formatTimeDiffSince($timestamp, $options);
      \assert($diff instanceof FormattedDateDiff);
      $value = $timestamp > $now
        ? (string) $this->t('in @time', ['@time' => $diff->getString()])
        : (string) $this->t('@time ago', ['@time' => $diff->getString()]);
      // A relative phrase goes stale as time passes; FormattedDateDiff knows
      // for how long it remains accurate.
      return new EvaluationResult($value, CacheableMetadata::createFromObject($diff));
    }

    $date_format = $this->entityTypeManager->getStorage('date_format')->load($this->format);
    if ($date_format === NULL) {
      // An unknown date format config entity: adapt to nothing rather than
      // failing the render.
      return new EvaluationResult(NULL);
    }
    return new EvaluationResult(
      $this->dateFormatter->format($timestamp, $this->format),
      (new CacheableMetadata())->addCacheableDependency($date_format),
    );
  }

}
