<?php

declare(strict_types=1);

/**
 * Rebuild the router.
 *
 * Clears the route cache as this issue introduces changes to canvas_ai routes.
 *
 * @see https://www.drupal.org/project/canvas/issues/3533079
 */
function canvas_ai_post_update_0001_rebuild_router(): void {
  \Drupal::service('router.builder')->rebuild();
}
