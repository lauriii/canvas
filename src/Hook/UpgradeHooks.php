<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\experience_builder\ExperienceBuilderConfigUpdater;
use Drupal\field\Entity\FieldConfig;

final class UpgradeHooks {

  public function __construct(
    private readonly ExperienceBuilderConfigUpdater $configUpdater,
  ) {
  }

  #[Hook('field_config_presave')]
  public function fieldConfigPreSave(FieldConfig $field): void {
    $this->configUpdater->updateConfigEntityWithComponentTreeInputs($field);
  }

}
