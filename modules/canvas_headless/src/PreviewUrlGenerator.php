<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;

/**
 * Generates draft-mode preview URLs carrying a signed assertion.
 *
 * The URL carries an RFC 7523 JWT assertion naming the initiating editor.
 * The frontend app exchanges the assertion at /oauth/token for an access
 * token bound to that editor, so the preview sees exactly what the editor's
 * own permissions allow — nothing to entitle, nothing to configure.
 *
 * The assertion also carries the draft *session* contract: a validated
 * entry path and a session-wide resource version policy. What the app
 * previews is determined by the entry path and the app's own routing;
 * access control lives entirely in the editor's permissions.
 */
class PreviewUrlGenerator implements PreviewUrlGeneratorInterface {

  /**
   * The resource version policy for the draft session.
   *
   * "rel:working-copy" resolves to the latest revision, which covers both
   * unpublished entities and forward revisions of published entities.
   */
  const RESOURCE_VERSION = 'rel:working-copy';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected AccountProxyInterface $currentUser,
    protected PreviewAssertionFactoryInterface $assertionFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function generateForPath(string $path): ?Url {
    $assertion = $this->issueForPath($path);
    if ($assertion === NULL) {
      return NULL;
    }

    $config = $this->configFactory->get('canvas_headless.settings');
    // The URL is served as a trusted redirect target carrying the assertion
    // in its query string, so it must be an unambiguous web URL — never an
    // executable scheme, never a host a browser would resolve differently
    // than PHP. The schema enforces this on save; this is the runtime
    // backstop for values that never went through validation. The canonical
    // base URL, not the raw setting, is what the assertion travels to.
    $frontend = FrontendUrl::fromConfig((string) $config->get('frontend_url'));
    if ($frontend === NULL) {
      return NULL;
    }
    return Url::fromUri($frontend->baseUrl . $config->get('draft_path'), [
      'query' => ['assertion' => $assertion],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function issueForPath(string $path, bool $renewal = FALSE): ?string {
    if (!$this->currentUser->hasPermission(PreviewUrlGeneratorInterface::PREVIEW_PERMISSION)) {
      return NULL;
    }

    return $this->assertionFactory->issue(
      $this->currentUser,
      $path,
      static::RESOURCE_VERSION,
      $renewal,
    );
  }

}
