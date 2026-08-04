<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\image\Entity\ImageStyle;
use Drupal\user\UserInterface;

/**
 * Builds avatar image URLs for users referenced in API payloads.
 *
 * The using class must have an $entityTypeManager property and a
 * $fileUrlGenerator property.
 */
trait BuildsAvatarUrlTrait {

  /**
   * Gets the URL to a user's avatar, or NULL when they have none.
   */
  private function buildAvatarUrl(UserInterface $owner): ?string {
    if (!$owner->hasField('user_picture') || $owner->get('user_picture')->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $owner->get('user_picture')->entity;
    if ($file === NULL) {
      return NULL;
    }
    $uri = $file->getFileUri();
    if ($uri === NULL) {
      return NULL;
    }
    $imageStyle = $this->entityTypeManager->getStorage('image_style')->load(ApiAutoSaveController::AVATAR_IMAGE_STYLE);
    if (!$imageStyle instanceof ImageStyle || !$imageStyle->supportsUri($uri)) {
      return $this->fileUrlGenerator->generateString($uri);
    }
    return $imageStyle->buildUrl($uri);
  }

}
