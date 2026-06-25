<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the orchestrator agent post-update hooks keep system-managed keys.
 */
#[Group('canvas_ai')]
final class CanvasAiOrchestratorPostUpdateTest extends CanvasKernelTestBase {

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
  }

  /**
   * Tests that the prompt update keeps the uuid and _core hash intact.
   */
  public function testPostUpdate0003PreservesManagedKeys(): void {
    $this->includePostUpdateFile();

    $original = $this->config(self::ORCHESTRATOR);
    $uuid = $original->get('uuid');
    $core = $original->get('_core');
    self::assertNotEmpty($uuid);
    self::assertNotEmpty($core);

    // Change the active prompt so the update has something to overwrite.
    $this->config(self::ORCHESTRATOR)->set('system_prompt', 'stale prompt')->save(TRUE);

    canvas_ai_post_update_0003_reimport_orchestrator_agent();
    $this->container->get('config.factory')->reset(self::ORCHESTRATOR);

    $updated = $this->config(self::ORCHESTRATOR);
    self::assertSame($uuid, $updated->get('uuid'));
    self::assertSame($core, $updated->get('_core'));
    self::assertNotSame('stale prompt', $updated->get('system_prompt'));
  }

  /**
   * Tests that the repair hook restores the uuid and _core stripped by 0003.
   */
  public function testPostUpdate0006RestoresManagedKeys(): void {
    $this->includePostUpdateFile();

    // Simulate the active config left behind by the original 0003, which
    // dropped both the uuid and _core when it replaced the whole record.
    $storage = $this->container->get('config.storage');
    $data = $storage->read(self::ORCHESTRATOR);
    unset($data['uuid'], $data['_core']);
    $storage->write(self::ORCHESTRATOR, $data);
    $this->container->get('config.factory')->reset(self::ORCHESTRATOR);
    self::assertEmpty($this->config(self::ORCHESTRATOR)->get('uuid'));
    self::assertEmpty($this->config(self::ORCHESTRATOR)->get('_core'));

    canvas_ai_post_update_0006_restore_orchestrator_agent_uuid();
    $this->container->get('config.factory')->reset(self::ORCHESTRATOR);

    $repaired = $this->config(self::ORCHESTRATOR);
    self::assertNotEmpty($repaired->get('uuid'));
    self::assertNotEmpty($repaired->get('_core.default_config_hash'));
  }

  /**
   * Includes the Canvas AI post-update file.
   */
  private function includePostUpdateFile(): void {
    $module_path = $this->container->get('extension.list.module')->getPath('canvas_ai');
    require_once DRUPAL_ROOT . '/' . $module_path . '/canvas_ai.post_update.php';
  }

}
