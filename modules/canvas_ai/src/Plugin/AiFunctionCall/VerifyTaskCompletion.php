<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentContextInterface;

/**
 * Plugin implementation to verify task completion.
 */
#[FunctionCall(
  id: 'ai_agent:verify_task_completion',
  function_name: 'ai_agent_verify_task_completion',
  name: 'Verify Task Completion Status',
  description: 'This tool MUST be called ONLY after invoking canvas_page_builder_agent or canvas_template_builder_agent to verify that title and metadata were properly generated if they were empty. DO NOT call this tool for any other operations including canvas_component_agent, general questions, or non-page-building tasks.',
  group: 'verification_tools',
  module_dependencies: ['canvas_ai'],
  context_definitions: [],
)]
class VerifyTaskCompletion extends FunctionCallBase implements ExecutableFunctionCallInterface, AiAgentContextInterface {

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    // This tool doesn't need to do anything - it's a prompt engineering trick
    // The act of calling it forces the orchestrator to think through completion
    // It can be removed once the orchestrator is smart enough to do this on its own.
    $this->setOutput("Task completion verification checklist:\n\n" .
      "✓ Confirm the following were completed:\n" .
      "  - If canvas_page_builder_agent was used:\n" .
      "    • Page components were added/modified\n" .
      "    • Page title was generated using canvas_title_generation_agent (if title was empty or 'Untitled page')\n" .
      "    • Page description was generated using canvas_metadata_generation_agent (if description was empty)");
  }

}
