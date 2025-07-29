<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\experience_builder\Entity\JavaScriptComponent;

class ExperienceBuilderConfigUpdater {

  /**
   * Flag determining whether deprecations should be triggered.
   *
   * @var bool
   */
  protected bool $deprecationsEnabled = TRUE;

  /**
   * Stores which deprecations were triggered.
   *
   * @var array
   */
  protected array $triggeredDeprecations = [];

  /**
   * Sets the deprecations enabling status.
   *
   * @param bool $enabled
   *   Whether deprecations should be enabled.
   */
  public function setDeprecationsEnabled(bool $enabled): void {
    $this->deprecationsEnabled = $enabled;
  }

  public function updateJavaScriptComponent(JavaScriptComponent $javaScriptComponent): bool {
    $map = [
      'getSiteData' => [
        'v0.baseUrl',
        'v0.branding',
      ],
      'getPageData' => [
        'v0.breadcrumbs',
        'v0.pageTitle',
      ],
      '@drupal-api-client/json-api-client' => [
        'v0.baseUrl',
        'v0.jsonapiSettings',
      ],
    ];

    $changed = FALSE;
    if ($this->needsDataDependenciesUpdate($javaScriptComponent)) {
      $settings = [];
      $jsCode = $javaScriptComponent->getJs();
      foreach ($map as $var => $neededSetting) {
        if (str_contains($jsCode, $var)) {
          $settings = \array_merge($settings, $neededSetting);
        }
      }
      if (\count($settings) > 0) {
        $current = $javaScriptComponent->get('dataDependencies');
        $current['drupalSettings'] = \array_unique(\array_merge($current['drupalSettings'] ?? [], $settings));
        $javaScriptComponent->set('dataDependencies', $current);
      }
      else {
        $javaScriptComponent->set('dataDependencies', []);
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * Checks if the code component still misses the 'dataDependencies' property.
   *
   * @return bool
   */
  public function needsDataDependenciesUpdate(JavaScriptComponent $javaScriptComponent): bool {
    if ($javaScriptComponent->get('dataDependencies') !== NULL) {
      return FALSE;
    }

    $deprecations_triggered = &$this->triggeredDeprecations['3533458'][$javaScriptComponent->id()];
    if ($this->deprecationsEnabled && !$deprecations_triggered) {
      $deprecations_triggered = TRUE;
      @trigger_error('JavaScriptComponent config entities without "dataDependencies" property is deprecated in experience_builder:1.0.0 and will be removed in experience_builder:1.0.0. See https://www.drupal.org/node/3538276', E_USER_DEPRECATED);
    }
    return TRUE;
  }

}
