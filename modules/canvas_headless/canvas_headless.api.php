<?php

/**
 * @file
 * Hooks provided by the Drupal Canvas Headless module.
 */

declare(strict_types=1);

/**
 * Declares permissions that are safe to expose in a draft preview session.
 *
 * Preview access tokens are bound to the initiating editor, and Simple
 * OAuth intersects the editor's own permissions with the ceiling this hook
 * assembles — so a declared permission takes effect only when the editor
 * personally holds it, and an editor's permission reaches the preview only
 * when it is declared here. Declare the view-only permissions your
 * module's content needs in a read-only preview; never declare permissions
 * that allow writes.
 *
 * This hook exists because Drupal permissions carry no machine-readable
 * read/write metadata, and no relationship to entity types either: an
 * entity type definition links an access control handler, but the handler
 * does not enumerate the permission strings it consults, so "the view
 * permissions of entity type X" cannot be computed. The judgment of what
 * is preview-safe stays with the module that defines the permission,
 * instead of in a central list that would silently strip permissions it
 * does not know about. The failure direction is safe — an undeclared
 * permission means a preview shows too little, never too much.
 *
 * The ceiling mediates permission checks and nothing else. Access decided
 * outside the permission system — node access grants via hook_node_grants()
 * foremost — follows the token's user binding instead: grant queries run
 * against the editor the token is bound to, so grant-based view access
 * appears in previews exactly as it does in the editor's own session. The
 * ceiling neither extends nor narrows it.
 *
 * @return string[]
 *   Permission machine names. Undefined permissions (e.g. from modules
 *   that are not installed) are ignored.
 */
function hook_canvas_headless_safe_permissions(): array {
  return [
    'view my_module widgets',
  ];
}

/**
 * Alters the preview-safe permission ceiling.
 *
 * @param string[] $permissions
 *   All permissions declared via hook_canvas_headless_safe_permissions().
 */
function hook_canvas_headless_safe_permissions_alter(array &$permissions): void {
  // Example: a site policy that keeps revision history out of previews.
  $permissions = array_diff($permissions, ['view all revisions']);
}
