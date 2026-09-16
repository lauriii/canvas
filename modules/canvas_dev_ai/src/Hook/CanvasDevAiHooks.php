<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_ai\Hook;

use Drupal\canvas_dev_ai\Form\CanvasDevAiAgentSelectionForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for canvas_dev_ai.
 *
 * @internal
 */
class CanvasDevAiHooks {

  use StringTranslationTrait;

  /**
   * The ID of the agent that describes the Tools rather than being one.
   */
  private const CANVAS_AGENT_ID = 'canvas_agent';

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

    $tools = $this->getToolDescriptors();
    if ($tools !== []) {
      $settings['canvas']['ai']['tools'] = $tools;
    }
  }

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    return [
      'types' => [
        'canvas_dev_ai' => [
          'name' => $this->t('Drupal Canvas Dev AI'),
          'description' => $this->t('Tokens related to the Canvas AI chat.'),
        ],
      ],
      'tokens' => [
        'canvas_dev_ai' => [
          'available_tools' => [
            'name' => $this->t('Available Tools'),
            'description' => $this->t('Returns the Tools available in the chat, as a Markdown list, each marked enabled or disabled.'),
          ],
        ],
      ],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    $replacements = [];

    if ($type === 'canvas_dev_ai') {
      foreach ($tokens as $name => $original) {
        if ($name === 'available_tools') {
          $available = $this->getAvailableAgents();
          // The replacement is built from the settings and from the agents it
          // describes, so anything rendering it has to be invalidated when
          // either changes.
          $settings = $this->configFactory->get('canvas_dev_ai.settings');
          $bubbleable_metadata->addCacheableDependency($settings);
          foreach ($available as $agent) {
            $bubbleable_metadata->addCacheableDependency($agent);
          }
          $replacements[$original] = self::renderToolList($available, $settings->get('tools') ?? []);
        }
      }
    }

    return $replacements;
  }

  /**
   * Renders the available Tools as the list an agent's system prompt carries.
   *
   * Every available Tool is listed, whether or not the site has enabled it, so
   * the agent can tell a user to enable one rather than claim it cannot be
   * done.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface[] $available
   *   The agents available as Tools, keyed by ID.
   * @param string[] $enabled_ids
   *   The IDs the site has enabled, from canvas_dev_ai.settings.
   *
   * @return string
   *   One Markdown list item per Tool, each marked enabled or disabled. A
   *   sentence saying so when no Tool is available at all.
   */
  private static function renderToolList(array $available, array $enabled_ids): string {
    if ($available === []) {
      return 'There are no tools available.';
    }

    $lines = [];
    foreach ($available as $id => $agent) {
      $lines[] = \sprintf(
        '* **%s** (%s): %s',
        $agent->label(),
        \in_array($id, $enabled_ids, TRUE) ? 'enabled' : 'disabled',
        (string) $agent->get('description'),
      );
    }
    return implode("\n", $lines);
  }

  /**
   * Builds the descriptors of the agents enabled as Tools in the chat.
   *
   * The front end offers only enabled Tools, so this lists fewer agents than
   * the system prompt token, which also describes the disabled ones.
   *
   * @return array<int, array{id: string, label: string, description: string}>
   *   One descriptor per enabled Tool, in the order stored in config.
   */
  private function getToolDescriptors(): array {
    $tools = [];
    foreach ($this->getToolAgents() as $id => $agent) {
      $tools[] = [
        'id' => $id,
        'label' => (string) $agent->label(),
        'description' => (string) $agent->get('description'),
      ];
    }

    return $tools;
  }

  /**
   * Loads the agents enabled as Tools in the chat.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface[]
   *   The agents, keyed by ID, in the order stored in config. An ID naming an
   *   agent that no longer exists is skipped.
   */
  private function getToolAgents(): array {
    return $this->loadAgents($this->configFactory->get('canvas_dev_ai.settings')->get('tools') ?? []);
  }

  /**
   * Loads the agents available as Tools, enabled or not.
   *
   * The Canvas agent itself is excluded: it describes the Tools, so listing it
   * would let it offer itself as one.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface[]
   *   The agents, keyed by ID. An ID naming an agent that does not exist is
   *   skipped.
   */
  private function getAvailableAgents(): array {
    $available_ids = array_values(array_diff(
      CanvasDevAiAgentSelectionForm::SELECTABLE_AGENTS,
      [self::CANVAS_AGENT_ID],
    ));

    return $this->loadAgents($available_ids);
  }

  /**
   * Loads the named agents, preserving order and skipping missing ones.
   *
   * @param string[] $ids
   *   The ai_agent IDs to load.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface[]
   *   The agents that exist, keyed by ID, in the order given.
   */
  private function loadAgents(array $ids): array {
    if ($ids === []) {
      return [];
    }

    $agents = $this->entityTypeManager
      ->getStorage('ai_agent')
      ->loadMultiple($ids);

    $ordered = [];
    foreach ($ids as $id) {
      $agent = $agents[$id] ?? NULL;
      if (!$agent instanceof ConfigEntityInterface) {
        continue;
      }
      $ordered[$id] = $agent;
    }

    return $ordered;
  }

}
