<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas_ai_legacy\Hook\CanvasAiLegacyHooks;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the updates that move the legacy agents and install the chat agents.
 */
#[Group('canvas_ai')]
#[RunTestsInSeparateProcesses]
final class CanvasAiAgentsUpdateTest extends CanvasKernelTestBase {

  private const ORCHESTRATOR = 'ai_agents.ai_agent.canvas_ai_orchestrator';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_agents',
    'canvas_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_ai']);
    $this->installEntitySchema('path_alias');
    $this->installSchema('user', ['users_data']);

    $extension_list = $this->container->get(ModuleExtensionList::class);
    $module_path = DRUPAL_ROOT . '/' . $extension_list->getPath('canvas_ai');
    require_once $module_path . '/canvas_ai.install';
    require_once $module_path . '/canvas_ai.post_update.php';

    // Create the legacy agents as a site that installed them with canvas_ai
    // has them: enforced on canvas_ai, and with a customized prompt.
    $source = new FileStorage(DRUPAL_ROOT . '/' . $extension_list->getPath('canvas_ai_legacy') . '/config/optional');
    $agent_storage = $this->container->get(EntityTypeManagerInterface::class)->getStorage('ai_agent');
    foreach (CanvasAiLegacyHooks::LEGACY_AGENT_IDS as $agent_id) {
      $name = "ai_agents.ai_agent.$agent_id";
      $data = $source->read($name);
      self::assertIsArray($data);
      $data['dependencies']['enforced']['module'] = ['canvas_ai'];
      if ($name === self::ORCHESTRATOR) {
        $data['system_prompt'] = 'customized prompt';
      }
      $agent_storage->create($data)->save();
    }
  }

  /**
   * Tests that an existing site keeps its legacy agents until the uninstall.
   */
  public function testLegacyAgentsMoveToCanvasAiLegacy(): void {
    self::assertFalse($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas_ai_legacy'));

    canvas_ai_update_11200();

    // Installing the module leaves the stored agents as they were.
    self::assertTrue($this->container->get(ModuleHandlerInterface::class)->moduleExists('canvas_ai_legacy'));
    self::assertSame('customized prompt', $this->config(self::ORCHESTRATOR)->get('system_prompt'));
    self::assertSame(['canvas_ai'], $this->config(self::ORCHESTRATOR)->get('dependencies.enforced.module'));

    canvas_ai_post_update_0015_enforce_legacy_agent_dependency();

    foreach (CanvasAiLegacyHooks::LEGACY_AGENT_IDS as $agent_id) {
      $name = "ai_agents.ai_agent.$agent_id";
      $dependencies = $this->config($name)->get('dependencies');
      self::assertSame(['canvas_ai_legacy'], $dependencies['enforced']['module'], $name);
      self::assertContains('canvas_ai_legacy', $dependencies['module'], $name);
    }
    self::assertSame('customized prompt', $this->config(self::ORCHESTRATOR)->get('system_prompt'));

    $this->container->get(ModuleInstallerInterface::class)->uninstall(['canvas_ai_legacy']);

    // Uninstalling the module deletes the legacy agents only.
    foreach (CanvasAiLegacyHooks::LEGACY_AGENT_IDS as $agent_id) {
      self::assertTrue($this->config("ai_agents.ai_agent.$agent_id")->isNew(), $agent_id);
    }
    self::assertFalse($this->config('ai_agents.ai_agent.canvas_component_agent')->isNew());
  }

  /**
   * Tests that an existing site gets the chat agents and their settings.
   */
  public function testChatAgentsAreInstalled(): void {
    // Remove the chat agents and setting that installConfig() created.
    foreach (['canvas_agent', 'drupal_canvas_page_agent'] as $agent_id) {
      $agent = $this->config("ai_agents.ai_agent.$agent_id");
      self::assertFalse($agent->isNew(), $agent_id);
      $agent->delete();
    }
    $this->config('canvas_ai.settings')->clear('keep_tool_calls_in_history')->save();
    self::assertNull($this->config('canvas_ai.settings')->get('main_agent'));

    canvas_ai_post_update_0016_install_chat_agents();

    foreach (['canvas_agent', 'drupal_canvas_page_agent'] as $agent_id) {
      $agent = $this->config("ai_agents.ai_agent.$agent_id");
      self::assertFalse($agent->isNew(), $agent_id);
      self::assertSame(['canvas_ai'], $agent->get('dependencies.enforced.module'), $agent_id);
    }
    $settings = $this->config('canvas_ai.settings');
    self::assertSame('canvas_agent', $settings->get('main_agent'));
    self::assertSame(['canvas_component_agent', 'drupal_canvas_page_agent'], $settings->get('tools'));
    self::assertFalse($settings->get('keep_tool_calls_in_history'));
  }

}
