<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Routing;

use Drupal\canvas_headless\PreviewTokenInspector;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Routes authenticated detached previews through a Canvas-owned route.
 */
final class DetachedPreviewRouteProcessor implements InboundPathProcessorInterface {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    return $this->isAuthenticatedDetachedPreview($request)
      ? CanvasContentApiRequest::API_PATH
      : $path;
  }

  /**
   * Whether this request carries authenticated detached preview context.
   */
  private function isAuthenticatedDetachedPreview(Request $request): bool {
    return $request->attributes->get(CanvasContentApiRequest::DETACHED_PREVIEW_ATTRIBUTE) === TRUE &&
      PreviewTokenInspector::hasPreviewScope($this->currentUser->getAccount());
  }

}
