<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropExpressions\Component;

/**
 * For pointing to a prop in a component.
 */
final class ComponentPropExpression implements ComponentPropExpressionInterface {

  public function __construct(
    public readonly string $componentName,
    public readonly string $propName,
  ) {}

  public function __toString(): string {
    return sprintf(static::PREFIX . "%s␟%s", $this->componentName, $this->propName);
  }

  public static function fromString(string $representation): static {
    $parts = explode('␟', mb_substr($representation, 1));
    return new static(...$parts);
  }

}
