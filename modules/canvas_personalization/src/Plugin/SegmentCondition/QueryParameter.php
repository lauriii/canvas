<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Plugin\SegmentCondition;

use Drupal\canvas_personalization\Attribute\SegmentCondition;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Matches an arbitrary URL query parameter, e.g. coupon=BLACKFRIDAY.
 */
#[SegmentCondition(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Query parameter'),
)]
final class QueryParameter extends SegmentConditionBase {

  public const string PLUGIN_ID = 'query_parameter';

  public const string MATCHING_EXACT = 'exact';
  public const string MATCHING_STARTS_WITH = 'starts_with';
  public const string MATCHING_PRESENT = 'present';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'parameter' => '',
      'value' => '',
      'matching' => self::MATCHING_EXACT,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function doEvaluate(): bool {
    $query = $this->getRequest()->query;
    $parameter = $this->configuration['parameter'];
    if (!$query->has($parameter)) {
      return FALSE;
    }
    $actual = $query->getString($parameter);
    return match ($this->configuration['matching']) {
      self::MATCHING_PRESENT => TRUE,
      self::MATCHING_EXACT => $actual === $this->configuration['value'],
      self::MATCHING_STARTS_WITH => str_starts_with($actual, $this->configuration['value']),
      default => throw new \LogicException(\sprintf('Unknown matching for condition %s', $this->getPluginId())),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['url.query_args:' . $this->configuration['parameter']];
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    return match ($this->configuration['matching']) {
      self::MATCHING_PRESENT => $this->t('Query parameter @parameter is present', ['@parameter' => $this->configuration['parameter']]),
      self::MATCHING_STARTS_WITH => $this->t('Query parameter @parameter starts with @value', [
        '@parameter' => $this->configuration['parameter'],
        '@value' => $this->configuration['value'],
      ]),
      default => $this->t('Query parameter @parameter is @value', [
        '@parameter' => $this->configuration['parameter'],
        '@value' => $this->configuration['value'],
      ]),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['parameter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parameter'),
      '#default_value' => $this->configuration['parameter'],
      '#required' => TRUE,
    ];
    $form['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Value'),
      '#default_value' => $this->configuration['value'],
    ];
    $form['matching'] = [
      '#type' => 'select',
      '#title' => $this->t('Matching'),
      '#options' => [
        self::MATCHING_EXACT => $this->t('Exact match'),
        self::MATCHING_STARTS_WITH => $this->t('Starts with'),
        self::MATCHING_PRESENT => $this->t('Parameter is present'),
      ],
      '#default_value' => $this->configuration['matching'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['parameter'] = rawurlencode((string) $form_state->getValue('parameter'));
    $this->configuration['value'] = rawurlencode((string) $form_state->getValue('value'));
    $this->configuration['matching'] = (string) $form_state->getValue('matching');
    parent::submitConfigurationForm($form, $form_state);
  }

}
