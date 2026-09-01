<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Envelope;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts a response's cacheability where a CDN reads it.
 *
 * The body carries per-node cacheability, which is what a build tool needs to
 * map an invalidation back to the pages that used an entity. A shared cache
 * reads none of that, so the response-level union is also emitted as headers:
 * a surrogate key per cache tag, a Vary derived from the portable variation
 * summary, and a Cache-Control that says plainly whether the response may be
 * stored publicly.
 *
 * Surrogate-Key is one common spelling of this header. Other CDNs use other
 * names for the same idea, and a site maps it in one line of edge
 * configuration; the point is that the tags leave the building at all, which
 * is where both existing structured-output implementations stop.
 */
final class CacheabilityHeaders {

  public static function apply(Response $response, CacheableDependencyInterface $cacheability): void {
    $normalized = CacheabilityNormalizer::normalize($cacheability);

    if ($normalized['tags'] !== []) {
      $response->headers->set('Surrogate-Key', implode(' ', $normalized['tags']));
    }
    if ($normalized['varies']['on'] !== []) {
      $response->setVary($normalized['varies']['on'], FALSE);
    }
    if ($normalized['varies']['public']) {
      $response->setPublic();
      if ($normalized['maxAge'] !== NULL) {
        $response->setMaxAge($normalized['maxAge']);
        $response->setSharedMaxAge($normalized['maxAge']);
      }
    }
    else {
      $response->setPrivate();
      $response->headers->addCacheControlDirective('no-store');
    }
  }

}
