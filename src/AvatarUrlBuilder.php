<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\UserInterface;

/**
 * Builds the avatar URL Canvas shows for a user.
 *
 * @internal
 */
final class AvatarUrlBuilder {

  public const string IMAGE_STYLE = 'canvas_avatar';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Gets the URL of a user's picture, or NULL when the user has none.
   */
  public function build(UserInterface $user): ?string {
    if (!$user->hasField('user_picture') || $user->get('user_picture')->isEmpty()) {
      return NULL;
    }
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $user->get('user_picture')->entity;
    if ($file === NULL) {
      return NULL;
    }
    $uri = $file->getFileUri();
    if ($uri === NULL) {
      return NULL;
    }
    $image_style = $this->entityTypeManager->getStorage('image_style')->load(self::IMAGE_STYLE);
    if (!$image_style instanceof ImageStyle || !$image_style->supportsUri($uri)) {
      return $this->fileUrlGenerator->generateString($uri);
    }
    return $image_style->buildUrl($uri);
  }

}
