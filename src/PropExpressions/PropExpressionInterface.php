<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions;

interface PropExpressionInterface extends \Stringable {

  public static function fromString(string $representation): static;

}
