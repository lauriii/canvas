<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Core\Cache\CacheableJsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Represents RFC 9457 Problem Details for the Canvas content API.
 */
final class CanvasContentProblemResponse extends CacheableJsonResponse {

  /**
   * Constructs a problem response with optional detail and type.
   */
  public function __construct(
    int $status_code,
    ?string $detail = NULL,
    string $type = 'about:blank',
  ) {
    $data = [
      'type' => $type,
      'title' => Response::$statusTexts[$status_code] ?? 'Error',
      'status' => $status_code,
    ];
    if ($detail !== NULL) {
      $data['detail'] = $detail;
    }
    parent::__construct($data, $status_code);
    $this->headers->set('Content-Type', 'application/problem+json');
  }

}
