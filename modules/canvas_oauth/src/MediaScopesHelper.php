<?php

declare(strict_types=1);

namespace Drupal\canvas_oauth;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaTypeInterface;

/**
 * Helper for creating OAuth2 scopes for media types.
 */
final class MediaScopesHelper implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Creates OAuth2 scopes for all media types with the image source plugin.
   */
  public function ensureMediaImageScopes(): void {
    $dependencies = [
      'enforced' => [
        'module' => [
          'canvas_oauth',
        ],
      ],
    ];
    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    $oauth2_scope_storage = $this->entityTypeManager->getStorage('oauth2_scope');
    foreach ($media_types as $media_type) {
      \assert($media_type instanceof MediaTypeInterface);
      if ($media_type->getSource()->getPluginId() !== 'image') {
        continue;
      }
      $media_type_id = $media_type->id();
      $scope_id = 'canvas_media_' . $media_type_id . '_create';
      if ($oauth2_scope_storage->load($scope_id)) {
        continue;
      }
      $label = $media_type->label();
      $oauth2_scope_storage->create([
        'id' => $scope_id,
        'name' => 'canvas:media:' . $media_type_id . ':create',
        'description' => 'Drupal Canvas: Create ' . $label . ' media',
        'status' => TRUE,
        'grant_types' => [
          'authorization_code' => [
            'status' => TRUE,
            'description' => \sprintf('Authorization code access for creating %s media', $label),
          ],
          'refresh_token' => [
            'status' => TRUE,
            'description' => \sprintf('Refresh token access for creating %s media', $label),
          ],
          'client_credentials' => [
            'status' => TRUE,
            'description' => \sprintf('Client credentials access for creating %s media', $label),
          ],
        ],
        'umbrella' => FALSE,
        'granularity_id' => 'permission',
        'granularity_configuration' => [
          'permission' => \sprintf('create %s media', $media_type_id),
        ],
        'dependencies' => NestedArray::mergeDeep($dependencies, ['enforced' => ['config' => [$media_type->getConfigDependencyName()]]]),
      ])->save();
    }
  }

}
