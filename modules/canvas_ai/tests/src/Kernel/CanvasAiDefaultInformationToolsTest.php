<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Tests that special characters in user content do not break getDefaultInformationTools().
 *
 * @see \Drupal\canvas_ai\Hook\CanvasAiHooks::canvas_ai_tokens()
 * @see \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper::getDefaultInformationTools()
 * @see https://www.drupal.org/project/canvas/issues/3572865
 */
#[Group('canvas_ai')]
final class CanvasAiDefaultInformationToolsTest extends CanvasKernelTestBase {

  use UserCreationTrait;

  protected static $modules = [
    'ai',
    'ai_agents',
    'canvas_ai',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['ai_agents', 'canvas_ai']);
    $this->container->set('plugin.manager.ai_agents', NULL);
    $this->setUpCurrentUser(['uid' => 1], [], TRUE);
  }

  public static function agentProvider(): array {
    return [
      ['canvas_title_generation_agent'],
      ['canvas_metadata_generation_agent'],
    ];
  }

  #[DataProvider('agentProvider')]
  public function testSpecialCharactersInDefaultInformationTools(string $agent_id): void {
    /** @var \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface&\Drupal\ai_agents\PluginBase\AiAgentEntityWrapper $agent */
    $agent = $this->container->get('plugin.manager.ai_agents')->createInstance($agent_id);
    $agent->setRunnerId('test-123');

    $layout = json_encode([
      '33b0c50f' => [
        'text' => "<p>Drupal's power: \"building sites\" — <no> limits! It's 100% open. #CMS 🚀\nSecure & fast.</p>",
      ],
    ]);

    $agent->setTokenContexts([
      'layout'           => $layout,
      'page_title'       => "What's new in Drupal? It's everything! <Core> & Modules. 100% #OpenSource 🎉",
      'page_description' => "What's new in Drupal? It's everything! <Core> & Modules. 100% #OpenSource 🎉",
    ]);

    try {
      $agent->getDefaultInformationTools();
    }
    catch (ParseException $e) {
      $this->fail($e->getMessage());
    }

    $this->addToAssertionCount(1);
  }

}
