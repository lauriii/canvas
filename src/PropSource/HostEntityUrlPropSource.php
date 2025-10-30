<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\canvas\MissingHostEntityException;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Prop source that is used to generate a URL to the host entity.
 *
 * Conceptual sibling of DynamicPropSource, but:
 * - DynamicPropSource retrieves information from structured data in the host
 *   entity (aka a field on the host entity)
 * - this generates a URL to the host entity.
 *
 * @see \Drupal\canvas\PropSource\DynamicPropSource
 *
 * @phpstan-import-type HostEntityUrlPropSourceArray from PropSourceBase
 * @internal
 */
final class HostEntityUrlPropSource extends PropSourceBase {

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
    \assert($prop_source === ['sourceType' => PropSource::HostEntityUrl->value]);
    return new self();
  }

  public function evaluate(?FieldableEntityInterface $host_entity, bool $is_required): mixed {
    if ($host_entity === NULL) {
      throw new MissingHostEntityException();
    }

    // @todo Allow picking `canonical` vs `edit-form` vs … ? in
    //   https://www.drupal.org/i/3551455.
    return $host_entity->toUrl('canonical')
      // Absolute URLs are accepted by both `type: string, format: uri` and
      // `format: uri-reference`. Relative URLs are only accepted by the latter.
      // @todo Allow specifying relative or absolute in
      //   https://www.drupal.org/i/3551455.
      ->setAbsolute()
      ->toString(TRUE)
      ->getGeneratedUrl();
  }

  public function asChoice(): string {
    // @todo Account for the two likely future parameters mentioned in
    //   ::evaluate() in https://www.drupal.org/i/3551455.
    return PropSource::HostEntityUrl->value . ':absolute:canonical';
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    // This prop source has no internal dependencies apart from the host entity
    // itself, which is always passed into ::evaluate() by the calling code.
    return [];
  }

  /**
   * @todo Generate appropriate labels depending on link relation type (and possibly based on relative vs absolute) in https://www.drupal.org/i/3551455
   */
  public function label(): TranslatableMarkup {
    return new TranslatableMarkup('Canonical absolute URL', []);
  }

}
