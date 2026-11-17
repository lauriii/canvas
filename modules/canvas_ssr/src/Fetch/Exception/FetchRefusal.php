<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch\Exception;

/**
 * Why the same-site fetch refused a request.
 *
 * Each case is one rule a request must pass before any subrequest is made.
 *
 * @see \Drupal\canvas_ssr\Fetch\Exception\FetchRefusedException
 */
enum FetchRefusal {

  // The method isn't GET or HEAD.
  case MethodNotAllowed;

  // The URL is empty.
  case EmptyUrl;

  // The URL doesn't parse.
  case InvalidUrl;

  // The URL's scheme or host isn't the current request's.
  case CrossSiteUrl;

  // No path policy allows the URL's system path.
  case PathNotAllowed;

}
