<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\CanvasConfigUpdater;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldConfig;

final class UpdateHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly CanvasConfigUpdater $configUpdater,
    private readonly ConfigInstallerInterface $configInstaller,
  ) {
  }

  #[Hook('field_config_presave')]
  public function fieldConfigPreSave(FieldConfig $field): void {
    $this->configUpdater->updateConfigEntityWithComponentTreeInputs($field);
    $this->configUpdater->updateConfigEntityWithComponentTreeInputsAsArrays($field);
    $this->configUpdater->updateConfigEntityBlockLabelDisplay($field);
    // We might need to update dependencies even on import.
    // @see \canvas_post_update_0002_intermediate_component_dependencies_in_field_config_component_trees
    if ($this->configInstaller->isSyncing()) {
      if ($this->configUpdater->needsIntermediateDependenciesComponentUpdate($field)) {
        $field->calculateDependencies();
      }
    }
  }

  /**
   * Implements hook_update_requirements().
   *
   * Canvas' assertions help developers working on Canvas itself. On sites, a
   * failing assertion can make a database update fail, while Canvas' guardrails
   * would let it serve production traffic despite the unexpected state.
   *
   * @see https://www.drupal.org/docs/develop/drupal-apis/runtime-assertions
   */
  #[Hook('update_requirements')]
  public function updateRequirements(): array {
    $assertions_enabled = FALSE;
    // @phpstan-ignore-next-line function.alreadyNarrowedType
    \assert($assertions_enabled = TRUE);
    // @phpstan-ignore-next-line booleanNot.alwaysTrue
    if (!$assertions_enabled) {
      return [];
    }
    return [
      'canvas_assertions' => [
        'title' => $this->t('Drupal Canvas: PHP assertions'),
        'value' => $this->t('Enabled'),
        'description' => $this->t("Canvas' assertions are for Canvas development, not for sites. On sites, a failing assertion blocks database updates that Canvas' guardrails would otherwise handle safely. Disable <code>zend.assertions</code> per the <a href=':url'>runtime assertions</a> best practices.", [':url' => 'https://www.drupal.org/docs/develop/drupal-apis/runtime-assertions']),
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

}
