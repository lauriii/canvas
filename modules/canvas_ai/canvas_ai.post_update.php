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

/**
 * Preserve the existing chat history limit fo existing sites.
 */
function canvas_ai_post_update_0002_chat_history_max_messages(): void {
  $config = \Drupal::configFactory()->getEditable('canvas_ai.settings');

  // Existing sites effectively use a limit of 3, so keep that value here to
  // preserve current behavior. New installations get the newer default of
  // 10 from config/install/canvas_ai.settings.yml.
  if (!$config->isNew()) {
    $config->set('chat_history_max_messages', 3)->save(TRUE);
  }
}
