<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Sets the title or meta description of the current canvas_page.
 *
 * Emits the canvas_page_data shape the Canvas UI consumes directly, so no
 * controller post-processing is needed.
 *
 * This tool will soon replace the separate create_field_content,
 * edit_field_content and add_metadata tools once it is wired.
 *
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\CreateFieldContent
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\EditFieldContent
 * @see \Drupal\canvas_ai\Plugin\AiFunctionCall\AddMetadata
 */
#[FunctionCall(
  id: 'canvas_ai:set_page_value',
  function_name: 'set_page_value',
  name: 'Set Page Value',
  description: 'Sets the title or SEO meta description of the current page. Set "key" to "title" or "description", and "value" to the text to write. Use this whenever the page title or meta description should be created or changed.',
  group: 'modification_tools',
  module_dependencies: ['canvas'],
  context_definitions: [
    'key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Page value key"),
      description: new TranslatableMarkup("The page value to set. Allowed values: 'title', 'description'."),
      required: TRUE,
      constraints: [
        'AllowedValues' => [
          'title',
          'description',
        ],
      ],
    ),
    'value' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup("Value"),
      description: new TranslatableMarkup("The text value to set for the given key."),
      required: TRUE,
    ),
  ],
)]
final class SetPageValue extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface, BuilderResponseFunctionCallInterface {

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $key = $this->getContextValue('key');
    $value = $this->getContextValue('value');

    // "key" is restricted to 'title'/'description' by the AllowedValues
    // constraint, which the agent validates before execute() runs.
    $this->setStructuredOutput([
      'canvas_page_data' => ["{$key}[0][value]" => $value],
    ]);
    $this->setOutput("Page {$key} set successfully.");
  }

}
