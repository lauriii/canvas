<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_legacy\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for canvas_ai_legacy.
 */
final class CanvasAiLegacyHooks {

  use StringTranslationTrait;

  /**
   * The IDs of the deprecated agents this module provides.
   */
  public const array LEGACY_AGENT_IDS = [
    'canvas_ai_orchestrator',
    'canvas_metadata_generation_agent',
    'canvas_page_builder_agent',
    'canvas_template_builder_agent',
    'canvas_title_generation_agent',
  ];

  public function __construct(
    private readonly MessengerInterface $messenger,
  ) {}

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo(): array {
    return [
      'tokens' => [
        'canvas_ai' => [
          'layout' => [
            'name' => $this->t('Layout'),
            'description' => $this->t('Returns the current page layout value passed to the AI Agent.'),
          ],
        ],
      ],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data = [], array $options = []): array {
    if ($type !== 'canvas_ai' || !isset($tokens['layout'])) {
      return [];
    }
    return [$tokens['layout'] => $data['layout'] ?? NULL];
  }

  /**
   * Implements hook_form_FORM_ID_alter() for ai_agent_edit_form.
   */
  #[Hook('form_ai_agent_edit_form_alter')]
  public function formAiAgentEditFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    \assert($form_object instanceof EntityFormInterface);
    if (\in_array($form_object->getEntity()->id(), self::LEGACY_AGENT_IDS, TRUE)) {
      $this->messenger->addWarning($this->t('This agent is deprecated. See the <a href=":url">change record</a> on how to switch to the new Canvas AI agents.', [
        ':url' => 'https://www.drupal.org/node/3628747',
      ]));
    }
  }

}
