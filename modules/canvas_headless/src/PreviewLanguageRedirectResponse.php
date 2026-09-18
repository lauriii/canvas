<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Core\Routing\LocalRedirectResponse;

/**
 * A content-API transport redirect, not a frontend navigation result.
 *
 * @internal
 */
final class PreviewLanguageRedirectResponse extends LocalRedirectResponse {

  public function __construct(private readonly string $transportTarget) {
    parent::__construct($transportTarget);
    $this->getCacheableMetadata()->setCacheMaxAge(0);
    $this->headers->set('Cache-Control', 'private, no-store');
  }

  /**
   * {@inheritdoc}
   */
  protected function isSafe($url): bool {
    // Core catches rejected SecuredRedirectResponse destination overrides.
    // Lock only this hop; do not disable destination for normal navigation.
    return $url === $this->transportTarget && parent::isSafe($url);
  }

}
