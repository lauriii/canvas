<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * @phpstan-type PropSourceTypePrefix 'static'|'dynamic'|'adapter'
 * @phpstan-import-type PropSourceArray from PropSource
 * @phpstan-import-type AdaptedPropSourceArray from PropSource
 */
abstract class PropSourceBase implements \Stringable {

  const SOURCE_TYPE_PREFIX_SEPARATOR = ':';

  /**
   * @param PropSourceArray|AdaptedPropSourceArray $sdc_prop_source
   */
  abstract public static function parse(array $sdc_prop_source): static;

  abstract public function evaluate(?FieldableEntityInterface $host_entity): mixed;

  abstract public function asChoice(): string;

  abstract public static function getSourceTypePrefix(): string;

  abstract public function getSourceType(): string;

}
