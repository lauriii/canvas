<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\Controller\FrontendsController;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the headless frontend configuration controller.
 */
#[Group('canvas_headless')]
#[RunTestsInSeparateProcesses]
final class FrontendsControllerTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'serialization',
    'consumers',
    'simple_oauth',
    'canvas_headless',
  ];

  /**
   * Tests reading and replacing the ordered frontend list.
   */
  public function testGetAndPatch(): void {
    $this->installConfig(['canvas_headless']);
    $controller = FrontendsController::create($this->container);

    self::assertSame(['frontends' => []], self::decode($controller->get()));

    $response = $controller->patch(self::request([
      'frontends' => [
        ['url' => 'https://first.example/app'],
        ['url' => 'http://127.0.0.1:3000'],
      ],
    ]));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertSame([
      'frontends' => [
        ['url' => 'https://first.example/app'],
        ['url' => 'http://127.0.0.1:3000'],
      ],
    ], self::decode($response));
    self::assertSame(self::decode($response), self::decode($controller->get()));
  }

  /**
   * Tests malformed, invalid, and duplicate entries.
   */
  public function testInvalidPayloads(): void {
    $this->installConfig(['canvas_headless']);
    $controller = FrontendsController::create($this->container);

    $response = $controller->patch(self::request(['frontends' => 'not a list']));
    self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

    $response = $controller->patch(self::request([
      'frontends' => [['url' => 'https://example.com/']],
    ]));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

    $response = $controller->patch(self::request([
      'frontends' => [
        ['url' => 'https://example.com'],
        ['url' => 'HTTPS://EXAMPLE.COM:443'],
      ],
    ]));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertSame([
      'error' => '"HTTPS://EXAMPLE.COM:443" is already in the list.',
    ], self::decode($response));
  }

  /**
   * Builds a JSON request.
   *
   * @param array<string, mixed> $payload
   *   The request payload.
   */
  private static function request(array $payload): Request {
    return Request::create(
      '/canvas/api/v0/headless/frontends',
      'PATCH',
      content: json_encode($payload, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Decodes a controller response.
   *
   * @return array<string, mixed>
   *   The decoded response.
   */
  private static function decode(Response $response): array {
    $content = $response->getContent();
    self::assertIsString($content);
    $decoded = json_decode($content, TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertIsArray($decoded);
    return $decoded;
  }

}
