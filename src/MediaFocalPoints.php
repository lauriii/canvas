<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\crop\Entity\Crop;
use Drupal\file\FileInterface;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\media\MediaInterface;
use Drupal\media\Plugin\media\Source\Image;

/**
 * Reads the focal point that the Focal Point module stores for an image.
 *
 * The Focal Point module stores one point per image file, in pixels, as a crop
 * entity. Image props that store focal points use it for images without a
 * focal point of their own.
 *
 * @see https://www.drupal.org/project/focal_point
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef::IMAGE_WITH_FOCAL_POINT
 *
 * @internal
 *
 * @drupalOptionalDependency crop
 *   Only used once `focal_point`, which requires it, is installed.
 */
final class MediaFocalPoints {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns a media item's focal point, in percent, if one is set.
   *
   * @param int|string $media_id
   *   The ID of an image media item.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   Receives what the result depends on, including when it is NULL: a focal
   *   point can be set later.
   *
   * @return array{x: int, y: int}|null
   *   The focal point, or NULL when the Focal Point module is not installed or
   *   the image has none.
   */
  public function get(int|string $media_id, RefinableCacheableDependencyInterface $cacheability): ?array {
    if (!$this->moduleHandler->moduleExists('focal_point')) {
      return NULL;
    }
    $settings = $this->configFactory->get('focal_point.settings');
    $cacheability->addCacheableDependency($settings);
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media instanceof MediaInterface || !$media->getSource() instanceof Image) {
      return NULL;
    }
    $cacheability->addCacheableDependency($media);
    $source_field = $media->getSource()->getConfiguration()['source_field'] ?? NULL;
    $item = \is_string($source_field) ? $media->get($source_field)->first() : NULL;
    $file = $item instanceof ImageItem ? $item->entity : NULL;
    $width = (int) ($item?->get('width')->getValue() ?? 0);
    $height = (int) ($item?->get('height')->getValue() ?? 0);
    if (!$file instanceof FileInterface || $width === 0 || $height === 0) {
      return NULL;
    }
    // A focal point added later creates a crop entity.
    $cacheability->addCacheTags(['crop_list']);
    $crop = Crop::findCrop((string) $file->getFileUri(), (string) $settings->get('crop_type'));
    if ($crop === NULL) {
      return NULL;
    }
    $cacheability->addCacheableDependency($crop);
    $position = $crop->position();
    return [
      'x' => (int) \round(\min(100, \max(0, $position['x'] / $width * 100))),
      'y' => (int) \round(\min(100, \max(0, $position['y'] / $height * 100))),
    ];
  }

}
