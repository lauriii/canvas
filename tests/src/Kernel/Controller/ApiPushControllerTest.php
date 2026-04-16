<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\Controller\ApiPushController;
use Drupal\canvas\Event\PushEvent;
use Drupal\canvas\Push\PushStatus;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests ApiPushController.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiPushController::class)]
#[Group('canvas')]
class ApiPushControllerTest extends CanvasKernelTestBase implements EventSubscriberInterface {

  use UserCreationTrait;
  use RequestTrait;

  /**
   * Events captured by this subscriber.
   *
   * @var \Drupal\canvas\Event\PushEvent[]
   */
  private array $capturedEvents = [];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PushEvent::class => 'onPushEvent'];
  }

  public function onPushEvent(PushEvent $event): void {
    $this->capturedEvents[] = $event;
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('canvas_test.push_event_subscriber', static::class)
      ->setSynthetic(TRUE)
      ->addTag('event_subscriber');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->container->set('canvas_test.push_event_subscriber', $this);
    $this->setUpCurrentUser([], ['administer code components']);
  }

  public function testStartReturns204AndDispatchesEvent(): void {
    $response = $this->request(Request::create(
      '/canvas/api/v0/push/start',
      'POST',
    ));
    self::assertSame(204, $response->getStatusCode());

    self::assertCount(1, $this->capturedEvents);
    self::assertSame(PushStatus::Started, $this->capturedEvents[0]->status);
  }

  public function testCompleteReturns204AndDispatchesEvent(): void {
    $response = $this->request(Request::create(
      '/canvas/api/v0/push/complete',
      'POST',
    ));
    self::assertSame(204, $response->getStatusCode());

    self::assertCount(1, $this->capturedEvents);
    self::assertSame(PushStatus::Completed, $this->capturedEvents[0]->status);
  }

  public function testFailReturns204AndDispatchesEvent(): void {
    $response = $this->request(Request::create(
      '/canvas/api/v0/push/fail',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      '{}',
    ));
    self::assertSame(204, $response->getStatusCode());

    self::assertCount(1, $this->capturedEvents);
    self::assertSame(PushStatus::Failed, $this->capturedEvents[0]->status);
  }

  public function testStartCreatesProcessingNotification(): void {
    $this->request(Request::create('/canvas/api/v0/push/start', 'POST'));

    $notifications = $this->container->get('database')
      ->select('canvas_notification', 'n')
      ->fields('n', ['type', 'key', 'title'])
      ->execute()
      ?->fetchAllAssoc('key') ?? [];

    self::assertArrayHasKey('cli-push', $notifications);
    self::assertSame('processing', $notifications['cli-push']->type);
    self::assertSame('Push in progress', $notifications['cli-push']->title);
  }

  public function testCompleteCreatesSuccessNotification(): void {
    $this->request(Request::create('/canvas/api/v0/push/start', 'POST'));
    $this->request(Request::create('/canvas/api/v0/push/complete', 'POST'));

    $notifications = $this->container->get('database')
      ->select('canvas_notification', 'n')
      ->fields('n', ['type', 'key', 'title'])
      ->execute()
      ?->fetchAllAssoc('key') ?? [];

    self::assertArrayHasKey('cli-push', $notifications);
    self::assertSame('success', $notifications['cli-push']->type);
    self::assertSame('Push completed', $notifications['cli-push']->title);
  }

  public function testFailCreatesErrorNotificationWithDefaultMessage(): void {
    $this->request(Request::create(
      '/canvas/api/v0/push/fail',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      '{}',
    ));

    $notifications = $this->container->get('database')
      ->select('canvas_notification', 'n')
      ->fields('n', ['type', 'key', 'message'])
      ->execute()
      ?->fetchAllAssoc('key') ?? [];

    self::assertArrayHasKey('cli-push', $notifications);
    self::assertSame('error', $notifications['cli-push']->type);
    self::assertSame('The CLI push did not complete successfully.', $notifications['cli-push']->message);
  }

  public function testFailCreatesErrorNotificationWithProvidedMessage(): void {
    $this->request(Request::create(
      '/canvas/api/v0/push/fail',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      (string) \json_encode(['message' => 'Build step failed: missing dependency']),
    ));

    $notifications = $this->container->get('database')
      ->select('canvas_notification', 'n')
      ->fields('n', ['type', 'key', 'message'])
      ->execute()
      ?->fetchAllAssoc('key') ?? [];

    self::assertArrayHasKey('cli-push', $notifications);
    self::assertSame('error', $notifications['cli-push']->type);
    self::assertSame('Build step failed: missing dependency', $notifications['cli-push']->message);
  }

}
