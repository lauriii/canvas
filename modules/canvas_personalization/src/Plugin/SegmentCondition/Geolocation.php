<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\Plugin\SegmentCondition;

use Drupal\canvas_personalization\Attribute\SegmentCondition;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionBase;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Matches the visitor's country and optionally region.
 *
 * Geolocation resolution belongs at the edge: this condition reads country and
 * region codes from request headers set by a CDN or reverse proxy. The header
 * names are configured in `canvas_personalization.settings`. An absent or
 * unknown header never matches — fail closed, never a wrong variant.
 */
#[SegmentCondition(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Geolocation'),
)]
final class Geolocation extends SegmentConditionBase {

  public const string PLUGIN_ID = 'geolocation';

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'countries' => [],
      'regions' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function doEvaluate(): bool {
    $headers = $this->getRequest()->headers;
    $country = \strtoupper((string) $headers->get($this->getSettings()->get('country_header'), ''));
    if ($country === '' || !\in_array($country, $this->configuration['countries'], TRUE)) {
      return FALSE;
    }
    if ($this->configuration['regions'] !== []) {
      $region = \strtoupper((string) $headers->get($this->getSettings()->get('region_header'), ''));
      return $region !== '' && \in_array($region, $this->configuration['regions'], TRUE);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    $contexts = ['headers:' . $this->getSettings()->get('country_header')];
    if ($this->configuration['regions'] !== []) {
      $contexts[] = 'headers:' . $this->getSettings()->get('region_header');
    }
    return $contexts;
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    if ($this->configuration['regions'] !== []) {
      return $this->t('Country is @countries and region is @regions', [
        '@countries' => \implode(', ', $this->configuration['countries']),
        '@regions' => \implode(', ', $this->configuration['regions']),
      ]);
    }
    return $this->t('Country is @countries', [
      '@countries' => \implode(', ', $this->configuration['countries']),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['countries'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Countries'),
      '#description' => $this->t('Comma-separated ISO 3166-1 alpha-2 country codes, e.g. "BE, NL". Matches any of them.'),
      '#default_value' => \implode(', ', $this->configuration['countries']),
      '#required' => TRUE,
    ];
    $form['regions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Regions'),
      '#description' => $this->t('Optional comma-separated region codes, e.g. "CO, MA". When set, the region must match too.'),
      '#default_value' => \implode(', ', $this->configuration['regions']),
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $split = static fn (string $value): array => \array_values(\array_filter(\array_map(
      static fn (string $code): string => \strtoupper(\trim($code)),
      \explode(',', $value),
    ), static fn (string $code): bool => $code !== ''));
    $this->configuration['countries'] = $split((string) $form_state->getValue('countries'));
    $this->configuration['regions'] = $split((string) $form_state->getValue('regions'));
    parent::submitConfigurationForm($form, $form_state);
  }

  private function getSettings(): ImmutableConfig {
    return $this->configFactory->get('canvas_personalization.settings');
  }

}
