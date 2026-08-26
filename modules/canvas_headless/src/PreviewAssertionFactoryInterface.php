<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Core\Session\AccountInterface;

/**
 * Mints signed preview assertions (RFC 7523 JWTs).
 */
interface PreviewAssertionFactoryInterface {

  /**
   * Issues a preview assertion for the given user and draft session.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The editor initiating the preview. The access token minted from this
   *   assertion is bound to this account.
   * @param string $path
   *   The validated entry path of the draft session.
   * @param string $resource_version
   *   The session-wide JSON:API resource version policy.
   * @param bool $renewal
   *   TRUE for the in-place renewal lane, whose assertions are relayed into
   *   the embedded app over postMessage and therefore pass through script
   *   context. The grant requires PKCE proof of the running session to
   *   redeem them; activation assertions (FALSE) travel in URLs and are
   *   redeemed server-side, never touching script context.
   * @param array{viewMode?: string} $preview_context
   *   Optional rendering context for a content-template preview.
   *
   * @return string
   *   The serialized, signed JWT.
   */
  public function issue(AccountInterface $user, string $path, string $resource_version, bool $renewal = FALSE, array $preview_context = []): string;

}
