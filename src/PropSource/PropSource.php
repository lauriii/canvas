<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

use Drupal\Core\Entity\FieldableEntityInterface;

abstract class PropSource implements \Stringable {

  /**
   * @param array{sourceType: string, expression: string, value?: array<string, mixed>} $sdc_prop_source
   */
  abstract public static function parse(array $sdc_prop_source): static;

  abstract public function evaluate(FieldableEntityInterface $host_entity): mixed;

  abstract public function asChoice(): string;

}
