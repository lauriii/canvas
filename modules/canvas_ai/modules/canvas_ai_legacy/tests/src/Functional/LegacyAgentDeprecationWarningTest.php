<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai_legacy\Functional;

use Drupal\canvas_ai_legacy\Hook\CanvasAiLegacyHooks;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the deprecation warning on the legacy agents' edit forms.
 */
#[Group('canvas')]
#[Group('canvas_ai')]
#[RunTestsInSeparateProcesses]
final class LegacyAgentDeprecationWarningTest extends BrowserTestBase {

  private const WARNING = 'This agent is deprecated.';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_ai',
    'canvas_ai_legacy',
    'ai',
    'ai_agents',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $user = $this->drupalCreateUser(['modeler api edit ai_agents_agent']);
    \assert($user instanceof AccountInterface);
    $this->drupalLogin($user);
  }

  /**
   * Tests that only the legacy agents' edit forms show the warning.
   */
  public function testWarningOnLegacyAgentsOnly(): void {
    foreach (CanvasAiLegacyHooks::LEGACY_AGENT_IDS as $agent_id) {
      $this->drupalGet(Url::fromRoute('entity.ai_agent.edit_form', ['ai_agent' => $agent_id]));
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->statusMessageContains(self::WARNING, 'warning');
    }

    foreach (['canvas_component_agent', 'drupal_canvas_page_agent', 'canvas_agent'] as $agent_id) {
      $this->drupalGet(Url::fromRoute('entity.ai_agent.edit_form', ['ai_agent' => $agent_id]));
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->statusMessageNotContains(self::WARNING);
    }
  }

}
