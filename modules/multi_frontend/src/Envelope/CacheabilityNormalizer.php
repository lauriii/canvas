<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Envelope;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;

/**
 * Puts cacheability on the wire in a form a consumer can act on.
 *
 * Tags and max-age port cleanly onto every framework and CDN: a tag maps to
 * revalidateTag, a Nitro purge, or a surrogate-key purge, and max-age maps to
 * a cache lifetime. Cache contexts do not port at all, because a context ID
 * is a Drupal plugin name that a client cannot evaluate, so they are
 * accompanied by "varies": the derived, portable conclusion Drupal draws from
 * them. That summary is deliberately conservative: an unrecognized context
 * means the response is not safe in a shared cache.
 */
final class CacheabilityNormalizer {


  /**
   * Contexts that vary only on things already in the URL.
   *
   * A shared cache keyed by URL already distinguishes these, so they neither
   * make a response private nor need an HTTP vary dimension.
   */
  private const URL_BORNE = ['url', 'route', 'request_format'];

  /**
   * Contexts that map onto an HTTP request header.
   */
  private const HEADER_BORNE = [
    'cookies' => 'cookie',
    'session' => 'cookie',
    'user' => 'cookie',
    'headers' => NULL,
    // Language negotiation is not necessarily URL-borne: it can come from a
    // header, a cookie, or the session. Treating it as URL-borne would let a
    // shared cache reuse one language's response for another with no Vary,
    // so it varies on Accept-Language. When negotiation is by cookie or
    // session, a cookie context is present too and makes the response
    // private anyway.
    'languages' => 'accept-language',
  ];

  /**
   * Contexts that are safe in a shared cache but vary on no HTTP dimension.
   *
   * These are constant for anonymous visitors, which is the audience a shared
   * cache serves. "public" here means exactly that: safe to store and reuse
   * for other anonymous requests matching the same URL and vary dimensions.
   */
  private const SHARED_SAFE = ['theme', 'timezone', 'ip'];

  /**
   * Normalizes cacheability for the wire.
   *
   * @return array{tags: string[], maxAge: int|null, contexts: string[], varies: array{public: bool, on: string[]}}
   *   The normalized cacheability.
   */
  public static function normalize(CacheableDependencyInterface $dependency): array {
    $max_age = $dependency->getCacheMaxAge();
    $contexts = $dependency->getCacheContexts();
    sort($contexts);
    $tags = $dependency->getCacheTags();
    sort($tags);

    return [
      'tags' => $tags,
      // Drupal's -1 means "cache permanently". Emitting the sentinel would be
      // read by a client as an HTTP max-age of minus one second, which is
      // exactly backwards, so it crosses as null.
      'maxAge' => $max_age === Cache::PERMANENT ? NULL : $max_age,
      'contexts' => $contexts,
      'varies' => self::varies($contexts),
    ];
  }

  /**
   * Derives the portable variation summary from Drupal cache contexts.
   *
   * @param string[] $contexts
   *   Cache context IDs.
   *
   * @return array{public: bool, on: string[]}
   *   Whether a shared cache may store the response, and the HTTP dimensions
   *   it varies on.
   */
  private static function varies(array $contexts): array {
    $public = TRUE;
    $on = [];
    foreach ($contexts as $context) {
      // A cache context ID is dot-hierarchical and may carry a colon-separated
      // parameter: "user.permissions", "url.path",
      // "languages:language_interface", "headers:X-Something". The family is
      // the first dot-segment of the part before the colon.
      $parts = explode(':', $context, 2);
      $parameter = $parts[1] ?? NULL;
      $root = explode('.', $parts[0])[0];
      if (\in_array($root, self::URL_BORNE, TRUE) || \in_array($root, self::SHARED_SAFE, TRUE)) {
        continue;
      }
      if (\array_key_exists($root, self::HEADER_BORNE)) {
        $header = self::HEADER_BORNE[$root] ?? $parameter;
        if ($header !== NULL) {
          $on[] = strtolower($header);
        }
        if ($root !== 'headers' && $root !== 'languages') {
          $public = FALSE;
        }
        continue;
      }
      // Anything this mapping does not know about is treated as private.
      // Being wrong in the permissive direction here would put one visitor's
      // response in front of another, so the default is the safe one, and the
      // honest consequence is that some cacheable responses are reported as
      // private until the mapping learns about their context.
      $public = FALSE;
    }
    $on = array_values(array_unique($on));
    sort($on);
    return ['public' => $public, 'on' => $on];
  }

}
