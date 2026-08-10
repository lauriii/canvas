<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_vwo_test;

use Drupal\canvas_personalization_vwo\VwoAudienceResolverInterface;
use Drupal\Core\State\StateInterface;

/**
 * A VWO resolver scripted through state, standing in for a real account.
 *
 * Set `canvas_personalization_vwo_test.behavior` to:
 * - a map of visitor UUID to the flag keys that visitor is in, where the key
 *   `*` stands for every visitor;
 * - the string `throw` to simulate an unreachable or erroring VWO;
 * - the string `hang` to simulate a provider slower than the timeout.
 *
 * `canvas_personalization_vwo_test.calls` counts resolver invocations, so
 * tests can prove the membership cache and the negative cache actually stop
 * the provider being consulted.
 */
final class StubVwoAudienceResolver implements VwoAudienceResolverInterface {

  public const string BEHAVIOR_KEY = 'canvas_personalization_vwo_test.behavior';
  public const string CALLS_KEY = 'canvas_personalization_vwo_test.calls';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isInAudience(string $flag_key, string $visitor_uuid): bool {
    $this->state->set(self::CALLS_KEY, (int) $this->state->get(self::CALLS_KEY, 0) + 1);
    $behavior = $this->state->get(self::BEHAVIOR_KEY, []);
    if ($behavior === 'throw') {
      throw new \RuntimeException('VWO is unreachable.');
    }
    if ($behavior === 'hang') {
      // What a real client does once its hard timeout expires.
      throw new \RuntimeException('Connection timed out after 2000 milliseconds.');
    }
    if (!\is_array($behavior)) {
      return FALSE;
    }
    $flags = $behavior[$visitor_uuid] ?? $behavior['*'] ?? [];
    return \in_array($flag_key, $flags, TRUE);
  }

}
