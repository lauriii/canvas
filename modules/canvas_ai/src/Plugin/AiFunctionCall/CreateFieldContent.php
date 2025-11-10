<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;

/**
 * Plugin implementation of create field content function.
 */
#[FunctionCall(
  id: 'ai_agent:create_field_content',
  function_name: 'ai_agent_create_field_content',
  name: 'Create content for entity field',
  description: 'This method allows you to add the content on entity field.',
  group: 'modification_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'field_content' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Content for the field."),
      description: new TranslatableMarkup("Content for the field."),
      required: TRUE
    ),
    'field_name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Field Name"),
      description: new TranslatableMarkup("The field provided by the user."),
      required: TRUE
    ),
  ],
)]
final class CreateFieldContent extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $this->setStructuredOutput([
      'created_content' => $this->getContextValue('field_content'),
    ]);
  }

}
