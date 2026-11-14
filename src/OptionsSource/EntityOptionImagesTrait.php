<?php

declare(strict_types=1);

namespace Drupal\canvas\OptionsSource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Finds the images of options that are entities.
 *
 * A class that uses this trait has an `$entityTypeManager` and a
 * `$fileUrlGenerator` property.
 *
 * @see \Drupal\canvas\OptionsSource\OptionsSourceWithImagesInterface
 */
trait EntityOptionImagesTrait {

  /**
   * Gets the images of entities.
   *
   * A media item gives its thumbnail. Any other entity gives its first image:
   * of an image field, or the thumbnail of a media item that it references.
   * Only what the current user can view counts: the entity, the field and the
   * media item.
   *
   * @param string $entity_type_id
   *   The entity type of the options.
   * @param list<string|int> $ids
   *   Entity IDs.
   *
   * @return array<string|int, string>
   *   Image URLs, keyed by the ID of each entity that has an image.
   */
  private function getEntityImages(string $entity_type_id, array $ids): array {
    // The image style that the media library shows its items with: Canvas
    // requires the Media Library module.
    $style = $this->entityTypeManager->getStorage('image_style')->load('media_library');
    $images = [];
    foreach ($this->entityTypeManager->getStorage($entity_type_id)->loadMultiple($ids) as $id => $entity) {
      $uri = $entity instanceof FieldableEntityInterface ? $this->getImageFile($entity)?->getFileUri() : NULL;
      if ($uri === NULL) {
        continue;
      }
      // An image style cannot scale every image, such as an SVG.
      $images[$id] = $style?->supportsUri($uri)
        ? $this->fileUrlGenerator->transformRelative($style->buildUrl($uri))
        : $this->fileUrlGenerator->generateString($uri);
    }
    return $images;
  }

  /**
   * Gets the file of the first image of an entity that the user can view.
   */
  private function getImageFile(FieldableEntityInterface $entity): ?FileInterface {
    if (!$entity->access('view')) {
      return NULL;
    }
    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      // The thumbnail of a media item is its first image field.
      $references_media = $definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'media';
      if ($definition->getType() !== 'image' && !$references_media) {
        continue;
      }
      $items = $entity->get($field_name);
      $referenced = $items->access('view') ? $items->entity : NULL;
      if ($referenced instanceof MediaInterface) {
        $referenced = $referenced->access('view') ? $referenced->get('thumbnail')->entity : NULL;
      }
      if ($referenced instanceof FileInterface) {
        return $referenced;
      }
    }
    return NULL;
  }

}
