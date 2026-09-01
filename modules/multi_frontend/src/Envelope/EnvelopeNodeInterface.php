<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Envelope;

use Drupal\Core\Cache\CacheableDependencyInterface;

/**
 * One node of an envelope.
 *
 * The union is closed and has exactly two members: a produced component whose
 * props are validated against a published schema, and rendered markup for
 * anything not converted yet. A consumer can branch on the type exhaustively,
 * which is the property that makes generated types worth having.
 *
 * @see \Drupal\multi_frontend\Envelope\ComponentNode
 * @see \Drupal\multi_frontend\Envelope\HtmlNode
 */
interface EnvelopeNodeInterface extends CacheableDependencyInterface {

  /**
   * Returns the node as a plain, JSON-serializable array.
   *
   * @return array<string, mixed>
   *   The node.
   */
  public function toArray(): array;

}
