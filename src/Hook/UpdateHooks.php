<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\canvas\CanvasConfigUpdater;
use Drupal\field\Entity\FieldConfig;

final class UpdateHooks {

  public function __construct(
    private readonly CanvasConfigUpdater $configUpdater,
    private readonly ConfigInstallerInterface $configInstaller,
  ) {
  }

  #[Hook('field_config_presave')]
  public function fieldConfigPreSave(FieldConfig $field): void {
    $this->configUpdater->updateConfigEntityWithComponentTreeInputs($field);
    // We might need to update dependencies even on import.
    // @see \canvas_post_update_0002_intermediate_component_dependencies_in_field_config_component_trees
    if ($this->configInstaller->isSyncing()) {
      if ($this->configUpdater->needsIntermediateDependenciesComponentUpdate($field)) {
        $field->calculateDependencies();
      }
    }
  }

}
