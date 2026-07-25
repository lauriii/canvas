<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Functional\Comment;

use Drupal\canvas\Entity\CommentThread;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Url;
use Drupal\Tests\canvas\Functional\HttpApiTestBase;
use Drupal\Tests\canvas\Traits\ContribStrictConfigSchemaTestTrait;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the comment HTTP API over real HTTP.
 *
 * The bulk of the API coverage lives in the much faster kernel test; this test
 * proves the happy path and the access rules work through the full stack.
 *
 * @see \Drupal\Tests\canvas\Kernel\Comment\ApiCommentControllerTest
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_comments')]
final class ApiCommentControllerTest extends HttpApiTestBase {

  use ContribStrictConfigSchemaTestTrait;

  private const string PATH = '/canvas/api/v0/comments';
  private const string COMPONENT_UUID = '16176e0b-8197-40e3-ad49-48f1b6e9a7f9';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['canvas'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The published page that all threads in this test are anchored to.
   */
  private Page $page;

  /**
   * The user that may comment, but may only view the page.
   */
  private UserInterface $commenter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $page = Page::create([
      'title' => 'A page worth commenting on',
      'components' => [],
      'status' => TRUE,
    ]);
    $page->save();
    $this->page = $page;

    // This user may comment, and may only *view* the page: it holds no Canvas
    // Page permission at all. Commenting must never require edit access.
    $commenter = $this->createUser([
      'access content',
      CommentThread::VIEW_PERMISSION,
      CommentThread::CREATE_PERMISSION,
    ]);
    self::assertInstanceOf(UserInterface::class, $commenter);
    $this->commenter = $commenter;
    $this->drupalLogin($this->commenter);
  }

  /**
   * Tests creating, replying to, resolving and listing threads over HTTP.
   */
  public function testCommentLifecycle(): void {
    $response = $this->apiRequest('POST', self::url(), [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => self::COMPONENT_UUID,
      'body' => 'Should this be a heading?',
    ]);
    self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $thread = self::decode($response)['thread'];
    self::assertSame(Page::ENTITY_TYPE_ID, $thread['surfaceType']);
    self::assertSame((string) $this->page->id(), $thread['surfaceId']);
    self::assertSame(self::COMPONENT_UUID, $thread['componentUuid']);
    self::assertFalse($thread['resolved']);
    self::assertSame((int) $this->commenter->id(), $thread['author']['uid']);
    self::assertSame($this->commenter->getDisplayName(), $thread['author']['displayName']);
    self::assertNull($thread['author']['avatar']);
    self::assertCount(1, $thread['comments']);

    $thread_id = $thread['id'];
    $response = $this->apiRequest('POST', self::url($thread_id . '/replies'), [
      'body' => 'A paragraph reads better.',
    ]);
    self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    self::assertSame(
      ['Should this be a heading?', 'A paragraph reads better.'],
      \array_column(self::decode($response)['thread']['comments'], 'body'),
    );

    self::assertSame([$thread_id], $this->listThreadIds());

    $response = $this->apiRequest('PATCH', self::url($thread_id), ['resolved' => TRUE]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertTrue(self::decode($response)['thread']['resolved']);

    // Resolved threads are excluded by default, and included on request.
    self::assertSame([], $this->listThreadIds());
    self::assertSame([$thread_id], $this->listThreadIds('1'));

    $response = $this->apiRequest('PATCH', self::url($thread_id), ['resolved' => FALSE]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    self::assertFalse(self::decode($response)['thread']['resolved']);
    self::assertCount(2, self::decode($response)['thread']['comments']);
    self::assertSame([$thread_id], $this->listThreadIds());
  }

  /**
   * Tests that semantically invalid input is a 422, never a 500.
   */
  public function testInvalidInputIsUnprocessable(): void {
    $response = $this->apiRequest('POST', self::url(), [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => NULL,
      'body' => '   ',
    ]);
    self::assertNotSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertStringStartsWith('body', self::decode($response)['errors'][0]['source']['pointer']);

    $response = $this->apiRequest('POST', self::url(), [
      'surfaceType' => 'node',
      'surfaceId' => '1',
      'componentUuid' => NULL,
      'body' => 'Nodes are not a Canvas surface.',
    ]);
    self::assertNotSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertSame('surfaceType', self::decode($response)['errors'][0]['source']['pointer']);

    // Nothing was stored.
    self::assertSame([], $this->listThreadIds('1'));
  }

  /**
   * Tests that the comment API is closed to users without the permissions.
   */
  public function testAccess(): void {
    // Unauthenticated: 401, because every Canvas API route requires a session.
    $this->drupalLogout();
    $response = $this->apiRequest('GET', self::listUrl());
    self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

    // Authenticated, but holding page edit access instead of any comment
    // permission: 403. Editing a page never implies commenting on it.
    $page_editor = $this->createUser(['access content', Page::EDIT_PERMISSION]);
    self::assertInstanceOf(UserInterface::class, $page_editor);
    $this->drupalLogin($page_editor);
    $response = $this->apiRequest('GET', self::listUrl());
    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    $response = $this->apiRequest('POST', self::url(), [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => NULL,
      'body' => 'Editing is not commenting.',
    ]);
    self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
  }

  /**
   * Lists the thread IDs on the test page.
   */
  private function listThreadIds(string $include_resolved = '0'): array {
    $response = $this->apiRequest('GET', self::listUrl($include_resolved));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    return \array_column(self::decode($response)['threads'], 'id');
  }

  /**
   * Builds the comment collection URL, optionally with a trailing path.
   */
  private static function url(string $suffix = ''): Url {
    return Url::fromUri('base:' . self::PATH . ($suffix === '' ? '' : '/' . $suffix));
  }

  /**
   * Builds the comment listing URL for the test page.
   */
  private function listUrl(string $include_resolved = '0'): Url {
    return self::url()->setOption('query', [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'includeResolved' => $include_resolved,
    ]);
  }

  /**
   * Performs a JSON request against the comment API.
   */
  private function apiRequest(string $method, Url $url, ?array $json = NULL): ResponseInterface {
    $request_options = [
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->drupalGet('session/token'),
      ],
    ];
    if ($json !== NULL) {
      $request_options[RequestOptions::JSON] = $json;
    }
    return $this->makeApiRequest($method, $url, $request_options);
  }

  /**
   * Decodes a JSON response body.
   */
  private static function decode(ResponseInterface $response): array {
    $body = (string) $response->getBody();
    self::assertJson($body);
    return \json_decode($body, associative: TRUE, flags: JSON_THROW_ON_ERROR);
  }

}
