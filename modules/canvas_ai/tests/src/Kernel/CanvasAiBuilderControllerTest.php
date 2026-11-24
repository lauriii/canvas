<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\CanvasAiTempStore;
use Drupal\canvas_ai_legacy\Controller\CanvasAiLegacyBuilder;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the request guards of the Canvas AI controller.
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasAiLegacyBuilder::class)]
#[RunTestsInSeparateProcesses]
final class CanvasAiBuilderControllerTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
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
    $this->installConfig(['canvas_ai', 'canvas_ai_legacy']);
    // The agent manager snapshots config-based agents when constructed, which
    // happened before the agent config was installed.
    $this->container->set('plugin.manager.ai_agents', NULL);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->setUpCurrentUser(permissions: [CanvasAiPermissions::USE_CANVAS_AI]);
  }

  /**
   * Tests that a missing layout is not refused by the controller.
   *
   * Agents needing regions report the problem themselves; the controller does
   * not guess intent from the prompt or the payload.
   */
  #[DataProvider('providerRequestsWithoutLayout')]
  public function testRequestWithoutLayoutRegionsIsNotRefused(array $payload, ?array $expected_stored_layout): void {
    $response = $this->postToBuilder($payload + [
      'request_id' => 'test-request',
    ]);

    $this->assertNotSame('Unable to read the page layout. Please reload the page and try again.', static::decodeResponse($response)['message'] ?? NULL);

    // Only a layout with regions is stored for the agents.
    $stored = $this->container->get(CanvasAiTempStore::class)->getData(CanvasAiTempStore::CURRENT_LAYOUT_KEY);
    $this->assertSame($expected_stored_layout === NULL ? NULL : Json::encode($expected_stored_layout), $stored);
  }

  /**
   * Provides requests without layout regions, however they are worded.
   */
  public static function providerRequestsWithoutLayout(): array {
    $message = static fn (string $text): array => [['role' => 'user', 'text' => $text]];
    return [
      'code component prompt' => [['messages' => $message('Create a code component with multivalue props')], NULL],
      'page builder prompt' => [['messages' => $message('Add a hero section to the page.')], NULL],
      'differently worded' => [['messages' => $message('Make me a button component'), 'current_layout' => ['regions' => []]], NULL],
      'in code' => [['messages' => $message('Create a card in code'), 'current_layout' => NULL], NULL],
      'layout with regions' => [
        ['messages' => $message('Add a hero section.'), 'current_layout' => ['regions' => ['content' => ['components' => []]]]],
        ['regions' => ['content' => ['components' => []]]],
      ],
      'selected component' => [['messages' => $message('Change the heading to blue.'), 'selected_component' => 'some-uuid'], NULL],
    ];
  }

  /**
   * Posts a JSON payload to the Canvas AI controller.
   */
  private function postToBuilder(array $payload): Response {
    $request = Request::create(
      '/admin/api/canvas/ai-legacy',
      'POST',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: (string) \json_encode($payload),
    );
    $session_configuration = $this->container->get(SessionConfigurationInterface::class)->getOptions($request);
    $request->cookies->set($session_configuration['name'], 'ABCD');
    $this->container->get('session')->start();
    $request->headers->set('X-CSRF-Token', $this->container->get(CsrfTokenGenerator::class)->get('canvas_ai.canvas_builder'));

    return $this->request($request);
  }

}
