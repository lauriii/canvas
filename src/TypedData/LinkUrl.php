<?php

declare(strict_types=1);

namespace Drupal\experience_builder\TypedData;

use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\Core\TypedData\Type\UriInterface;
use Drupal\link\LinkItemInterface;

/**
 * Defines a link URL computed value.
 *
 * Resolves e.g. `entity:node/3` to `/node/3` or `/subdir/node/3`, which is a
 * URL that a browser understands.
 */
final class LinkUrl extends StringData implements UriInterface {

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    $item = $this->getParent();
    \assert($item instanceof LinkItemInterface);
    $uri = $item->get('uri')->getValue();
    if (\parse_url($uri, PHP_URL_SCHEME) === NULL) {
      // We cannot use Url::fromUri without a scheme, return the URI as is.
      return $uri;
    }
    return $item->getUrl()->toString();
  }

  public function getCastedValue(): string {
    return $this->getValue();
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    // We don't support setting a value here.
    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

}
