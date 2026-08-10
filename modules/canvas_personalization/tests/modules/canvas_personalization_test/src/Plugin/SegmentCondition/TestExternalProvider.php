<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_test\Plugin\SegmentCondition;

use Drupal\canvas_personalization\Attribute\SegmentCondition;
use Drupal\canvas_personalization\SegmentCondition\ExternalSegmentConditionBase;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A scriptable third-party provider, for testing the shared degradation policy.
 *
 * The identity comes from a cookie and the answer from state, so a test can
 * drive every path ExternalSegmentConditionBase is responsible for — missing
 * identity, cache hit, provider failure, negative cache — without a real
 * provider.
 */
#[SegmentCondition(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Test external provider'),
)]
final class TestExternalProvider extends ExternalSegmentConditionBase {

  public const string PLUGIN_ID = 'test_external_provider';

  /**
   * Flag keys the visitor in `identity` is a member of, or 'throw'.
   */
  public const string BEHAVIOR_KEY = 'canvas_personalization_test.provider_behavior';

  /**
   * How often the provider was actually consulted.
   */
  public const string CALLS_KEY = 'canvas_personalization_test.provider_calls';

  public const string COOKIE = 'canvas_test_provider';

  private StateInterface $state;

  /**
   * {@inheritdoc}
   *
   * Demonstrates the documented way a condition gets services of its own:
   * SegmentConditionBase::create() fixes the constructor signature, so extra
   * dependencies are assigned as properties after calling parent::create().
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getVisitorIdentity(): ?string {
    $cookie = $this->getRequest()->cookies->get(self::COOKIE);
    return \is_string($cookie) && $cookie !== '' ? $cookie : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveMembership(string $identity): bool {
    $this->state->set(self::CALLS_KEY, (int) $this->state->get(self::CALLS_KEY, 0) + 1);
    $behavior = $this->state->get(self::BEHAVIOR_KEY, []);
    if ($behavior === 'throw') {
      throw new \RuntimeException('The external segmentation provider is unreachable.');
    }
    return \is_array($behavior) && \in_array($identity, $behavior, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['cookies:' . self::COOKIE];
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    return $this->t('Membership in an external provider segment');
  }

}
