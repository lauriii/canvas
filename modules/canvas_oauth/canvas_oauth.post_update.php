<?php

declare(strict_types=1);

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
