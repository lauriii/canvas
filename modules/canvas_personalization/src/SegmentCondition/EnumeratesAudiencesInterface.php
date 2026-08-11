<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\SegmentCondition;

/**
 * Implemented by conditions that can list the audiences they can target.
 *
 * Without this, a third-party provider's audience is an opaque string an
 * author types by hand, and a typo is indistinguishable from an audience
 * nobody is in: the page renders, the default variant is served, and nothing
 * is logged or reported. Providers whose API can enumerate audiences implement
 * this so the authoring UI can offer a choice instead of a text field.
 *
 * Optional on purpose. Some providers genuinely cannot enumerate — the
 * identifier is a customer-defined key, or listing it needs a credential the
 * site does not hold — and those keep the plain text field.
 */
interface EnumeratesAudiencesInterface {

  /**
   * The audiences this condition can currently target.
   *
   * Implementations SHOULD answer from data they already cache rather than
   * making the authoring UI wait on the provider, MUST NOT throw, and SHOULD
   * return an empty array when the provider cannot be reached.
   *
   * An empty result means "nothing can be targeted right now", and a condition
   * implementing this MUST NOT fall back to letting the identifier be typed:
   * an identifier the provider does not recognise is indistinguishable at
   * runtime from an audience nobody belongs to, so a free-text fallback trades
   * a visible empty control for an invisible misconfiguration.
   *
   * @return array<string, string>
   *   Audience identifiers, as stored in the condition's configuration, mapped
   *   to human-readable labels.
   */
  public function listAudiences(): array;

}
