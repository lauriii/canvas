<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch;

use Drupal\canvas_ssr\Fetch\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Answers components' fetch() calls during SSR.
 *
 * The fetch() bound in the realm calls this service. An implementation
 * decides which requests are allowed and how to answer them, such as with a
 * Drupal subrequest or a canned response.
 *
 * @see \Drupal\canvas_ssr\Fetch\SameSiteFetch
 */
interface FetchInterface {

  /**
   * Fetches a component's request.
   *
   * @param \Psr\Http\Message\RequestInterface $request
   *   The request as the component made it. Its URI may be absolute,
   *   root-relative or relative. The implementation resolves and checks it.
   *
   * @return \Drupal\canvas_ssr\Fetch\Message\ResponseInterface
   *   The response, with the URL requested and its cacheability.
   *
   * @throws \Drupal\canvas_ssr\Fetch\Exception\FetchRefusedException
   *   When the request is refused before any is made.
   * @throws \RuntimeException
   *   When there's no current request.
   */
  public function fetch(RequestInterface $request): ResponseInterface;

}
