<?php

declare(strict_types=1);

namespace Drupal\canvas\Resource;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * Contains a set of CanvasResourceLink objects.
 *
 * Heavily inspired by \Drupal\jsonapi\JsonApiResource\LinkCollection.
 * The differences are:
 * - JsonApi LinkCollection requires a context while we don't here.
 * - Each link rel can hold an array of links in JsonApi, while we allow
 * only one.
 * - Implements \Drupal\Core\Cache\CacheableDependencyInterface and
 * \Drupal\Core\Cache\RefinableCacheableDependencyInterface.
 * - Omits the 3 methods Canvas does not use: hasLinkWithKey, filter, merge.
 *
 * @internal
 *
 * @see \Drupal\jsonapi\JsonApiResource\LinkCollection
 */
final class CanvasResourceLinkCollection implements \IteratorAggregate, CacheableDependencyInterface, RefinableCacheableDependencyInterface {

  use CacheableDependencyTrait;
  use RefinableCacheableDependencyTrait;

  /**
   * The links in the collection, keyed by unique strings.
   *
   * @var \Drupal\canvas\Resource\CanvasResourceLink[]
   */
  protected array $links;

  /**
   * CanvasResourceLinkCollection constructor.
   *
   * @param \Drupal\canvas\Resource\CanvasResourceLink[] $links
   *   An associated array of key names and CanvasResourceLink objects.
   */
  public function __construct(array $links) {
    \assert(Inspector::assertAll(function ($key) {
      return static::validKey($key);
    }, \array_keys($links)));
    \assert(Inspector::assertAll(function ($link) {
      return $link instanceof CanvasResourceLink;
    }, $links));
    ksort($links);
    $this->links = $links;
    foreach ($links as $link) {
      $this->addCacheableDependency($link);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @return \ArrayIterator<\Drupal\canvas\Resource\CanvasResourceLink>
   */
  public function getIterator(): \ArrayIterator {
    return new \ArrayIterator($this->links);
  }

  /**
   * Gets a new CanvasResourceLinkCollection with the given link inserted.
   *
   * @param string $key
   *   A key for the link. If the key already exists and the link shares an
   *   href, link relation type and attributes with an existing link with that
   *   key, those links will be merged together.
   * @param \Drupal\canvas\Resource\CanvasResourceLink $new_link
   *   The link to insert.
   *
   * @return static
   *   A new CanvasResourceLinkCollection with the given link inserted or
   *   merged with the current set of links.
   */
  public function withLink(string $key, CanvasResourceLink $new_link): CanvasResourceLinkCollection {
    \assert(static::validKey($key));
    $merged = $this->links;
    if (isset($merged[$key])) {
      if (CanvasResourceLink::compare($merged[$key], $new_link) === 0) {
        $merged[$key] = CanvasResourceLink::merge($merged[$key], $new_link);
      }
    }
    else {
      $merged[$key] = $new_link;
    }
    $collection = new static($merged);
    // We need to keep existing cache metadata added to the collection object
    // for e.g. absent links.
    $collection->addCacheTags($this->getCacheTags())
      ->addCacheContexts($this->getCacheContexts())
      ->mergeCacheMaxAge($this->getCacheMaxAge());
    return $collection;
  }

  /**
   * Ensures that a link key is valid.
   *
   * @param string $key
   *   A key name.
   *
   * @return bool
   *   TRUE if the key is valid, FALSE otherwise.
   */
  protected static function validKey(string $key): bool {
    return !\is_numeric($key);
  }

  /**
   * @return array<string, string>
   *
   * @see https://jsonapi.org/format/#document-links
   */
  public function asArray(): array {
    return array_reduce($this->links, function (array $carry, CanvasResourceLink $link): array {
      $carry[$link->getLinkRelationType()] = $link->getHref();
      return $carry;
    }, []);
  }

}
