<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas\Entity\Page;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the public route inventory endpoint.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class RouteInventoryControllerTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  private const string PATH = '/canvas/api/v0/headless/inventory';

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
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->installConfig(['simple_oauth', 'canvas_headless']);
    // Anonymous users may view published content, matching a real site.
    $this->setUpCurrentUser();
    user_role_grant_permissions('anonymous', ['access content']);
    $this->setCurrentUser(new AnonymousUserSession());
  }

  /**
   * Tests that only published pages are listed, anonymously.
   */
  public function testListsPublishedPagesOnly(): void {
    $published = self::createPage('Published', TRUE);
    self::createPage('Unpublished', FALSE);

    $data = $this->inventory();
    self::assertCount(1, $data['paths']);
    self::assertNull($data['cursor']['next']);

    $entry = $data['paths'][0];
    self::assertSame('/page/' . $published->id(), $entry['path']);
    self::assertSame('canvas_page', $entry['entityType']);
    self::assertSame((string) $published->id(), $entry['id']);
    self::assertSame($published->uuid(), $entry['uuid']);
    self::assertSame('en', $entry['langcode']);
    self::assertIsString($entry['changed']);
  }

  /**
   * Tests that the configured front page is emitted as an extra "/" entry.
   */
  public function testFrontPageEntry(): void {
    $front = self::createPage('Home', TRUE);
    $this->config('system.site')
      ->set('page.front', '/page/' . $front->id())
      ->save();

    $paths = array_column($this->inventory()['paths'], 'path');
    self::assertContains('/page/' . $front->id(), $paths);
    self::assertContains('/', $paths);
  }

  /**
   * Tests that the keyset cursor paginates without gaps or duplicates.
   */
  public function testCursorPagination(): void {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
      $ids[] = (string) self::createPage("Page $i", TRUE)->id();
    }

    $seen = [];
    $cursor = NULL;
    // A generous loop bound guards against a cursor that never terminates.
    for ($request = 0; $request < 10; $request++) {
      $query = ['limit' => 2] + ($cursor !== NULL ? ['cursor' => $cursor] : []);
      $data = $this->inventory($query);
      foreach ($data['paths'] as $entry) {
        $seen[] = $entry['id'];
      }
      $cursor = $data['cursor']['next'];
      if ($cursor === NULL) {
        break;
      }
    }

    self::assertNull($cursor, 'The walk terminated.');
    self::assertSame($ids, $seen, 'Every page appeared exactly once, in id order.');
  }

  /**
   * Tests that a malformed cursor is rejected.
   */
  public function testInvalidCursor(): void {
    $response = $this->request(Request::create(self::PATH . '?cursor=not-a-cursor'));
    self::assertSame(400, $response->getStatusCode());
  }

  /**
   * Requests the inventory and returns the decoded payload.
   *
   * @param array<string, mixed> $query
   *   Query parameters.
   *
   * @return array<string, mixed>
   *   The decoded JSON payload.
   */
  private function inventory(array $query = []): array {
    $url = self::PATH . ($query === [] ? '' : '?' . http_build_query($query));
    $response = $this->request(Request::create($url));
    self::assertSame(200, $response->getStatusCode());
    $content = $response->getContent();
    self::assertIsString($content);
    return json_decode($content, TRUE, flags: JSON_THROW_ON_ERROR);
  }

  private static function createPage(string $title, bool $published): Page {
    $page = Page::create([
      'title' => $title,
      'status' => $published,
    ]);
    $page->save();
    return $page;
  }

}
