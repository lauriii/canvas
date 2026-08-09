<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * The result of negotiating which case of a switch component instance applies.
 *
 * @internal
 *
 * ⚠️ This is highly experimental and *will* be refactored or even removed.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentSourceWithSwitchCasesInterface
 */
final class SwitchCaseNegotiation {

  /**
   * @param string|null $negotiatedCaseUuid
   *   The component instance UUID of the single case to render, or NULL when
   *   no case applies (nothing is rendered).
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability of the entire negotiation decision — it MUST cover
   *   every input the decision consulted or could consult under a different
   *   request context, no matter which case won.
   */
  public function __construct(
    public readonly ?string $negotiatedCaseUuid,
    public readonly CacheableMetadata $cacheability,
  ) {}

}
