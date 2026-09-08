<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\Event\PublishedEvent;
use Drupal\canvas_headless\EventSubscriber\PublishWebhookSubscriber;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;

/**
 * Tests the publish webhook subscriber.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class PublishWebhookSubscriberTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
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
    $this->installConfig(['canvas_headless']);
  }

  /**
   * Tests that no request is sent when no webhook URL is configured.
   */
  public function testNoWebhookConfigured(): void {
    $requests = [];
    $subscriber = $this->subscriber($requests);
    $subscriber->onPublished($this->event());
    self::assertSame([], $requests, 'No request is made without a configured URL.');
  }

  /**
   * Tests that a configured webhook receives the signed publish payload.
   */
  public function testWebhookDelivered(): void {
    $secret = 'top-secret-value';
    // The secret lives in State, not config, and works without settings.php
    // access.
    $this->container->get(StateInterface::class)
      ->set(PublishWebhookSubscriber::SECRET_STATE_KEY, $secret);
    $this->config('canvas_headless.settings')
      ->set('publish_webhook', ['url' => 'https://build.example/hook'])
      ->save();

    $requests = [];
    $subscriber = $this->subscriber($requests);
    $subscriber->onPublished($this->event());

    self::assertCount(1, $requests);
    $request = $requests[0]['request'];
    \assert($request instanceof GuzzleRequest);
    self::assertSame('POST', $request->getMethod());
    self::assertSame('https://build.example/hook', (string) $request->getUri());

    $body = (string) $request->getBody();
    $payload = json_decode($body, TRUE, flags: JSON_THROW_ON_ERROR);
    self::assertSame('publish', $payload['event']);
    self::assertSame([
      [
        'entityType' => 'canvas_page',
        'id' => '7',
        'uuid' => 'test-uuid',
        'langcode' => 'en',
      ],
    ], $payload['entities']);
    self::assertSame(['canvas_page:7'], $payload['tags']);

    // The signature is an HMAC-SHA256 of the exact body under the secret.
    self::assertSame(
      'sha256=' . hash_hmac('sha256', $body, $secret),
      $request->getHeaderLine('X-Canvas-Signature'),
    );
  }

  /**
   * Tests that a delivery failure never surfaces to the caller.
   */
  public function testDeliveryFailureIsSwallowed(): void {
    $this->config('canvas_headless.settings')
      ->set('publish_webhook', ['url' => 'https://build.example/hook'])
      ->save();

    // A transport-level failure must be caught, not rethrown, so a broken
    // consumer never turns a successful publish into an error.
    $mock = new MockHandler([new \RuntimeException('Connection refused')]);
    $subscriber = new PublishWebhookSubscriber(
      $this->container->get(ConfigFactoryInterface::class),
      $this->container->get(StateInterface::class),
      new Client(['handler' => HandlerStack::create($mock)]),
      new NullLogger(),
    );
    $subscriber->onPublished($this->event());
    // Reaching here without an exception is the assertion.
    $this->addToAssertionCount(1);
  }

  /**
   * Builds a subscriber whose HTTP client records requests into $requests.
   *
   * @param array<int, array{request: mixed}> $requests
   *   Populated with each request the client sends.
   */
  private function subscriber(array &$requests): PublishWebhookSubscriber {
    $mock = new MockHandler([new GuzzleResponse(200)]);
    $stack = HandlerStack::create($mock);
    $stack->push(function (callable $handler) use (&$requests): callable {
      return function ($request, array $options) use ($handler, &$requests) {
        $requests[] = ['request' => $request];
        return $handler($request, $options);
      };
    });
    return new PublishWebhookSubscriber(
      $this->container->get(ConfigFactoryInterface::class),
      $this->container->get(StateInterface::class),
      new Client(['handler' => $stack]),
      new NullLogger(),
    );
  }

  /**
   * Builds a PublishedEvent referencing one stub entity.
   */
  private function event(): PublishedEvent {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('canvas_page');
    $entity->method('id')->willReturn('7');
    $entity->method('uuid')->willReturn('test-uuid');
    $entity->method('getCacheTagsToInvalidate')->willReturn(['canvas_page:7']);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $entity->method('language')->willReturn($language);
    return new PublishedEvent([$entity]);
  }

}
