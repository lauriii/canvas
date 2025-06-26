<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Resource;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * Contains a set of XbResourceLink objects.
 *
 * Heavily inspired by \Drupal\jsonapi\JsonApiResource\LinkCollection.
 * The differences are:
 * - JsonApi LinkCollection requires a context while we don't here.
 * - Each link rel can hold an array of links in JsonApi, while we only allow one.
 * - Implements \Drupal\Core\Cache\CacheableDependencyInterface and
 * \Drupal\Core\Cache\RefinableCacheableDependencyInterface.
 *
 * @internal
 *
 * @see \Drupal\jsonapi\JsonApiResource\LinkCollection
 */
final class XbResourceLinkCollection implements \IteratorAggregate, CacheableDependencyInterface, RefinableCacheableDependencyInterface {

  use CacheableDependencyTrait;
  use RefinableCacheableDependencyTrait;

  /**
   * The links in the collection, keyed by unique strings.
   *
   * @var \Drupal\experience_builder\Resource\XbResourceLink[]
   */
  protected array $links;

  /**
   * XbResourceLinkCollection constructor.
   *
   * @param \Drupal\experience_builder\Resource\XbResourceLink[] $links
   *   An associated array of key names and XbResourceLink objects.
   */
  public function __construct(array $links) {
    assert(Inspector::assertAll(function ($key) {
      return static::validKey($key);
    }, array_keys($links)));
    assert(Inspector::assertAll(function ($link) {
      return $link instanceof XbResourceLink;
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
   * @return \ArrayIterator<\Drupal\experience_builder\Resource\XbResourceLink>
   */
  public function getIterator(): \ArrayIterator {
    return new \ArrayIterator($this->links);
  }

  /**
   * Gets a new XbResourceLinkCollection with the given link inserted.
   *
   * @param string $key
   *   A key for the link. If the key already exists and the link shares an
   *   href, link relation type and attributes with an existing link with that
   *   key, those links will be merged together.
   * @param \Drupal\experience_builder\Resource\XbResourceLink $new_link
   *   The link to insert.
   *
   * @return static
   *   A new XbResourceLinkCollection with the given link inserted or merged with the
   *   current set of links.
   */
  public function withLink(string $key, XbResourceLink $new_link): XbResourceLinkCollection {
    assert(static::validKey($key));
    $merged = $this->links;
    if (isset($merged[$key])) {
      if (XbResourceLink::compare($merged[$key], $new_link) === 0) {
        $merged[$key] = XbResourceLink::merge($merged[$key], $new_link);
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
   * Whether a link with the given key exists.
   *
   * @param string $key
   *   The key.
   *
   * @return bool
   *   TRUE if a link with the given key exist, FALSE otherwise.
   */
  public function hasLinkWithKey($key): bool {
    return array_key_exists($key, $this->links);
  }

  /**
   * Filters a XbResourceLinkCollection using the provided callback.
   *
   * @param callable $f
   *   The filter callback. The callback has the signature below.
   *
   * @code
   *   boolean callback(string $key, \Drupal\experience_builder\Resource\XbResourceLink $link))
   * @endcode
   *
   * @return XbResourceLinkCollection
   *   A new, filtered XbResourceLinkCollection.
   */
  public function filter(callable $f): XbResourceLinkCollection {
    $links = iterator_to_array($this);
    $filtered = array_reduce(array_keys($links), function ($filtered, $key) use ($links, $f) {
      if ($f($key, $links[$key])) {
        $filtered[$key] = $links[$key];
      }
      return $filtered;
    }, []);
    return new XbResourceLinkCollection($filtered);
  }

  /**
   * Merges two XbResourceLinkCollections.
   *
   * @param \Drupal\experience_builder\Resource\XbResourceLinkCollection $a
   *   The first link collection.
   * @param \Drupal\experience_builder\Resource\XbResourceLinkCollection $b
   *   The second link collection.
   *
   * @return \Drupal\experience_builder\Resource\XbResourceLinkCollection
   *   A new XbResourceLinkCollection with the links of both inputs.
   */
  public static function merge(XbResourceLinkCollection $a, XbResourceLinkCollection $b): XbResourceLinkCollection {
    $merged = new XbResourceLinkCollection([]);
    foreach ($a as $key => $link) {
      $merged = $merged->withLink($key, $link);
    }
    foreach ($b as $key => $link) {
      $merged = $merged->withLink($key, $link);
    }
    return $merged;
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
    return !is_numeric($key);
  }

  /**
   * @return array<string, string>
   *
   * @see https://jsonapi.org/format/#document-links
   */
  public function asArray(): array {
    return array_reduce($this->links, function (array $carry, XbResourceLink $link): array {
      $carry[$link->getLinkRelationType()] = $link->getHref();
      return $carry;
    }, []);
  }

}
