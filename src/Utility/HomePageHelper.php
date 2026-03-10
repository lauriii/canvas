<?php

namespace Drupal\canvas\Utility;

use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

final class HomePageHelper {

  public function __construct(private ConfigFactoryInterface $configFactory, private EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Checks if the given entity's path is set as the homepage.
   *
   * Checks both the current homepage configuration and any staged homepage
   * configuration changes.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if the entity's path is the homepage (current or staged), FALSE
   *   otherwise.
   */
  public function isHomepage(FieldableEntityInterface $entity): bool {
    try {
      $url = $entity->toUrl('canonical');
      $path_alias = $url->toString();
      $internal_path = '/' . $url->getInternalPath();
      $paths = array_unique([$path_alias, $internal_path]);
    }
    catch (\Exception) {
      return FALSE;
    }

    // Check current homepage configuration.
    $system_config = $this->configFactory->get('system.site');
    $current_homepage = $system_config->get('page.front');
    if (\in_array($current_homepage, $paths, TRUE)) {
      return TRUE;
    }

    // Check staged homepage configuration.
    $staged_homepage_config = $this->entityTypeManager
      ->getStorage('staged_config_update')
      ->load('canvas_set_homepage');
    if ($staged_homepage_config instanceof StagedConfigUpdate) {
      $actions = $staged_homepage_config->getActions();
      foreach ($actions as $action) {
        if (isset($action['input']['page.front'])) {
          $staged_homepage = $action['input']['page.front'];
          if (\in_array($staged_homepage, $paths, TRUE)) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

}
