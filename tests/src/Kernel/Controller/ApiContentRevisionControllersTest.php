<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Controller\ApiContentRevisionControllers;
use Drupal\canvas\Controller\ApiLayoutController;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\canvas\Traits\AutoSaveRequestTestTrait;
use Drupal\Tests\canvas\Traits\OpenApiSpecTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Tests a page's history: listing, previewing and restoring revisions.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[CoversClass(ApiContentRevisionControllers::class)]
#[CoversMethod(ApiLayoutController::class, 'getRevision')]
final class ApiContentRevisionControllersTest extends CanvasKernelTestBase {

  use AutoSaveRequestTestTrait;
  use OpenApiSpecTrait;
  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
  ];

  /**
   * A page with two revisions: "About", then "About us" (live).
   */
  private Page $page;

  /**
   * The page's first revision.
   */
  private int $firstRevision;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'field', 'filter', 'path_alias']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->setUpCurrentUser([], ['access content', Page::EDIT_PERMISSION, AutoSaveManager::PUBLISH_PERMISSION]);

    $this->page = Page::create(['title' => 'About', 'status' => TRUE, 'components' => []]);
    $this->page->save();
    $this->firstRevision = (int) $this->page->getRevisionId();
    $this->page->set('title', 'About us');
    $this->page->setNewRevision();
    $this->page->save();
  }

  /**
   * The history lists revisions newest first, marking the live one.
   */
  public function testList(): void {
    $data = $this->getJson('/canvas/api/v0/content/canvas_page/1/revisions');
    self::assertTrue($data['canRestore']);
    self::assertSame(['About us', 'About'], \array_column($data['revisions'], 'title'));
    self::assertSame([TRUE, FALSE], \array_column($data['revisions'], 'live'));
    self::assertNull($data['draft']);

    // Unpublished changes are on top of the live version.
    $this->autoSaveManager()->saveEntity($this->storedPage()->set('title', 'About our team'));
    $draft = $this->getJson('/canvas/api/v0/content/canvas_page/1/revisions')['draft'];
    self::assertSame($this->container->get(AccountProxyInterface::class)->getDisplayName(), $draft['author']);
    self::assertIsInt($draft['updated']);
    foreach ($data['revisions'] as $revision) {
      $this->assertDataCompliesWithApiSpecification($revision, 'PageRevision');
    }

    // Revisions are for people who may edit the page.
    $this->setUpCurrentUser([], ['access content']);
    self::assertSame(Response::HTTP_FORBIDDEN, $this->statusOf('/canvas/api/v0/content/canvas_page/1/revisions'));
  }

  /**
   * A revision can be previewed, without becoming a draft.
   */
  public function testPreview(): void {
    $data = $this->getJson("/canvas/api/v0/layout/canvas_page/1/revisions/{$this->firstRevision}");
    self::assertSame($this->firstRevision, $data['revision']);
    self::assertStringContainsString('canvas-region-start-content', $data['html']);
    self::assertTrue($this->autoSaveManager()->getAutoSaveEntity($this->page)->isEmpty(), 'Previewing creates no draft.');

    // Only revisions of this page.
    $other = Page::create(['title' => 'Other', 'status' => TRUE, 'components' => []]);
    $other->save();
    self::assertSame(Response::HTTP_NOT_FOUND, $this->statusOf("/canvas/api/v0/layout/canvas_page/1/revisions/{$other->getRevisionId()}"));
  }

  /**
   * Restoring makes a revision the draft, and publishes nothing.
   */
  public function testRestore(): void {
    $data = $this->postJson("/canvas/api/v0/content/canvas_page/1/revisions/{$this->firstRevision}/restore");
    self::assertTrue($data['hasDraft']);
    $draft = $this->autoSaveManager()->getAutoSaveEntity($this->page, bypass_cache: TRUE)->entity;
    self::assertSame('About', $draft?->label());
    self::assertSame('About us', $this->storedPage()->label(), 'Nothing is published.');

    // What is live is not a version to go back to.
    self::assertSame(Response::HTTP_FORBIDDEN, $this->statusOf("/canvas/api/v0/content/canvas_page/1/revisions/{$this->storedPage()->getRevisionId()}/restore", 'POST'));
  }

  /**
   * Publishing dates the new revision, and does not repeat an old log message.
   */
  public function testPublishStartsAFreshRevision(): void {
    $page = $this->storedPage();
    $page->setRevisionLogMessage('Imported');
    $page->setRevisionCreationTime(1000);
    $page->setNewRevision();
    $page->save();

    $this->postJson("/canvas/api/v0/content/canvas_page/1/revisions/{$this->firstRevision}/restore");
    self::assertSame(Response::HTTP_OK, $this->makePublishAllRequest()->getStatusCode());
    $published = $this->storedPage();
    self::assertSame('About', $published->label());
    self::assertEmpty($published->getRevisionLogMessage());
    self::assertNotSame(1000, (int) $published->getRevisionCreationTime());
  }

  /**
   * GETs a URL as the current user and decodes the JSON response.
   *
   * @return array<string, mixed>
   *   The decoded response.
   */
  private function getJson(string $url): array {
    $response = $this->request(Request::create($url));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    return self::decodeResponse($response);
  }

  /**
   * POSTs to a URL as the current user and decodes the JSON response.
   *
   * @return array<string, mixed>
   *   The decoded response.
   */
  private function postJson(string $url): array {
    $response = $this->request(Request::create($url, 'POST'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    return self::decodeResponse($response);
  }

  /**
   * The status code of a request, whether it returns or throws.
   */
  private function statusOf(string $url, string $method = 'GET'): int {
    try {
      return $this->request(Request::create($url, $method))->getStatusCode();
    }
    catch (HttpExceptionInterface $e) {
      return $e->getStatusCode();
    }
  }

  /**
   * The page as stored, without drafts.
   */
  private function storedPage(): Page {
    $page = $this->container->get(EntityTypeManagerInterface::class)->getStorage('canvas_page')->loadUnchanged(1);
    \assert($page instanceof Page);
    return $page;
  }

  /**
   * The auto-save manager.
   */
  private function autoSaveManager(): AutoSaveManager {
    return $this->container->get(AutoSaveManager::class);
  }

}
