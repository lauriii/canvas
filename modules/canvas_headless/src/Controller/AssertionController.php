<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\Exception\UndefinedLinkTemplateException;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mints fresh preview assertions for session activation and renewal.
 *
 * Two endpoints, one per renewal transport — the only two transports that
 * can reach the editor's Drupal session from the frontend app:
 *
 * - mint(): JSON, called by the Canvas editor's own JavaScript (same
 *   origin, session cookie present, CSRF-protected via the X-CSRF-Token
 *   header). The editor relays the assertion into the embedded app via
 *   postMessage, which renews the draft session in place — no document
 *   reload. The editor also calls this to mint the assertion for the
 *   iframe's initial activation URL.
 * - renew(): a top-level redirect for standalone tabs, where no embedding
 *   host exists to relay anything. A top-level navigation is exactly the
 *   request that still carries Drupal's SameSite=Lax session cookie, so the
 *   route can authenticate the editor, mint a preview URL for the path they
 *   were on, and bounce them straight back into the app.
 *
 * Both re-anchor renewal in the live Drupal session: they run as the
 * session's user, require the same permission as minting, and mint through
 * the same generator. Log out of Drupal and previews stop renewing when
 * the current token lapses — the Drupal session is the revocation boundary.
 *
 * The `path` query parameter is the session's entry point — wherever the
 * editor currently is in the frontend app. It is navigation-only by
 * design: access control lives entirely in the token, so accepting any
 * relative path grants nothing.
 */
class AssertionController extends ControllerBase {

  /**
   * The preview URL generator.
   *
   * @var \Drupal\canvas_headless\PreviewUrlGeneratorInterface
   */
  protected PreviewUrlGeneratorInterface $previewUrlGenerator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->previewUrlGenerator = $container->get(PreviewUrlGeneratorInterface::class);
    return $instance;
  }

  /**
   * Returns a fresh assertion as JSON, for the Canvas editor's JavaScript.
   *
   * Accepts either an entity (`entity_type` + `entity` query parameters —
   * used at activation, when the editor only knows what it is editing and
   * the server resolves the canonical path) or a `path` (used at renewal,
   * when the app reports wherever the editor currently is).
   */
  public function mint(Request $request): JsonResponse {
    $path = $this->resolvePath($request);
    // The host marks its in-place renewal lane: those assertions are
    // relayed into the embedded app over postMessage, so the grant demands
    // PKCE proof of the running session to redeem them. Activation and
    // recovery mints (no flag) load the app by URL and are redeemed
    // server-side instead.
    $renewal = $request->query->getBoolean('renewal');
    $assertion = $this->previewUrlGenerator->issueForPath(
      $path,
      $renewal,
      static::previewContext($request),
    );
    // The route requires the permission the generator checks, so a NULL here
    // means the two got out of sync — fail loudly, not with a broken preview.
    if ($assertion === NULL) {
      throw new BadRequestHttpException('No preview assertion can be minted for this account.');
    }

    return new JsonResponse(['assertion' => $assertion]);
  }

  /**
   * Redirects a standalone tab back into the app with a fresh session.
   */
  public function renew(Request $request): TrustedRedirectResponse {
    $path = static::validatedPath($request);
    $url = $this->previewUrlGenerator->generateForPath($path);
    if ($url === NULL) {
      throw new BadRequestHttpException('No preview URL can be generated for this account.');
    }

    // The destination is the *configured* frontend URL — the request only
    // chooses the entry path within the app, so a forged link cannot
    // redirect the editor anywhere the editor frame itself would not.
    return new TrustedRedirectResponse($url->toString());
  }

  /**
   * Resolves the session entry path from the request.
   *
   * The Canvas editor cannot know the edited entity's public path (the SPA
   * boots from a generic route; see CanvasPathProcessor), so activation
   * passes the entity instead and the path is resolved — and access-checked
   * — server-side.
   */
  protected function resolvePath(Request $request): string {
    $entity_type = (string) $request->query->get('entity_type', '');
    $entity_id = (string) $request->query->get('entity', '');
    if ($entity_type === '' && $entity_id === '') {
      return static::validatedPath($request);
    }

    try {
      $storage = $this->entityTypeManager()->getStorage($entity_type);
    }
    catch (PluginNotFoundException | InvalidPluginDefinitionException) {
      throw new BadRequestHttpException('Unknown entity type.');
    }
    $entity = $storage->load($entity_id);
    if ($entity === NULL) {
      throw new NotFoundHttpException('The entity does not exist.');
    }
    if (!$entity->access('view')) {
      throw new AccessDeniedHttpException('The entity may not be viewed.');
    }
    try {
      $path = $entity->toUrl()->toString(TRUE)->getGeneratedUrl();
    }
    catch (UndefinedLinkTemplateException) {
      throw new BadRequestHttpException('The entity has no canonical URL.');
    }

    // The claim becomes requestUri for the content endpoint, whose URL already
    // includes Drupal's base path. On a subdirectory install ("/cms"), the
    // generated canonical path includes that prefix too. Strip it so the claim
    // carries only the site-relative path.
    $base_path = $request->getBasePath();
    if ($base_path !== '' && str_starts_with($path, $base_path . '/')) {
      $path = substr($path, \strlen($base_path));
    }
    return $path;
  }

  /**
   * Reads and validates the `path` query parameter.
   *
   * Must be a relative path: an absolute or protocol-relative URL here
   * would let a crafted link turn the redirect into an open redirect (and
   * would be meaningless as a session entry point anyway).
   */
  protected static function validatedPath(Request $request): string {
    $path = (string) $request->query->get('path', '');
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
      throw new BadRequestHttpException('The path query parameter must be a relative path.');
    }
    return $path;
  }

  /**
   * Reads optional content-template rendering context.
   *
   * @return array{viewMode?: string}
   *   The context carried by the signed preview assertion.
   */
  protected static function previewContext(Request $request): array {
    $context = [];
    $view_mode = (string) $request->query->get('view_mode', '');
    if ($view_mode !== '') {
      if (preg_match('/^[a-z0-9_]+$/', $view_mode) !== 1) {
        throw new BadRequestHttpException('The view_mode query parameter is invalid.');
      }
      $context['viewMode'] = $view_mode;
    }
    return $context;
  }

}
