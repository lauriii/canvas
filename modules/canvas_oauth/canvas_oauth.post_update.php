<?php

declare(strict_types=1);

use Drupal\canvas_oauth\MediaScopesHelper;
use Drupal\simple_oauth\Entity\Oauth2Scope;

/**
 * Install scopes for the canvas page entity type.
 */
function canvas_oauth_post_update_0001_canvas_page_scopes(array &$sandbox): void {
  $dependencies = [
    'enforced' => [
      'module' => [
        'canvas_oauth',
      ],
    ],
  ];
  $scopes = Oauth2Scope::loadMultiple([
    'canvas_page_create',
    'canvas_page_read',
    'canvas_page_edit',
    'canvas_page_delete',
  ]);
  if (!array_key_exists('canvas_page_create', $scopes)) {
    Oauth2Scope::create([
      'id' => 'canvas_page_create',
      'name' => 'canvas:page:create',
      'description' => 'Drupal Canvas: Create Pages',
      'status' => TRUE,
      'grant_types' => [
        'authorization_code' => [
          'status' => TRUE,
          'description' => 'Authorization code access for creating Canvas Pages',
        ],
        'refresh_token' => [
          'status' => TRUE,
          'description' => 'Refresh token access for creating Canvas Pages',
        ],
        'client_credentials' => [
          'status' => TRUE,
          'description' => 'Client credentials access for creating Canvas Pages',
        ],
      ],
      'umbrella' => FALSE,
      'granularity_id' => 'permission',
      'granularity_configuration' => [
        'permission' => 'create canvas_page',
      ],
      'dependencies' => $dependencies,
    ])->save();
  }
  if (!array_key_exists('canvas_page_read', $scopes)) {
    Oauth2Scope::create([
      'id' => 'canvas_page_read',
      'name' => 'canvas:page:read',
      'description' => 'Drupal Canvas: Read Pages',
      'status' => TRUE,
      'grant_types' => [
        'authorization_code' => [
          'status' => TRUE,
          'description' => 'Authorization code access for reading Canvas Pages',
        ],
        'refresh_token' => [
          'status' => TRUE,
          'description' => 'Refresh token access for reading Canvas Pages',
        ],
        'client_credentials' => [
          'status' => TRUE,
          'description' => 'Client credentials access for reading Canvas Pages',
        ],
      ],
      'umbrella' => FALSE,
      'granularity_id' => 'permission',
      'granularity_configuration' => [
        'permission' => 'access content',
      ],
      'dependencies' => $dependencies,
    ])->save();
  }
  if (!array_key_exists('canvas_page_edit', $scopes)) {
    Oauth2Scope::create([
      'id' => 'canvas_page_edit',
      'name' => 'canvas:page:edit',
      'description' => 'Drupal Canvas: Edit Pages',
      'status' => TRUE,
      'grant_types' => [
        'authorization_code' => [
          'status' => TRUE,
          'description' => 'Authorization code access for editing Canvas Pages',
        ],
        'refresh_token' => [
          'status' => TRUE,
          'description' => 'Refresh token access for editing Canvas Pages',
        ],
        'client_credentials' => [
          'status' => TRUE,
          'description' => 'Client credentials access for editing Canvas Pages',
        ],
      ],
      'umbrella' => FALSE,
      'granularity_id' => 'permission',
      'granularity_configuration' => [
        'permission' => 'edit canvas_page',
      ],
      'dependencies' => $dependencies,
    ])->save();
  }
  if (!array_key_exists('canvas_page_delete', $scopes)) {
    Oauth2Scope::create([
      'id' => 'canvas_page_delete',
      'name' => 'canvas:page:delete',
      'description' => 'Drupal Canvas: Delete Pages',
      'status' => TRUE,
      'grant_types' => [
        'authorization_code' => [
          'status' => TRUE,
          'description' => 'Authorization code access for deleting Canvas Pages',
        ],
        'refresh_token' => [
          'status' => TRUE,
          'description' => 'Refresh token access for deleting Canvas Pages',
        ],
        'client_credentials' => [
          'status' => TRUE,
          'description' => 'Client credentials access for deleting Canvas Pages',
        ],
      ],
      'umbrella' => FALSE,
      'granularity_id' => 'permission',
      'granularity_configuration' => [
        'permission' => 'delete canvas_page',
      ],
      'dependencies' => $dependencies,
    ])->save();
  }
}

/**
 * Install scope for the Canvas brand kit.
 */
function canvas_oauth_post_update_0002_canvas_brand_kit_scope(array &$sandbox): void {
  $dependencies = [
    'enforced' => [
      'module' => [
        'canvas_oauth',
      ],
    ],
  ];
  $scope = Oauth2Scope::load('canvas_brand_kit');
  if (!$scope) {
    Oauth2Scope::create([
      'id' => 'canvas_brand_kit',
      'name' => 'canvas:brand_kit',
      'description' => 'Drupal Canvas: Full access to brand kits',
      'status' => TRUE,
      'grant_types' => [
        'authorization_code' => [
          'status' => TRUE,
          'description' => 'Authorization code access for brand kits',
        ],
        'refresh_token' => [
          'status' => TRUE,
          'description' => 'Refresh token access for brand kits',
        ],
        'client_credentials' => [
          'status' => TRUE,
          'description' => 'Client credentials access for brand kits',
        ],
      ],
      'umbrella' => FALSE,
      'granularity_id' => 'permission',
      'granularity_configuration' => [
        'permission' => 'administer brand kit',
      ],
      'dependencies' => $dependencies,
    ])->save();
  }

}

/**
 * Install scopes for media types with the image source plugin.
 */
function canvas_oauth_post_update_0003_media_image_scopes(array &$sandbox): void {
  \Drupal::classResolver(MediaScopesHelper::class)->ensureMediaImageScopes();
}

/**
 * Install the canvas:media:view scope.
 */
function canvas_oauth_post_update_0004_media_view_scope(array &$sandbox): void {
  $scope = Oauth2Scope::load('canvas_media_view');
  if (!$scope) {
    Oauth2Scope::create([
      'id' => 'canvas_media_view',
      'name' => 'canvas:media:view',
      'description' => 'Drupal Canvas: View Media',
      'status' => TRUE,
      'grant_types' => [
        'authorization_code' => [
          'status' => TRUE,
          'description' => 'Authorization code access for viewing media',
        ],
        'refresh_token' => [
          'status' => TRUE,
          'description' => 'Refresh token access for viewing media',
        ],
        'client_credentials' => [
          'status' => TRUE,
          'description' => 'Client credentials access for viewing media',
        ],
      ],
      'umbrella' => FALSE,
      'granularity_id' => 'permission',
      'granularity_configuration' => [
        'permission' => 'view media',
      ],
      'dependencies' => [
        'enforced' => [
          'module' => [
            'canvas_oauth',
          ],
        ],
      ],
    ])->save();
  }
}
