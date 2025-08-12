<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\experience_builder\ExperienceBuilderConfigUpdater;

trait ConfigUpdaterAwareEntityTrait {

  protected static function getConfigUpdater(): ExperienceBuilderConfigUpdater {
    return \Drupal::service(ExperienceBuilderConfigUpdater::class);
  }

}
