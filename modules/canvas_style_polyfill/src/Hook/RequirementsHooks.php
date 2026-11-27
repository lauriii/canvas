<?php

declare(strict_types=1);

namespace Drupal\canvas_style_polyfill\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Reports whether the polyfill is in use, and when it can be uninstalled.
 */
final class RequirementsHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $core_has_style_api = \Drupal::hasService('plugin.manager.style');
    return [
      'canvas_style_polyfill' => [
        'title' => $this->t('Canvas Style API polyfill'),
        'value' => $core_has_style_api ? $this->t('Not in use') : $this->t('In use'),
        'description' => $core_has_style_api
          ? $this->t('Drupal core provides the Style API, so Canvas uses it. You can uninstall the Canvas Style API polyfill module.')
          : $this->t('Canvas reads <code>utilities.yml</code> files itself until Drupal core provides the Style API.'),
        'severity' => $core_has_style_api ? RequirementSeverity::Warning : RequirementSeverity::Info,
      ],
    ];
  }

}
