<?php

namespace Drupal\canvas\ComponentSource;

/**
 * Result of an attempted component instance update.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentInstanceUpdaterInterface::update()
 * @internal
 */
enum ComponentInstanceUpdateAttemptResult {
  case NotNeeded;
  case NotAllowed;
  case Latest;
}
