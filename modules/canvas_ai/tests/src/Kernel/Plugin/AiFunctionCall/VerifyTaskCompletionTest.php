<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel\Plugin\AiFunctionCall;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Drupal\canvas_ai\Plugin\AiFunctionCall\VerifyTaskCompletion;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;

/**
 * Tests Drupal\canvas_ai\Plugin\AiFunctionCall\VerifyTaskCompletion.
 */
#[CoversClass(VerifyTaskCompletion::class)]
#[Group('canvas_ai')]
final class VerifyTaskCompletionTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_agents',
    'canvas_ai',
  ];

  /**
   * Tests the readable output contains expected checklist content.
   */
  public function test(): void {
    $function_call_manager = $this->container->get('plugin.manager.ai.function_calls');
    $tool = $function_call_manager->createInstance('ai_agent:verify_task_completion');
    self::assertInstanceOf(VerifyTaskCompletion::class, $tool);
    $tool->execute();
    $output = $tool->getReadableOutput();

    self::assertEquals(
      "Task completion verification checklist:\n\n" .
        "✓ Confirm the following were completed:\n" .
        "  - If canvas_page_builder_agent was used:\n" .
        "    • Page components were added/modified\n" .
        "    • Page title was generated using canvas_title_generation_agent (if title was empty or 'Untitled page')\n" .
        "    • Page description was generated using canvas_metadata_generation_agent (if description was empty)\n\n" .
        "  - If canvas_template_builder_agent was used:\n" .
        "    • Page was designed\n" .
        "    • Page title was generated using canvas_title_generation_agent (if title was empty or 'Untitled page')\n" .
        "    • Page description was generated using canvas_metadata_generation_agent (if description was empty)",
      $output
    );
  }

}
