<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_ai\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for canvas_dev_ai.
 *
 * @internal
 */
class CanvasDevAiHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Implements hook_js_settings_alter().
   */
  #[Hook('js_settings_alter')]
  public function jsSettingsAlter(array &$settings): void {
    if (empty($settings['canvas']['aiExtensionAvailable'])) {
      return;
    }
    $settings['canvas']['aiDevMode'] = TRUE;

    $tool_ids = $this->configFactory->get('canvas_dev_ai.settings')->get('tools') ?? [];
    if ($tool_ids === []) {
      return;
    }

    $agents = $this->entityTypeManager
      ->getStorage('ai_agent')
      ->loadMultiple($tool_ids);

    $tools = [];
    foreach ($tool_ids as $id) {
      $agent = $agents[$id] ?? NULL;
      if (!$agent instanceof ConfigEntityInterface) {
        continue;
      }
      $tools[] = [
        'id' => $id,
        'label' => (string) $agent->label(),
        'description' => (string) $agent->get('description'),
      ];
    }

    if ($tools !== []) {
      $settings['canvas']['ai']['tools'] = $tools;
    }
  }

}
