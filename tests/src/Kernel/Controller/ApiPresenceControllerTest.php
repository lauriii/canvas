<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiPresenceController;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Presence\PresenceManager;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use League\OpenAPIValidation\PSR7\Exception\Validation\InvalidBody;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests who-else-is-here presence for items open in the Canvas editor.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiPresenceController::class)]
#[CoversClass(PresenceManager::class)]
#[Group('canvas')]
final class ApiPresenceControllerTest extends CanvasKernelTestBase {

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

  private Page $page;

  private UserInterface $alex;

  private UserInterface $blake;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    // Take uid 1 so neither test user bypasses access.
    $this->createUser();

    $permissions = ['access content', Page::EDIT_PERMISSION];
    $this->alex = $this->createUser($permissions, 'Alex');
    $this->blake = $this->createUser($permissions, 'Blake');

    $page = Page::create(['title' => 'Home']);
    $page->save();
    $this->page = $page;
  }

  private function heartbeat(string $client_instance_id, array $selection = [], bool $active = TRUE): array {
    $response = $this->request(Request::create(
      '/canvas/api/v0/presence/canvas_page/' . $this->page->id(),
      'POST',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: (string) \json_encode([
        'clientInstanceId' => $client_instance_id,
        'selection' => $selection,
        'active' => $active,
      ]),
    ));
    self::assertSame(200, $response->getStatusCode());
    $json = static::decodeResponse($response);
    $this->assertDataCompliesWithApiSpecification($json, 'PresenceRoster');
    return $json;
  }

  private function leave(string $client_instance_id): void {
    $response = $this->request(Request::create(
      '/canvas/api/v0/presence/canvas_page/' . $this->page->id() . '?clientInstanceId=' . $client_instance_id,
      'DELETE',
    ));
    self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
  }

  public function testRoster(): void {
    // Alex opens the page and is alone.
    $this->setCurrentUser($this->alex);
    $json = $this->heartbeat('alex-tab-1', ['uuid-hero']);
    self::assertSame([], $json['sessions']);
    self::assertNull($json['pendingChangesBy']);

    // Blake opens it and sees Alex with their selection.
    $this->setCurrentUser($this->blake);
    $json = $this->heartbeat('blake-tab-1', active: FALSE);
    self::assertCount(1, $json['sessions']);
    self::assertSame([
      'clientInstanceId' => 'alex-tab-1',
      'self' => FALSE,
      'selection' => ['uuid-hero'],
      'active' => TRUE,
      'uid' => (int) $this->alex->id(),
      'displayName' => 'Alex',
      'avatar' => NULL,
    ], $json['sessions'][0]);

    // Alex sees Blake as idle, and their own second tab as "self".
    $this->setCurrentUser($this->alex);
    $this->heartbeat('alex-tab-2');
    $json = $this->heartbeat('alex-tab-1');
    $sessions = \array_column($json['sessions'], NULL, 'clientInstanceId');
    self::assertEqualsCanonicalizing(['blake-tab-1', 'alex-tab-2'], \array_keys($sessions));
    self::assertFalse($sessions['blake-tab-1']['active']);
    self::assertFalse($sessions['blake-tab-1']['self']);
    self::assertTrue($sessions['alex-tab-2']['self']);

    // Blake cannot end Alex's session.
    $this->setCurrentUser($this->blake);
    $this->leave('alex-tab-2');
    self::assertCount(2, $this->heartbeat('blake-tab-1')['sessions']);

    // Alex can.
    $this->setCurrentUser($this->alex);
    $this->leave('alex-tab-2');
    $this->setCurrentUser($this->blake);
    self::assertSame(['alex-tab-1'], \array_column($this->heartbeat('blake-tab-1')['sessions'], 'clientInstanceId'));

    // Sessions expire without a heartbeat.
    $store = $this->container->get(KeyValueExpirableFactoryInterface::class)
      ->get('canvas.presence.' . AutoSaveManager::getAutoSaveKey($this->page));
    \assert($store instanceof KeyValueStoreExpirableInterface);
    $store->setWithExpire('alex-tab-1', ['uid' => (int) $this->alex->id(), 'selection' => [], 'active' => TRUE], -1);
    self::assertSame([], $this->heartbeat('blake-tab-1')['sessions']);
  }

  public function testPendingChangesBy(): void {
    $this->setCurrentUser($this->alex);
    $this->page->set('title', 'Changed');
    $this->container->get(AutoSaveManager::class)->saveEntity($this->page, 'alex-tab-1');

    $this->setCurrentUser($this->blake);
    $json = $this->heartbeat('blake-tab-1');
    self::assertSame('alex-tab-1', $json['pendingChangesBy']['clientInstanceId']);
    self::assertSame('Alex', $json['pendingChangesBy']['displayName']);
    self::assertSame((int) $this->alex->id(), $json['pendingChangesBy']['uid']);
  }

  public function testAccessAndValidation(): void {
    $viewer = $this->createUser(['access content', Page::CREATE_PERMISSION]);
    $this->setCurrentUser($viewer);
    try {
      $this->heartbeat('viewer-tab');
      $this->fail('Expected access denied.');
    }
    catch (AccessDeniedHttpException) {
    }

    $this->setCurrentUser($this->alex);
    try {
      $this->heartbeat('not a valid id');
      $this->fail('Expected a bad request.');
    }
    // The OpenAPI request validator, active in tests, rejects it first.
    catch (InvalidBody) {
    }
  }

}
