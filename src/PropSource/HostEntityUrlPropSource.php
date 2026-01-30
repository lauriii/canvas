<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
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

  public readonly string $rel;

  public function __construct(
    public readonly bool $absolute,
  ) {
    // At the moment, only linking to the entity's canonical URL is supported.
    $this->rel = 'canonical';
  }

  /**
   * @return HostEntityUrlPropSourceArray
   */
  public function toArray(): array {
    return [
      'sourceType' => $this->getSourceType(),
      'absolute' => $this->absolute,
      // @todo Allow picking link templates other than `canonical`.
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $prop_source): static {
    \assert(
      isset($prop_source['sourceType']) &&
      $prop_source['sourceType'] === PropSource::getTypePrefix(self::class)
    );
    // Absolute URLs are the default.
    $absolute = $prop_source['absolute'] ?? TRUE;
    return new self($absolute);
  }

  public function evaluate(?FieldableEntityInterface $host_entity, bool $is_required): EvaluationResult {
    if ($host_entity === NULL) {
      throw new MissingHostEntityException();
    }
    $generated_url = $host_entity->toUrl($this->rel)
      ->setAbsolute($this->absolute)
      ->toString(collect_bubbleable_metadata: TRUE);
    return new EvaluationResult($generated_url->getGeneratedUrl(), $generated_url);
  }

  public function asChoice(): string {
    return implode(':', [
      PropSource::getTypePrefix($this),
      $this->absolute ? 'absolute' : 'relative',
      $this->rel,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
    // This prop source has no internal dependencies apart from the host entity
    // itself, which is always passed into ::evaluate() by the calling code.
    return [];
  }

  public function label(): TranslatableMarkup {
    return $this->absolute
      ? new TranslatableMarkup('Absolute URL')
      : new TranslatableMarkup('Relative URL');
  }

}
