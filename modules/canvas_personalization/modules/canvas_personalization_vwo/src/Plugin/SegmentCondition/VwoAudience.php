<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_vwo\Plugin\SegmentCondition;

use Drupal\canvas_personalization\Attribute\SegmentCondition;
use Drupal\canvas_personalization\SegmentCondition\ExternalSegmentConditionBase;
use Drupal\canvas_personalization_vwo\VwoAudienceResolverInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Matches visitors VWO places in an audience.
 *
 * The audience is named by the VWO FME feature flag that models it; see
 * VwoFmeAudienceResolver for why an audience is a flag and not a segment.
 *
 * The membership cache, the negative cache and the fail-closed behavior all
 * come from ExternalSegmentConditionBase; this class only supplies the two
 * VWO-specific halves — where the identity comes from, and how to ask VWO —
 * plus the cacheability its identity implies.
 *
 * @see \Drupal\canvas_personalization\SegmentCondition\ExternalSegmentConditionBase
 * @see \Drupal\canvas_personalization_vwo\VwoFmeAudienceResolver
 */
#[SegmentCondition(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('VWO audience'),
)]
final class VwoAudience extends ExternalSegmentConditionBase {

  public const string PLUGIN_ID = 'vwo_audience';

  /**
   * VWO's own SDKs accept exactly this shape for a browser-issued UUID.
   *
   * @see https://github.com/wingify/wingify-fme-php-sdk/blob/master/src/Utils/UuidUtil.php
   */
  private const string VISITOR_UUID_PATTERN = '/^[DJ][0-9A-Fa-f]{32}$/';

  private VwoAudienceResolverInterface $resolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->resolver = $container->get(VwoAudienceResolverInterface::class);
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
  protected function getVisitorIdentity(): ?string {
    $cookie = $this->getRequest()->cookies->get($this->getCookieName());
    return \is_string($cookie) ? self::parseVisitorUuid($cookie) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveMembership(string $identity): bool {
    // An unconfigured rule must not match everyone, and must not cost a call.
    if ($this->configuration['flag_key'] === '') {
      return FALSE;
    }
    return $this->resolver->isInAudience($this->configuration['flag_key'], $identity);
  }

  /**
   * Extracts the visitor UUID from a raw VWO identity cookie value.
   *
   * VWO's SmartCode writes two pipe-separated fields — the visitor UUID and a
   * hash — and writes the pipe raw while its own read path tolerates a
   * percent-encoded one, so both are accepted here. A value that does not
   * carry a UUID in VWO's documented shape returns NULL rather than being
   * forwarded: VWO's SDKs reject it anyway, and guessing is how a wrong
   * variant gets served.
   *
   * Pure and static so the parsing is unit-testable without a container.
   */
  public static function parseVisitorUuid(string $cookie_value): ?string {
    $candidate = \explode('|', \rawurldecode($cookie_value))[0];
    return \preg_match(self::VISITOR_UUID_PATTERN, $candidate) === 1 ? $candidate : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['cookies:' . $this->getCookieName()];
  }

  /**
   * {@inheritdoc}
   */
  protected function getMembershipTtl(): int {
    return \max(1, (int) $this->settings()->get('membership_ttl'));
  }

  /**
   * {@inheritdoc}
   */
  protected function getFailureTtl(): int {
    return \max(1, (int) $this->settings()->get('failure_ttl'));
  }

  /**
   * The cookie name VWO writes the visitor identity to.
   *
   * Configurable because VWO accounts created from 2026-06-14 write
   * `_wingify_uuid_v2` instead, and an account may carry a cookie prefix.
   */
  public function getCookieName(): string {
    $name = $this->settings()->get('cookie_name');
    return \is_string($name) && $name !== '' ? $name : '_vwo_uuid_v2';
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

  private function settings(): ImmutableConfig {
    return $this->configFactory->get('canvas_personalization_vwo.settings');
  }

}
