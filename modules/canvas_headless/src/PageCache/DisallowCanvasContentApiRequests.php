<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\PageCache;

use Drupal\canvas_headless\Controller\CanvasEntityController;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prevents Internal Page Cache from caching Canvas content API responses.
 *
 * These endpoints return legacy or json-render content based on client
 * capabilities. Internal Page Cache does not vary by the capabilities header,
 * so it could serve the wrong format. Dynamic Page Cache handles this variation
 * through the content format cache context.
 *
 * @see https://www.drupal.org/project/drupal/issues/2972483
 */
final class DisallowCanvasContentApiRequests implements RequestPolicyInterface {

  public function __construct(
    private readonly ?InboundPathProcessorInterface $languagePathProcessor = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function check(Request $request): ?string {
    // Page Cache runs before core decodes paths and strips language prefixes.
    // Normalize the path so this policy also matches language-prefixed endpoints.
    $path = urldecode($request->getPathInfo());
    if ($this->languagePathProcessor !== NULL) {
      $path = $this->languagePathProcessor->processInbound($path, $request->duplicate());
    }
    return \in_array($path, [
      CanvasContentApiRequest::API_PATH,
      CanvasEntityController::API_PATH,
    ], TRUE)
      ? self::DENY
      : NULL;
  }

}
