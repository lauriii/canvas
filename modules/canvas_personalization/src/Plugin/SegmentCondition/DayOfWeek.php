<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Plugin\SegmentCondition;

use Drupal\canvas_personalization\Attribute\SegmentCondition;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Matches the current day of week in the site's default timezone.
 *
 * The site default timezone is used deliberately — not the visitor's — so the
 * result depends only on time, never on request context: this condition
 * declares no cache contexts and a max-age of the seconds remaining until the
 * next midnight, when its result can next change.
 */
#[SegmentCondition(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Day of week'),
)]
final class DayOfWeek extends SegmentConditionBase {

  public const string PLUGIN_ID = 'day_of_week';

  public const array DAYS = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
  ];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'days' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function doEvaluate(): bool {
    return \in_array(\strtolower($this->now()->format('l')), $this->configuration['days'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return self::secondsUntilNextMidnight($this->now());
  }

  /**
   * Computes the seconds from $now until the next midnight in its timezone.
   *
   * Pure so it is unit-testable; DST transitions are handled by letting PHP
   * resolve "tomorrow midnight" in the given timezone.
   */
  public static function secondsUntilNextMidnight(\DateTimeImmutable $now): int {
    $next_midnight = $now->modify('tomorrow midnight');
    return \max(1, $next_midnight->getTimestamp() - $now->getTimestamp());
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    return $this->t('Day of week is @days', [
      '@days' => \implode(', ', \array_map('ucfirst', $this->configuration['days'])),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['days'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Days'),
      '#options' => [
        'monday' => $this->t('Monday'),
        'tuesday' => $this->t('Tuesday'),
        'wednesday' => $this->t('Wednesday'),
        'thursday' => $this->t('Thursday'),
        'friday' => $this->t('Friday'),
        'saturday' => $this->t('Saturday'),
        'sunday' => $this->t('Sunday'),
      ],
      '#default_value' => $this->configuration['days'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['days'] = \array_values(\array_filter((array) $form_state->getValue('days')));
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * The current time in the site's default timezone.
   */
  private function now(): \DateTimeImmutable {
    $configured = $this->configFactory->get('system.date')->get('timezone.default');
    $timezone = new \DateTimeZone(\is_string($configured) && $configured !== '' ? $configured : 'UTC');
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($timezone);
  }

}
