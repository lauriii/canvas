<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_ai\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Selects the main agent and the Tools offered in the Canvas AI chat.
 */
final class CanvasDevAiAgentSelectionForm extends ConfigFormBase {

  /**
   * The ai_agent IDs that may be selected.
   *
   * CanvasDevAiHooks reads this to decide which Tools are available, so every
   * ID here is described to the Canvas agent whether or not it is enabled.
   *
   * @todo Trim to canvas_agent, canvas_component_agent and canvas_dev_page_builder_agent, and set canvas_agent as the main agent, in https://git.drupalcode.org/project/canvas/-/work_items/3591995
   */
  public const SELECTABLE_AGENTS = [
    'canvas_ai_orchestrator',
    'canvas_component_agent',
    'canvas_dev_page_builder_agent',
    'canvas_page_builder_agent',
  ];

  /**
   * Creates a new CanvasDevAiAgentSelectionForm instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'canvas_dev_ai_agent_selection';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['canvas_dev_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('canvas_dev_ai.settings');
    $options = $this->agentOptions();

    $form['main_agent'] = [
      '#type' => 'select',
      '#title' => $this->t('Main agent'),
      '#description' => $this->t('The agent that answers a chat turn when no Tool is active.'),
      '#options' => $options,
      '#default_value' => $config->get('main_agent'),
      '#required' => TRUE,
    ];

    $form['tools'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Tools'),
      '#description' => $this->t('The agents offered as Tools in the chat.'),
      '#options' => $options,
      '#default_value' => $config->get('tools') ?? [],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $main_agent = $form_state->getValue('main_agent');
    $tools = array_filter((array) $form_state->getValue('tools'));
    if ($main_agent && isset($tools[$main_agent])) {
      $form_state->setErrorByName('tools', $this->t('The main agent cannot also be offered as a Tool.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tools = array_values(array_filter((array) $form_state->getValue('tools')));
    $this->config('canvas_dev_ai.settings')
      ->set('main_agent', $form_state->getValue('main_agent'))
      ->set('tools', $tools)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Builds the selectable agent options, keyed by agent ID.
   *
   * @return array<string, string>
   *   Agent labels keyed by ID.
   */
  private function agentOptions(): array {
    $options = [];
    $storage = $this->entityTypeManager->getStorage('ai_agent');
    foreach ($storage->loadMultiple(self::SELECTABLE_AGENTS) as $id => $agent) {
      $options[$id] = (string) $agent->label();
    }
    return $options;
  }

}
