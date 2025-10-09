<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\canvas\MissingHostEntityException;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Prop source that is used to get a link to the host entity URL.
 *
 * @phpstan-import-type HostEntityUrlPropSourceArray from PropSourceBase
 *
 * @internal
 */
final class HostEntityUrlPropSource extends PropSourceBase {

  public static function getSourceTypePrefix(): string {
    return 'host-entity-url';
  }

  public function getSourceType(): string {
    return self::getSourceTypePrefix();
  }

  /**
   * @return HostEntityUrlPropSourceArray
   */
  public function toArray(): array {
    return [
      'sourceType' => $this->getSourceType(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $prop_source): static {
    \assert($prop_source === ['sourceType' => self::getSourceTypePrefix()]);
    return new self();
  }

  public function evaluate(?FieldableEntityInterface $host_entity, bool $is_required): mixed {
    if ($host_entity === NULL) {
      throw new MissingHostEntityException();
    }

    // @todo Allow picking `canonical` vs `edit-form` vs … ?
    return $host_entity->toUrl('canonical')
      // Absolute URLs are accepted by both `type: string, format: uri` and
      // `format: uri-reference`. Relative URLs are only accepted by the latter.
      // @todo Allow specifying relative or absolute?
      ->setAbsolute(TRUE)
      ->toString(TRUE)
      ->getGeneratedUrl();
  }

  public function asChoice(): string {
    // @todo Account for the two likely future parameters mentioned in ::evaluate().
    return self::getSourceTypePrefix() . ':absolute:canonical';
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    return [];
  }

}
