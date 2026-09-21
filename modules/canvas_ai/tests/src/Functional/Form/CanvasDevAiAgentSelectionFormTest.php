<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Functional\Form;

use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Functional test for the Canvas Dev AI Agents & Tools settings form.
 */
#[Group('canvas')]
#[Group('canvas_ai')]
final class CanvasDevAiAgentSelectionFormTest extends BrowserTestBase {

  /**
   * The route name for the form.
   */
  private const ROUTE_NAME = 'canvas_dev_ai.agent_selection';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'canvas',
    'canvas_ai',
    'canvas_dev_ai',
    'ai',
    'ai_agents',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Local tasks only render when the block is placed, and the test profile
    // places no blocks.
    $this->drupalPlaceBlock('local_tasks_block');
    $admin_user = $this->drupalCreateUser(['use Drupal Canvas AI']);
    \assert($admin_user instanceof AccountInterface);
    $this->drupalLogin($admin_user);
  }

  /**
   * Tests that the Agents & Tools tab is available under the settings route.
   */
  public function testLocalTaskIsAvailable(): void {
    // Resolve the path while the module — and so its route — still exists.
    $tab_path = Url::fromRoute(self::ROUTE_NAME)->toString();

    $this->drupalGet(Url::fromRoute('canvas_ai.setting'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists($tab_path);

    \Drupal::service(ModuleInstallerInterface::class)->uninstall(['canvas_dev_ai']);

    $this->drupalGet(Url::fromRoute('canvas_ai.setting'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefNotExists($tab_path);
  }

  /**
   * Tests that the form renders and saves every value.
   */
  public function testFormSavesAllValues(): void {
    $this->drupalGet(Url::fromRoute(self::ROUTE_NAME));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Agents & Tools');
    // The shipped main agent must be offered, otherwise saving the form would
    // silently replace it.
    $this->assertSession()->optionExists('main_agent', 'canvas_agent');
    // Keeping tool calls and results between turns costs tokens, so it is
    // off until a site turns it on here.
    $this->assertSession()->checkboxNotChecked('keep_tool_calls_in_history');

    $this->submitForm([
      'main_agent' => 'drupal_canvas_page_agent',
      'tools[canvas_agent]' => FALSE,
      'tools[canvas_component_agent]' => TRUE,
      'tools[drupal_canvas_page_agent]' => FALSE,
      'keep_tool_calls_in_history' => TRUE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('canvas_dev_ai.settings');
    $this->assertSame('drupal_canvas_page_agent', $config->get('main_agent'));
    $this->assertSame(['canvas_component_agent'], $config->get('tools'));
    $this->assertTrue($config->get('keep_tool_calls_in_history'));
    $this->assertSession()->checkboxChecked('keep_tool_calls_in_history');
  }

  /**
   * Tests that the main agent cannot also be selected as a Tool.
   */
  public function testMainAgentCannotAlsoBeSelectedAsTool(): void {
    $this->drupalGet(Url::fromRoute(self::ROUTE_NAME));

    $this->submitForm([
      'main_agent' => 'canvas_agent',
      'tools[canvas_agent]' => TRUE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The main agent cannot also be offered as a Tool.');
  }

}
