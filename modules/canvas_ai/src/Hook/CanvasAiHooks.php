<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Hook;

use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\Form\CanvasAiAgentSelectionForm;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for canvas_ai tokens.
 */
class CanvasAiHooks {
  use StringTranslationTrait;

  /**
   * The ID of the agent that describes the Tools rather than being one.
   */
  private const CANVAS_AGENT_ID = 'canvas_agent';

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function canvas_ai_token_info(): array {
    return [
      'types' => [
        'canvas_ai' => [
          'name' => $this->t('Canvas AI Agent'),
          'description' => $this->t('Tokens related to Canvas AI Agent context.'),
        ],
      ],
      'tokens' => [
        'canvas_ai' => [
          'entity_type' => [
            'name' => $this->t('Entity Type'),
            'description' => $this->t('Returns the entity type value passed to the AI Agent.'),
          ],
          'entity_id' => [
            'name' => $this->t('Entity Id'),
            'description' => $this->t('Returns the entity id value passed to the AI Agent.'),
          ],
          'selected_component' => [
            'name' => $this->t('Selected Component'),
            'description' => $this->t('Returns the selected component name passed to the AI Agent.'),
          ],
          'derived_proptypes' => [
            'name' => $this->t('derived Proptypes'),
            'description' => $this->t('Returns the proptypes available in Drupal Canvas.'),
          ],
          'page_title' => [
            'name' => $this->t('Page Title'),
            'description' => $this->t('Returns the title of the page.'),
          ],
          'page_description' => [
            'name' => $this->t('Page Description'),
            'description' => $this->t('Returns the description of the page.'),
          ],
          'active_component_uuid' => [
            'name' => $this->t('Active Component UUID'),
            'description' => $this->t('Returns the UUID of the active component in the page.'),
          ],
          'custom_libraries' => [
            'name' => $this->t('Custom libraries in Canvas.'),
            'description' => $this->t('Returns the custom libraries in Canvas.'),
          ],
          'component_agent_dynamic_state' => [
            'name' => $this->t('Component Agent Dynamic State'),
            'description' => $this->t('Returns the assembled state and constraints for the component agent.'),
          ],
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
  public function canvas_ai_tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    $replacements = [];

    if ($type === 'canvas_ai') {
      foreach ($tokens as $name => $original) {
        switch ($name) {
          case 'entity_type':
            $replacements[$original] = $data['entity_type'] ?? NULL;
            break;

          case 'entity_id':
            $replacements[$original] = $data['entity_id'] ?? NULL;
            break;

          case 'selected_component':
            $replacements[$original] = $data['selected_component'] ?? NULL;
            break;

          case 'derived_proptypes':
            $replacements[$original] = $data['derived_proptypes'] ?? NULL;
            break;

          case 'page_title':
            $replacements[$original] = $data['page_title'] ?? NULL;
            break;

          case 'page_description':
            $replacements[$original] = $data['page_description'] ?? NULL;
            break;

          case 'active_component_uuid':
            $replacements[$original] = $data['active_component_uuid'] ?? 'None';
            break;

          case 'custom_libraries':
            $replacements[$original] = $data['custom_libraries'];
            break;

          case 'component_agent_dynamic_state':
            $replacements[$original] = $data['component_agent_dynamic_state'] ?? NULL;
            break;

          case 'available_tools':
            $available = $this->getAvailableAgents();
            // The replacement is built from the settings and from the agents it
            // describes, so anything rendering it has to be invalidated when
            // either changes.
            $settings = $this->configFactory->get('canvas_ai.settings');
            $bubbleable_metadata->addCacheableDependency($settings);
            foreach ($available as $agent) {
              $bubbleable_metadata->addCacheableDependency($agent);
            }
            $replacements[$original] = self::renderToolList($available, $settings->get('tools') ?? []);
            break;
        }
      }
    }

    return $replacements;
  }

  /**
   * Implements hook_js_settings_alter().
   */
  #[Hook('js_settings_alter')]
  public function jsSettingsAlter(array &$settings): void {
    if (!empty($settings['canvas']['aiExtensionAvailable'])) {
      $config = $this->configFactory->get('canvas_ai.settings');
      $file_upload_size_mb = $config->get('file_upload_size') ?? 2;
      $file_upload_size_bytes = $file_upload_size_mb * 1024 * 1024;
      $settings['canvas']['canvasAiMaxFileSize'] = $file_upload_size_bytes;
      $settings['canvas']['permissions']['useCanvasAi'] = $this->currentUser->hasPermission(CanvasAiPermissions::USE_CANVAS_AI);
      // Renders the client-side orchestrated chat unless the legacy chat is
      // installed.
      // @todo Rename AiWizard to AiWizardLegacy and render it on an aiLegacyMode flag set by canvas_ai_legacy, rename AiWizardDev to AiWizard, and set aiDevMode only from canvas_dev_ai, see https://git.drupalcode.org/project/canvas/-/work_items/3592374
      if (!$this->moduleHandler->moduleExists('canvas_ai_legacy')) {
        $settings['canvas']['aiDevMode'] = TRUE;
      }
      $tools = $this->getToolDescriptors();
      if ($tools !== []) {
        $settings['canvas']['ai']['tools'] = $tools;
      }
    }
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
   *   The IDs the site has enabled, from canvas_ai.settings.
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
    return $this->loadAgents($this->configFactory->get('canvas_ai.settings')->get('tools') ?? []);
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
      CanvasAiAgentSelectionForm::SELECTABLE_AGENTS,
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
