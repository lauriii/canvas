<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization_vwo;

/**
 * Resolves whether one VWO visitor is in one VWO audience.
 *
 * This is the only place that talks to VWO. Everything policy-shaped — the
 * membership cache, the negative cache and the fail-closed behavior — comes
 * from ExternalSegmentConditionBase in canvas_personalization, so that a stub
 * resolver exercises all of it.
 *
 * Implementations are free to throw: callers treat any throwable as "not a
 * member" and negatively cache it.
 *
 * @see \Drupal\canvas_personalization\SegmentCondition\ExternalSegmentConditionBase
 * @see \Drupal\canvas_personalization_vwo\VwoFmeAudienceResolver
 */
interface VwoAudienceResolverInterface {

  /**
   * Whether the visitor belongs to the audience behind $flag_key.
   *
   * @param string $flag_key
   *   A VWO FME feature flag key modeling the audience.
   * @param string $visitor_uuid
   *   The VWO visitor UUID, already validated by the caller.
   *
   * @throws \Throwable
   *   When VWO could not be consulted.
   */
  public function isInAudience(string $flag_key, string $visitor_uuid): bool;

}
