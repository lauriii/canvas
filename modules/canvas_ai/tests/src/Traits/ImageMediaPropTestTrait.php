<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Traits;

use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;

/**
 * Sets up image props that are populated by a media item.
 */
trait ImageMediaPropTestTrait {

  use MediaTypeCreationTrait;
  use TestFileCreationTrait;
  use UserCreationTrait;

  /**
   * The error an agent gets for a media item the current user may not view.
   *
   * Canvas evaluates the media reference behind an image prop with the current
   * user's access rights, and reports the denial instead of the prop value.
   *
   * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator::validateAccess()
   */
  private const MEDIA_ACCESS_DENIED_MESSAGE = "Access denied to entity while evaluating expression, ℹ︎␜entity:media:image␝field_media_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}, reason: The 'view media' permission is required when the media item is published.";

  /**
   * Creates the `image` media type that image props are then matched against.
   *
   * Must be called before
   * \Drupal\canvas\ComponentSource\ComponentSourceManager::generateComponents():
   * each Component config entity stores the prop source shape computed when it
   * is generated, and it is the presence of an image media type that makes
   * that shape a reference to a media item rather than a plain image file.
   *
   * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
   */
  private function setUpImageMediaType(): void {
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
  }

  /**
   * Creates a user that may use Canvas AI but may not view media items.
   *
   * @return \Drupal\user\Entity\User
   *   The saved user account.
   */
  private function createUserWithoutMediaAccess(): User {
    $user = $this->createUser([CanvasAiPermissions::USE_CANVAS_AI]);
    if (!$user instanceof User) {
      throw new \Exception('Failed to create test user.');
    }
    return $user;
  }

  /**
   * Creates a published image media item.
   *
   * @return \Drupal\media\MediaInterface
   *   The saved media item.
   */
  private function createImageMedia(): MediaInterface {
    $image = $this->getTestFiles('image')[0];
    // @phpstan-ignore-next-line property.notFound
    $file = File::create(['uri' => $image->uri]);
    $file->setPermanent();
    $file->save();

    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Restricted image',
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => 'An image not every user may view',
      ],
    ]);
    $media->save();
    \assert($media instanceof MediaInterface);
    return $media;
  }

}
