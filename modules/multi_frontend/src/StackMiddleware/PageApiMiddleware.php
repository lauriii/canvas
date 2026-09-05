<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\StackMiddleware;

use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\multi_frontend\Render\EnvelopeMainContentRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Serves /page-api/{path} as an alias for the envelope format.
 *
 * Issue 3506337 names /page-api/{path}, and a prefix is easier for a front
 * end to configure than a header, so the name survives. The mechanism
 * underneath is the wrapper format, not a catch-all route: this rewrites the
 * request and re-dispatches it, exactly as lupus_decoupled_ce_api's
 * BackendApiRequest does for /ce-api, so the inner path resolves through
 * ordinary routing with its own access checks.
 */
final class PageApiMiddleware implements HttpKernelInterface {

  public const PREFIX = '/page-api';

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    $path = $request->getPathInfo();
    if ($path !== self::PREFIX && !str_starts_with($path, self::PREFIX . '/')) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $inner_path = substr($path, strlen(self::PREFIX));
    if ($inner_path === '') {
      $inner_path = '/';
    }
    $query = $request->query->all();
    $query[MainContentViewSubscriber::WRAPPER_FORMAT] = EnvelopeMainContentRenderer::FORMAT;

    $query_string = http_build_query($query);

    // Mutate the duplicate's server bag rather than passing $server to
    // duplicate(): Symfony rebuilds the whole header bag from $_SERVER when
    // given one, which silently reverts anything an outer middleware set or
    // removed on the original request.
    $rewritten = $request->duplicate($query);
    $rewritten->server->set('REQUEST_URI', $request->getBaseUrl() . $inner_path . ($query_string === '' ? '' : '?' . $query_string));
    $rewritten->server->set('QUERY_STRING', $query_string);

    return $this->httpKernel->handle($rewritten, $type, $catch);
  }

}
