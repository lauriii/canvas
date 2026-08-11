<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ai\Kernel;

use Drupal\canvas_ai\CanvasAiPermissions;
use Drupal\canvas_ai\Controller\CanvasBuilder;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the request guards of the Canvas AI controller.
 */
#[Group('canvas_ai')]
#[CoversClass(CanvasBuilder::class)]
final class CanvasAiBuilderControllerTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_ai',
    'ai',
    'ai_agents',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['canvas_ai']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->setUpCurrentUser(permissions: [CanvasAiPermissions::USE_CANVAS_AI]);
  }

  /**
   * Tests that a request without usable layout regions is refused.
   */
  #[DataProvider('providerLayoutsWithoutRegions')]
  public function testRequestWithoutLayoutRegionsIsRefused(array $payload): void {
    $response = $this->postToBuilder($payload + [
      'messages' => [['role' => 'user', 'text' => 'Add a hero.']],
    ]);

    $this->assertSame([
      'status' => FALSE,
      'message' => 'Unable to read the page layout. Please reload the page and try again.',
    ], static::decodeResponse($response));
  }

  /**
   * Provides payloads whose layout carries no regions.
   */
  public static function providerLayoutsWithoutRegions(): array {
    return [
      'layout key absent' => [[]],
      'layout is null' => [['current_layout' => NULL]],
      'regions key empty' => [['current_layout' => ['regions' => []]]],
    ];
  }

  /**
   * Posts a JSON payload to the Canvas AI controller.
   */
  private function postToBuilder(array $payload): Response {
    $request = Request::create(
      '/admin/api/canvas/ai',
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
