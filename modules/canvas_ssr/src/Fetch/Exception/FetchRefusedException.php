<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch\Exception;

/**
 * A fetch refused before any subrequest was made.
 *
 * The message is what the component's fetch() rejects with. The reason
 * says which rule refused it, for code that needs to tell them apart.
 *
 * @see \Drupal\canvas_ssr\Fetch\FetchInterface::fetch()
 */
final class FetchRefusedException extends \InvalidArgumentException {

  /**
   * Constructs a FetchRefusedException.
   *
   * @param \Drupal\canvas_ssr\Fetch\Exception\FetchRefusal $reason
   *   Which rule refused the fetch.
   * @param string $message
   *   Why, in words.
   */
  public function __construct(
    public readonly FetchRefusal $reason,
    string $message,
  ) {
    parent::__construct($message);
  }

}
