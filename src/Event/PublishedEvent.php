<?php

declare(strict_types=1);

namespace Drupal\canvas\Event;

use Drupal\Core\Entity\EntityInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after auto-saved changes were published successfully.
 *
 * Carries every entity the publish operation saved, config and content
 * alike, batched per publish request. Auto-saving never dispatches this
 * event; only publishing does, after the transaction has committed, so
 * subscribers observe persisted state. Consumers that need a wire-friendly
 * shape can use getEntityReferences().
 */
final class PublishedEvent extends Event {

  /**
   * @param list<\Drupal\Core\Entity\EntityInterface> $entities
   *   The published entities.
   */
  public function __construct(
    public readonly array $entities,
  ) {}

  /**
   * Returns wire-friendly references to the published entities.
   *
   * @return list<array{entityType: string, id: string, uuid: string|null, langcode: string}>
   *   One reference per published entity.
   */
  public function getEntityReferences(): array {
    $references = [];
    foreach ($this->entities as $entity) {
      \assert($entity instanceof EntityInterface);
      $references[] = [
        'entityType' => $entity->getEntityTypeId(),
        'id' => (string) $entity->id(),
        'uuid' => $entity->uuid(),
        'langcode' => $entity->language()->getId(),
      ];
    }
    return $references;
  }

  /**
   * Returns the cache tags this publish invalidated.
   *
   * The union of each published entity's cache-tags-to-invalidate, sorted and
   * de-duplicated. These are the tags a consumer that recorded per-page
   * cacheability can revalidate: publishing a component, for example,
   * publishes its config entity, whose tag every dependent page carries, so
   * this list drives indirect-dependency revalidation, not only the changed
   * entities' own pages.
   *
   * @return list<string>
   *   The invalidated cache tags.
   */
  public function getCacheTags(): array {
    $tags = [];
    foreach ($this->entities as $entity) {
      \assert($entity instanceof EntityInterface);
      $tags = [...$tags, ...$entity->getCacheTagsToInvalidate()];
    }
    $tags = \array_values(\array_unique($tags));
    \sort($tags);
    return $tags;
  }

}
