<?php

namespace Drupal\xb_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;

/**
 * Plugin implementation of the get props types function.
 */
#[FunctionCall(
  id: 'ai_agent:get_props_type',
  function_name: 'ai_agent_get_props_type',
  name: 'Get Props Type',
  description: 'This method gets the props type in experience builder.',
  group: 'information_tools',
  module_dependencies: ['experience_builder'],
  context_definitions: [
    'derived_proptypes' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Prop Type"),
      description: new TranslatableMarkup("The prop type for experience builder."),
      required: TRUE
    ),
  ],
)]
final class GetPropsType extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * The prop types information.
   *
   * @var string
   */
  protected string $derivedProptypes = '';

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $this->derivedProptypes = html_entity_decode($this->getContextValue('derived_proptypes'));
  }

  /**
   * {@inheritdoc}
   */
  public function getReadableOutput(): string {
    return $this->derivedProptypes;
  }

}
