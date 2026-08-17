<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Plugin\SegmentCondition;

use Drupal\canvas_personalization\Attribute\SegmentCondition;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Matches one or more UTM tracking query parameters.
 *
 * @phpstan-type UtmParameterSetting array{key: string, value: string, matching: 'exact'|'starts_with'}
 */
#[SegmentCondition(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('UTM parameters'),
)]
final class UtmParameters extends SegmentConditionBase {

  public const string PLUGIN_ID = 'utm_parameters';

  public const string UTM_ID = 'utm_id';
  public const string UTM_SOURCE = 'utm_source';
  public const string UTM_MEDIUM = 'utm_medium';
  public const string UTM_CAMPAIGN = 'utm_campaign';
  public const string UTM_TERM = 'utm_term';
  public const string UTM_CONTENT = 'utm_content';
  public const string CUSTOM = 'custom';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'parameters' => [],
      'all' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function doEvaluate(): bool {
    $parameters = $this->configuration['parameters'];
    if (empty($parameters)) {
      return TRUE;
    }
    $query = $this->getRequest()->query;
    $matches = \array_map(
      function (array $parameter) use ($query): bool {
        $actual = $query->getString($parameter['key']);
        return match ($parameter['matching']) {
          'exact' => $parameter['value'] === $actual,
          'starts_with' => $actual !== '' && str_starts_with($actual, $parameter['value']),
          default => throw new \LogicException(\sprintf('Unknown matching for condition %s', $this->getPluginId())),
        };
      },
      $parameters,
    );
    return $this->configuration['all']
      ? !\in_array(FALSE, $matches, TRUE)
      : \in_array(TRUE, $matches, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return \array_values(\array_unique(\array_map(
      static fn (array $parameter): string => 'url.query_args:' . $parameter['key'],
      $this->configuration['parameters'],
    )));
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    $pairs = \implode(', ', \array_map(
      static fn (array $parameter): string => $parameter['key'] . '=' . $parameter['value'],
      $this->configuration['parameters'],
    ));
    return $this->configuration['all']
      ? $this->t('All UTM parameters match: @parameters', ['@parameters' => $pairs])
      : $this->t('Any UTM parameter matches: @parameters', ['@parameters' => $pairs]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $parameter_options = [
      self::UTM_ID => $this->t('ID', [], ['context' => 'UTM parameters']),
      self::UTM_SOURCE => $this->t('Source', [], ['context' => 'UTM parameters']),
      self::UTM_MEDIUM => $this->t('Medium', [], ['context' => 'UTM parameters']),
      self::UTM_CAMPAIGN => $this->t('Campaign', [], ['context' => 'UTM parameters']),
      self::UTM_TERM => $this->t('Term', [], ['context' => 'UTM parameters']),
      self::UTM_CONTENT => $this->t('Content', [], ['context' => 'UTM parameters']),
      self::CUSTOM => $this->t('Custom', [], ['context' => 'UTM parameters']),
    ];
    $form['new_parameter'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Add parameter'),
      '#tree' => TRUE,
      'key' => [
        '#type' => 'select',
        '#title' => $this->t('Parameter'),
        '#options' => $parameter_options,
      ],
      'custom_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('Custom parameter'),
        '#states' => [
          'visible' => [
            'select[name="settings[new_parameter][key]"]' => ['value' => self::CUSTOM],
          ],
        ],
      ],
      'value' => [
        '#type' => 'textfield',
        '#title' => $this->t('Value'),
        '#required' => TRUE,
      ],
      'matching' => [
        '#type' => 'select',
        '#title' => $this->t('Matching'),
        '#options' => [
          'exact' => $this->t('Exact match'),
          'starts_with' => $this->t('Starts with'),
        ],
        '#default_value' => 'exact',
      ],
    ];
    $form['all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('All parameters must match'),
      '#default_value' => $this->configuration['all'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $new = $form_state->getValue('new_parameter');
    $key = $new['key'] === self::CUSTOM ? $new['custom_key'] : $new['key'];
    $this->configuration['parameters'][] = [
      'key' => rawurlencode((string) $key),
      'value' => rawurlencode((string) $new['value']),
      'matching' => $new['matching'],
    ];
    $this->configuration['all'] = (bool) $form_state->getValue('all');
    parent::submitConfigurationForm($form, $form_state);
  }

}
