<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of the get js component function.
 */
#[FunctionCall(
  id: 'ai_agent:get_js_component',
  function_name: 'ai_agent_get_js_component',
  name: 'Get JS Component',
  description: 'This method gets the javascript, css, props and slots for a JS component.',
  group: 'information_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'component_name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component Name"),
      description: new TranslatableMarkup("The data name of the component that we should get the data for."),
      required: TRUE
    ),
  ],
)]
final class GetJsComponent extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  use AiGeneratedJsComponentPropsAndSlotsTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The AutoSave manager.
   *
   * @var \Drupal\canvas\AutoSave\AutoSaveManager
   */
  protected AutoSaveManager $autoSaveManager;

  /**
   * Load from dependency injection container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->entityTypeManager = $container->get(EntityTypeManagerInterface::class);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    return $instance;
  }

  /**
   * The component information.
   *
   * @var string
   */
  protected string $information = "";

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    // Collect the context values.
    $component_id = $this->getContextValue('component_name');

    // Check if the component exists.
    /** @var \Drupal\canvas\Entity\JavaScriptComponent $component */
    $component = $this->entityTypeManager->getStorage(JavaScriptComponent::ENTITY_TYPE_ID)->load($component_id);

    // If the component does not exist, return an error.
    if (!$component) {
      $this->information = "The component does not exist.";
      return;
    }

    // Check so the user has access to the component.
    if (!$component->access('view')) {
      $this->information = "You do not have access to this component.";
      return;
    }

    // Normalize it for the frontend as array.
    $array = $component->toArray();

    // Check if there is autosaved data.
    $save = $this->autoSaveManager->getAutoSaveEntity($component);
    if (!$save->isEmpty()) {
      \assert($save->entity instanceof JavaScriptComponent);
      $array = $save->entity->toArray();
    }

    // Give back the js, css and the props and slots in the shape the agent
    // passes back to the create and edit tools.
    $output = [
      'js' => $array['js']['original'],
      'css' => $array['css']['original'],
      'props_metadata' => $this->buildPropsMetadataFromStored($array['props'] ?? [], $array['required'] ?? []),
      'slots_metadata' => $this->buildSlotsMetadataFromStored($array['slots'] ?? []),
    ];

    $this->information = Yaml::dump($output, 10, 2);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->information;
  }

}
