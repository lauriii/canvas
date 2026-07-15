<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Core\Url;

/**
 * Generates draft-mode preview URLs carrying a signed assertion.
 */
interface PreviewUrlGeneratorInterface {

  /**
   * The permission gating preview minting and redemption.
   *
   * Minting checks it (this generator and the module's routes) and the grant
   * re-checks it at redemption, so it must name the same permission
   * everywhere; a single source keeps the mint and redeem gates in step.
   */
  const PREVIEW_PERMISSION = 'access canvas headless preview';

  /**
   * The path on the frontend that enables draft mode.
   *
   * This is a fixed convention of the adapter contract, not configuration:
   * every framework adapter (and the documentation custom adapters follow)
   * mounts the draft session routes at this path, so a configurable value
   * could only break the exchange.
   */
  const DRAFT_PATH = '/api/draft';

  /**
   * Generates a preview URL whose session enters at the given path.
   *
   * Used by session renewal, where the entry point is wherever the editor
   * currently is in the frontend app, not an entity's canonical path.
   *
   * @param string $path
   *   The session entry path. Navigation only — access control lives
   *   entirely in the token the assertion redeems for.
   *
   * @return \Drupal\Core\Url|null
   *   The preview URL, or NULL when the current user may not preview.
   */
  public function generateForPath(string $path): ?Url;

  /**
   * Mints a bare preview assertion whose session enters at the given path.
   *
   * The Canvas editor's renewal protocol delivers this assertion to the
   * embedded app via postMessage instead of a URL, so the app can renew its
   * session in place without a document reload.
   *
   * @param string $path
   *   The session entry path.
   * @param bool $renewal
   *   TRUE for the in-place renewal lane; see
   *   \Drupal\canvas_headless\PreviewAssertionFactoryInterface::issue().
   *
   * @return string|null
   *   The serialized, signed JWT, or NULL when the current user may not
   *   preview.
   */
  public function issueForPath(string $path, bool $renewal = FALSE): ?string;

}
