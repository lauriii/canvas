<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidBody;
use Drupal\canvas\CanvasNotificationHandler;
use Drupal\canvas\Controller\ApiNotificationController;
use Drupal\canvas\Entity\Page;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the ApiNotificationController.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiNotificationController::class)]
#[Group('canvas')]
class ApiNotificationControllerTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;
  use OpenApiSpecTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
  }

  private function handler(): CanvasNotificationHandler {
    return $this->container->get(CanvasNotificationHandler::class);
  }

  public function testGetNotificationsReturnsJsonResponse(): void {
    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    $this->handler()->create([
      'type' => 'info',
      'title' => 'Test notification',
      'message' => 'Test message',
    ]);

    $response = $this->request(Request::create('/canvas/api/v0/notifications'));
    self::assertSame(200, $response->getStatusCode());

    $json = static::decodeResponse($response);
    self::assertArrayHasKey('data', $json);
    self::assertArrayHasKey('notifications', $json['data']);
    self::assertCount(1, $json['data']['notifications']);

    $notification = $json['data']['notifications'][0];
    self::assertSame('info', $notification['type']);
    self::assertSame('Test notification', $notification['title']);
    self::assertSame('Test message', $notification['message']);
    self::assertArrayHasKey('hasRead', $notification);
    self::assertFalse($notification['hasRead']);

    // Validate against OpenAPI spec.
    foreach ($json['data']['notifications'] as $n) {
      $this->assertDataCompliesWithApiSpecification($n, 'Notification');
    }
  }

  public function testGetNotificationsWithActionsPassesValidation(): void {
    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    $this->handler()->create([
      'type' => 'error',
      'title' => 'Import failed',
      'message' => 'Could not connect to remote server.',
      'actions' => [
        ['label' => 'View logs', 'href' => '/admin/reports/dblog'],
        ['label' => 'Retry', 'href' => '/admin/config/content/canvas'],
      ],
    ]);

    $response = $this->request(Request::create('/canvas/api/v0/notifications'));
    self::assertSame(200, $response->getStatusCode());

    $json = static::decodeResponse($response);
    $notification = $json['data']['notifications'][0];
    self::assertSame('Import failed', $notification['title']);
    self::assertIsArray($notification['actions']);
    self::assertCount(2, $notification['actions']);
    self::assertSame('View logs', $notification['actions'][0]['label']);
    self::assertSame('/admin/reports/dblog', $notification['actions'][0]['href']);

    // Validate against OpenAPI spec — this is the key assertion.
    // Prior to the nullable fix, this would fail with:
    // "items MUST be present if the type is array".
    foreach ($json['data']['notifications'] as $n) {
      $this->assertDataCompliesWithApiSpecification($n, 'Notification');
    }
  }

  public function testGetNotificationsWithNullActionsPassesValidation(): void {
    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    $this->handler()->create([
      'type' => 'info',
      'title' => 'No actions',
      'message' => 'This notification has no actions.',
    ]);

    $response = $this->request(Request::create('/canvas/api/v0/notifications'));
    self::assertSame(200, $response->getStatusCode());

    $json = static::decodeResponse($response);
    $notification = $json['data']['notifications'][0];
    self::assertNull($notification['actions']);

    foreach ($json['data']['notifications'] as $n) {
      $this->assertDataCompliesWithApiSpecification($n, 'Notification');
    }
  }

  public function testGetNotificationsIncludesHasReadPerUser(): void {
    $user1 = $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    $n = $this->handler()->create([
      'type' => 'info',
      'title' => 'Shared notification',
      'message' => 'Message',
    ]);

    // Mark as read for user 1.
    $this->handler()->markRead((int) $user1->id(), [$n['id']]);

    // User 1 sees hasRead: true.
    $response = $this->request(Request::create('/canvas/api/v0/notifications'));
    $json = static::decodeResponse($response);
    self::assertTrue($json['data']['notifications'][0]['hasRead']);

    // Switch to user 2.
    $user2 = $this->createUser(['access content', Page::CREATE_PERMISSION]);
    \assert($user2 !== FALSE);
    $this->setCurrentUser($user2);

    // User 2 sees hasRead: false.
    $response = $this->request(Request::create('/canvas/api/v0/notifications'));
    $json = static::decodeResponse($response);
    self::assertFalse($json['data']['notifications'][0]['hasRead']);
  }

  public function testGetNotificationsRequiresAuthentication(): void {
    // Anonymous user — the _canvas_ui_access check denies access before the
    // authentication check can return 401.
    $this->setUpCurrentUser();

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->request(Request::create('/canvas/api/v0/notifications'));
  }

  public function testMarkReadReturns204(): void {
    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    $n = $this->handler()->create([
      'type' => 'info',
      'title' => 'Test',
      'message' => 'Message',
    ]);

    $response = $this->request(Request::create(
      '/canvas/api/v0/notifications/read',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      (string) (string) \json_encode(['ids' => [$n['id']]]),
    ));
    self::assertSame(204, $response->getStatusCode());
  }

  public function testMarkReadUpdatesHasRead(): void {
    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    $n = $this->handler()->create([
      'type' => 'info',
      'title' => 'Test',
      'message' => 'Message',
    ]);

    // Initially unread.
    $response = $this->request(Request::create('/canvas/api/v0/notifications'));
    $json = static::decodeResponse($response);
    self::assertFalse($json['data']['notifications'][0]['hasRead']);

    // Mark as read.
    $this->request(Request::create(
      '/canvas/api/v0/notifications/read',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      (string) \json_encode(['ids' => [$n['id']]]),
    ));

    // Now shows as read.
    $response = $this->request(Request::create('/canvas/api/v0/notifications'));
    $json = static::decodeResponse($response);
    self::assertTrue($json['data']['notifications'][0]['hasRead']);
  }

  public function testMarkAllReadMarksEveryNotificationAsRead(): void {
    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    $n1 = $this->handler()->create(['type' => 'info', 'title' => 'A', 'message' => 'M']);
    $n2 = $this->handler()->create(['type' => 'warning', 'title' => 'B', 'message' => 'M']);
    $n3 = $this->handler()->create(['type' => 'error', 'title' => 'C', 'message' => 'M']);
    $n4 = $this->handler()->create(['type' => 'success', 'title' => 'D', 'message' => 'M']);

    // Mark all as read in a single request.
    $response = $this->request(Request::create(
      '/canvas/api/v0/notifications/read',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      (string) \json_encode(['ids' => [$n1['id'], $n2['id'], $n3['id'], $n4['id']]]),
    ));
    self::assertSame(204, $response->getStatusCode());

    // All notifications should now be read.
    $response = $this->request(Request::create('/canvas/api/v0/notifications'));
    $json = static::decodeResponse($response);
    foreach ($json['data']['notifications'] as $notification) {
      self::assertTrue($notification['hasRead'], \sprintf(
        'Notification "%s" (type: %s) should be marked as read.',
        $notification['title'],
        $notification['type'],
      ));
    }
  }

  public function testMarkAllReadDoesNotAffectOtherUsers(): void {
    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    $n1 = $this->handler()->create(['type' => 'info', 'title' => 'A', 'message' => 'M']);
    $n2 = $this->handler()->create(['type' => 'warning', 'title' => 'B', 'message' => 'M']);

    // User 1 marks all as read.
    $this->request(Request::create(
      '/canvas/api/v0/notifications/read',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      (string) \json_encode(['ids' => [$n1['id'], $n2['id']]]),
    ));

    // Switch to user 2.
    $user2 = $this->createUser(['access content', Page::CREATE_PERMISSION]);
    \assert($user2 !== FALSE);
    $this->setCurrentUser($user2);

    // User 2 should still see them as unread.
    $response = $this->request(Request::create('/canvas/api/v0/notifications'));
    $json = static::decodeResponse($response);
    foreach ($json['data']['notifications'] as $notification) {
      self::assertFalse($notification['hasRead'], \sprintf(
        'Notification "%s" should be unread for user 2.',
        $notification['title'],
      ));
    }
  }

  public function testMarkReadRejectsMissingIds(): void {
    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    // The OpenAPI request validator rejects this before the controller runs.
    $this->expectException(InvalidBody::class);
    $this->request(Request::create(
      '/canvas/api/v0/notifications/read',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      (string) \json_encode(['something' => 'else']),
    ));
  }

  public function testMarkReadRejectsEmptyBody(): void {
    $this->setUpCurrentUser([], ['access content', Page::CREATE_PERMISSION]);

    // The OpenAPI request validator rejects the empty body before the
    // controller runs.
    $this->expectException(InvalidBody::class);
    $this->request(Request::create(
      '/canvas/api/v0/notifications/read',
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      '',
    ));
  }

}
