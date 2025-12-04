<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions;

/**
 * @internal
 */
interface PropExpressionInterface extends \Stringable {

  public static function fromString(string $representation): static;

}
