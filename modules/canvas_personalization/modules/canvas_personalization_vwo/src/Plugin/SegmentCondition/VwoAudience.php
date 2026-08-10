<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_vwo\Plugin\SegmentCondition;

use Drupal\canvas_personalization\Attribute\SegmentCondition;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionBase;
use Drupal\canvas_personalization_vwo\VwoAudienceMembership;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Matches visitors VWO places in an audience.
 *
 * The audience is named by the VWO FME feature flag that models it; see
 * VwoFmeAudienceResolver for why an audience is a flag and not a segment.
 *
 * Cacheability: the result varies by the VWO identity cookie the visitor
 * carries, and stays valid for as long as the membership answer is cached, so
 * this declares `cookies:<name>` and a max-age of that TTL. Both are derived
 * from configuration alone, never from the request, as the condition contract
 * requires. The `cookies:` context is not a URL-derived one, so
 * SegmentAwareResponsePolicy keeps these responses out of the URL-keyed
 * internal page cache; they stay cacheable in dynamic_page_cache.
 *
 * @see \Drupal\canvas_personalization_vwo\VwoAudienceMembership
 * @see \Drupal\canvas_personalization\PageCache\SegmentAwareResponsePolicy
 */
#[SegmentCondition(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('VWO audience'),
)]
final class VwoAudience extends SegmentConditionBase {

  public const string PLUGIN_ID = 'vwo_audience';

  private VwoAudienceMembership $membership;

  /**
   * {@inheritdoc}
   *
   * SegmentConditionBase::create() instantiates `new static($configuration,
   * $plugin_id, $plugin_definition)`, so a condition needing services beyond
   * the three the base injects has to set them as properties afterwards
   * rather than take them as constructor arguments.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->membership = $container->get(VwoAudienceMembership::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'flag_key' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  protected function doEvaluate(): bool {
    // An unconfigured rule must not match everyone.
    if ($this->configuration['flag_key'] === '') {
      return FALSE;
    }
    return $this->membership->isInAudience($this->configuration['flag_key']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['cookies:' . $this->membership->getCookieName()];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->membership->getMembershipTtl();
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    return $this->t('VWO reports the visitor in the audience behind the @flag feature flag', [
      '@flag' => $this->configuration['flag_key'] !== '' ? $this->configuration['flag_key'] : $this->t('(unset)'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['flag_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('VWO feature flag key'),
      '#description' => $this->t('The key of the VWO FME feature flag whose targeting rule defines this audience. Credentials are read from site settings, never from here: segment rules are exported with site configuration and readable over the segment HTTP API.'),
      '#default_value' => $this->configuration['flag_key'],
      '#required' => TRUE,
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['flag_key'] = \trim((string) $form_state->getValue('flag_key'));
    parent::submitConfigurationForm($form, $form_state);
  }

}
