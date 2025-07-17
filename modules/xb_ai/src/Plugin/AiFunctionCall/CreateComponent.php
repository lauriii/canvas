<?php

namespace Drupal\xb_ai\Plugin\AiFunctionCall;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\Utility\ContextDefinitionNormalizer;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of the create component function.
 */
#[FunctionCall(
  id: 'ai_agent:create_component',
  function_name: 'ai_agent_create_component',
  name: 'Create new component',
  description: 'This method creates a new component.',
  group: 'modification_tools',
  module_dependencies: ['experience_builder'],
  context_definitions: [
    'component_name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Component Name"),
      description: new TranslatableMarkup("The data name of the component that we should create."),
      required: TRUE
    ),
    'js_structure' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Javascript"),
      description: new TranslatableMarkup("All the new javascript."),
      required: FALSE
    ),
    'css_structure' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("CSS"),
      description: new TranslatableMarkup("All the new CSS."),
      required: FALSE
    ),
    'props_metadata' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Props"),
      description: new TranslatableMarkup("Metadata for props"),
      required: FALSE
    ),
  ],
)]
final class CreateComponent extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Load from dependency injection container.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface | static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      new ContextDefinitionNormalizer(),
    );
    $instance->entityTypeManager = $container->get(EntityTypeManagerInterface::class);
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
    $component_name = $this->getContextValue('component_name');
    $javascript = $this->getContextValue('js_structure') ?? '';
    $css = $this->getContextValue('css_structure') ?? '';
    $props = $this->getContextValue('props_metadata') ?? '';
    $machine_name = strtolower(preg_replace('/\s+/', '_', $component_name));

    // Check if the component exists.
    /** @var \Drupal\experience_builder\Entity\JavaScriptComponent $component */
    $component = $this->entityTypeManager->getStorage('js_component')->load($machine_name);

    // If the component does not exist, return an error.
    if ($component) {
      $this->information = "The component with same name already exists.";
      return;
    }

    $props_array = Json::decode($props);
    $transformed_props = [];
    if (is_array($props_array)) {
      foreach ($props_array as $prop) {
        if (!empty($prop['id']) && !empty($prop['name']) && !empty($prop['type']) && !empty($prop['example'])) {
          $transformed = [
            'title' => $prop['name'],
            'type' => $prop['type'],
            'examples' => [$prop['example']],
          ];
          foreach (['format', '$ref', 'enum'] as $optional) {
            if (isset($prop[$optional])) {
              $transformed[$optional] = $prop[$optional];
            }
          }
          $transformed_props[$prop['id']] = $transformed;
        }
      }
    }
    $output = [
      'name' => $component_name,
      'machineName' => $machine_name,
      // Mark this code component as "internal": do not make it available to Content Creators yet.
      // @see docs/config-management.md, section 3.2.1
      'status' => FALSE,
      'sourceCodeJs' => $javascript,
      'sourceCodeCss' => $css,
      'compiledJs' => '',
      'compiledCss' => '',
      'importedJsComponents' => [],
      'props' => $transformed_props,
    ];

    $this->information = Yaml::dump([
      'component_structure' => $output,
    ], 10, 2);
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->information;
  }

}
