<?php

namespace Drupal\xb_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of edit the component js function.
 */
#[FunctionCall(
  id: 'ai_agent:edit_component_js',
  function_name: 'ai_agent_edit_component_js',
  name: 'Edit javascript on components',
  description: 'This method allows you to edit the javascript on components.',
  group: 'modification_tools',
  module_dependencies: ['experience_builder'],
  context_definitions: [
    'javascript' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Javascript"),
      description: new TranslatableMarkup("All the new javascript that should replace the old one."),
      required: TRUE
    ),
    'props_metadata' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Props"),
      description: new TranslatableMarkup("Metadata for props"),
      required: TRUE
    ),
  ],
)]
final class EditComponentJs extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The js.
   *
   * @var string
   */
  protected string $js = "";
  /**
   * The props.
   *
   * @var string
   */
  protected string $props = "";

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $this->js = $this->getContextValue('javascript');
    $this->props = $this->getContextValue('props_metadata');
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    // Output it kind of like a yaml file.
    return Yaml::dump([
      'js_structure' => $this->js,
      'props_metadata' => $this->props,
    ], 10, 2);
  }

}
