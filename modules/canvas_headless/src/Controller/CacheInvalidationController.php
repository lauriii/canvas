<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hands a frontend the cache tags announced to it, once.
 *
 * @see \Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator
 */
final class CacheInvalidationController {

  public function __construct(
    private readonly FrontendCacheTagInvalidator $frontendCacheTagInvalidator,
  ) {}

  /**
   * Returns and forgets the tags announced under the `id` in the JSON body.
   *
   * The ID travels in the body, not the URL, so access logs never record it.
   */
  public function __invoke(Request $request): JsonResponse {
    $id = json_decode($request->getContent(), TRUE)['id'] ?? NULL;
    // Single use: a replayed ID finds nothing.
    $tags = \is_string($id) ? $this->frontendCacheTagInvalidator->redeem($id) : NULL;
    if ($tags === NULL) {
      throw new NotFoundHttpException();
    }
    return new JsonResponse(['tags' => $tags], headers: ['Cache-Control' => 'no-store']);
  }

}
