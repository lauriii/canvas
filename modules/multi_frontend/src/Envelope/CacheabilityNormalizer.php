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
   * Prefix of Drupal's tag-shaped internal sentinels, which are not tags.
   */
  private const SENTINEL_TAG_PREFIX = 'CACHE_MISS_IF_UNCACHEABLE_HTTP_METHOD:';


  /**
   * Contexts that vary only on things already in the URL.
   *
   * A shared cache keyed by URL already distinguishes these, so they neither
   * make a response private nor need an HTTP vary dimension.
   */
  private const URL_BORNE = ['url', 'route', 'request_format'];

  /**
   * Contexts that name an HTTP dimension. All of them are private.
   *
   * Naming the dimension helps a cache; it does not make the response
   * shareable. "headers" with no parameter hashes every request header,
   * Cookie and Authorization included. Language is here rather than treated
   * as URL-borne because negotiation is not necessarily in the URL, and the
   * earlier claim that account and session negotiation always add a cookie
   * context was wrong: core's user negotiator reads the account's preferred
   * language and its session negotiator reads the session, and neither adds
   * one.
   */
  private const HEADER_BORNE = [
    'cookies' => 'cookie',
    'session' => 'cookie',
    'user' => 'cookie',
    'headers' => NULL,
    'languages' => 'accept-language',
  ];

  /**
   * Contexts that are safe in a shared cache and vary on no HTTP dimension.
   *
   * Deliberately empty. An earlier version listed "theme", "timezone" and
   * "ip", reasoning that they are constant for anonymous visitors. None of
   * them is: the timezone context is the current account's timezone, the
   * active theme can be negotiated on a permission, and the ip context is per
   * client by definition. Being wrong in the permissive direction here puts
   * one visitor's response in front of another, so this list holds only what
   * can be defended, and nothing yet can.
   *
   * @var string[]
   */
  private const SHARED_SAFE = [];

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
    // Drupal uses tag-shaped sentinels internally that are not cache tags at
    // all, and one of them rides along on every form. Emitting
    // it would put a value a CDN cannot purge into the one header a CDN acts
    // on, which is the same mistake as emitting -1 for max-age.
    // @see \Drupal\Core\Render\RenderCache::isElementCacheable()
    $tags = \array_values(\array_filter(
      $dependency->getCacheTags(),
      static fn (string $tag): bool => !\str_starts_with($tag, self::SENTINEL_TAG_PREFIX),
    ));
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
        // Naming the dimension is not the same as making the response
        // shareable: two visitors sending the same Accept-Language can still
        // be owed different responses when language comes from their account.
        $public = FALSE;
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
