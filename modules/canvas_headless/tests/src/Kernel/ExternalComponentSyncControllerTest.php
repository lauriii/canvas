<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\CanvasNotificationHandler;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_headless\Controller\ExternalComponentSyncController;
use Drupal\canvas_headless\ExternalComponentSync;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the browser-coordinated external component sync controller.
 */
#[Group('canvas_headless')]
#[RunTestsInSeparateProcesses]
final class ExternalComponentSyncControllerTest extends CanvasKernelTestBase {

  private const string FRONTEND_URL = 'https://frontend.example';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'serialization',
    'custom_elements',
    'consumers',
    'simple_oauth',
    'canvas_headless',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['canvas_headless']);
    $this->config('canvas_headless.settings')
      ->set('frontends', [['url' => self::FRONTEND_URL]])
      ->save();
  }

  /**
   * Tests the processing and successful completion lifecycle.
   */
  public function testStartAndComplete(): void {
    $controller = $this->controller();
    $response = $controller->start(self::request([
      'frontendUrl' => self::FRONTEND_URL,
    ]));
    self::assertSame(200, $response->getStatusCode());
    self::assertSame([
      'assertion' => 'test-assertion',
      'metadataPath' => '/api/canvas/components',
    ], self::decode($response));
    self::assertSame('processing', $this->latestNotification()['type']);

    $response = $controller->complete(self::request([
      'frontendUrl' => self::FRONTEND_URL,
      'payload' => self::metadataPayload(),
    ]));
    self::assertSame(200, $response->getStatusCode());
    self::assertSame([
      'created' => 1,
      'updated' => 0,
      'unchanged' => 0,
      'warnings' => [],
      'errors' => [],
    ], self::decode($response)['result']);
    self::assertInstanceOf(JavaScriptComponent::class, JavaScriptComponent::load('hello-card'));
    $notification = $this->latestNotification();
    self::assertSame('success', $notification['type']);
    self::assertSame('Component sync completed', $notification['title']);
    self::assertSame('Created 1, updated 0, and left 0 unchanged.', $notification['message']);
  }

  /**
   * Tests recording a browser metadata fetch failure.
   */
  public function testFail(): void {
    $response = $this->controller()->fail(self::request([
      'frontendUrl' => self::FRONTEND_URL,
      'message' => 'The metadata endpoint answered 503.',
    ]));
    self::assertSame(200, $response->getStatusCode());
    $notification = $this->latestNotification();
    self::assertSame('error', $notification['type']);
    self::assertSame('Component sync failed', $notification['title']);
    self::assertStringContainsString('The metadata endpoint answered 503.', $notification['message']);
  }

  /**
   * Creates the controller with a deterministic assertion generator.
   */
  private function controller(): ExternalComponentSyncController {
    $preview_url_generator = new class() implements PreviewUrlGeneratorInterface {

      public function generateForPath(string $path): ?Url {
        return NULL;
      }

      public function issueForPath(string $path, bool $renewal = FALSE, array $preview_context = []): string {
        return 'test-assertion';
      }

    };

    return new ExternalComponentSyncController(
      $this->container->get(ConfigFactoryInterface::class),
      $this->container->get(CanvasNotificationHandler::class),
      $preview_url_generator,
      $this->container->get(ExternalComponentSync::class),
    );
  }

  /**
   * Creates a JSON request.
   *
   * @param array<string, mixed> $data
   *   The request data.
   */
  private static function request(array $data): Request {
    return Request::create(
      '/canvas/api/v0/headless/components/sync',
      'POST',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: json_encode($data, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Decodes a JSON response.
   *
   * @return array<string, mixed>
   *   The response data.
   */
  private static function decode(JsonResponse $response): array {
    $data = json_decode((string) $response->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertIsArray($data);
    return $data;
  }

  /**
   * Gets the newest notification.
   *
   * @return array{type: string, title: string, message: string}
   *   The notification data.
   */
  private function latestNotification(): array {
    $row = $this->container->get('database')
      ->select('canvas_notification', 'n')
      ->fields('n', ['type', 'title', 'message'])
      ->orderBy('timestamp', 'DESC')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ?->fetchAssoc();
    self::assertIsArray($row);
    return [
      'type' => (string) $row['type'],
      'title' => (string) $row['title'],
      'message' => (string) $row['message'],
    ];
  }

  /**
   * Builds a valid metadata payload.
   *
   * @return array<string, mixed>
   *   The payload.
   */
  private static function metadataPayload(): array {
    return [
      'version' => 1,
      'components' => [[
        'machineName' => 'hello-card',
        'name' => 'Hello Card',
        'status' => TRUE,
        'required' => ['title'],
        'props' => [
          'title' => [
            'type' => 'string',
            'title' => 'Title',
            'examples' => ['Hello'],
          ],
        ],
        'slots' => [],
        'relativeDirectory' => 'hello-card',
      ],
      ],
      'warnings' => [],
    ];
  }

}
