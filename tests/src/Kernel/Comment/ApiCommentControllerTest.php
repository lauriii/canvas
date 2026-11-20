<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Comment;

use Drupal\canvas\Controller\ApiCommentController;
use Drupal\canvas\Entity\Comment;
use Drupal\canvas\Entity\CommentThread;
use Drupal\canvas\Entity\Page;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the comment HTTP API.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('canvas_comments')]
#[CoversClass(ApiCommentController::class)]
final class ApiCommentControllerTest extends CanvasKernelTestBase {

  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_test_page',
    'field',
  ];

  private const string URL = '/canvas/api/v0/comments';
  private const string COMPONENT_UUID = '16176e0b-8197-40e3-ad49-48f1b6e9a7f9';

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
    $this->installEntitySchema('user');
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('media');
    $this->installEntitySchema(CommentThread::ENTITY_TYPE_ID);
    $this->installEntitySchema(Comment::ENTITY_TYPE_ID);
    $this->installConfig(['system', 'field', 'filter', 'path_alias']);
    // Claim uid 1, so that no test user below is the all-powerful super user.
    $this->createUser();

    $page = Page::create([
      'title' => 'A page worth commenting on',
      'components' => [],
      'status' => TRUE,
      'path' => ['alias' => '/commented-page'],
    ]);
    $page->save();
    $this->page = $page;

    // This user may comment and view the page, but holds no Canvas Page
    // permission.
    $commenter = $this->setUpCurrentUser([], [
      'access content',
      CommentThread::VIEW_PERMISSION,
      CommentThread::CREATE_PERMISSION,
    ]);
    self::assertInstanceOf(UserInterface::class, $commenter);
    $this->commenter = $commenter;
  }

  /**
   * Tests creating, replying to, resolving, reopening and listing threads.
   */
  public function testCommentLifecycle(): void {
    $response = $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => self::COMPONENT_UUID,
      'body' => 'Should this be a heading?',
    ]);
    self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $thread = self::decodeResponse($response)['thread'];
    self::assertSame('1', $thread['id']);
    self::assertSame(Page::ENTITY_TYPE_ID, $thread['surfaceType']);
    self::assertSame((string) $this->page->id(), $thread['surfaceId']);
    self::assertSame(self::COMPONENT_UUID, $thread['componentUuid']);
    self::assertFalse($thread['resolved']);
    self::assertIsInt($thread['created']);
    self::assertSame([
      'uid' => (int) $this->commenter->id(),
      'displayName' => $this->commenter->getDisplayName(),
    ], $thread['author']);
    self::assertCount(1, $thread['comments']);
    self::assertSame('1', $thread['comments'][0]['id']);
    self::assertSame('Should this be a heading?', $thread['comments'][0]['body']);

    // Reply: 201, and the full thread comes back with both comments.
    $response = $this->post(self::URL . '/' . $thread['id'] . '/replies', [
      'body' => 'A paragraph reads better.',
    ]);
    self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $thread = self::decodeResponse($response)['thread'];
    self::assertCount(2, $thread['comments']);
    self::assertSame(
      ['Should this be a heading?', 'A paragraph reads better.'],
      \array_column($thread['comments'], 'body'),
    );

    self::assertSame([$thread['id']], $this->listThreadIds());

    // Resolve: 200, and the comments survive.
    $response = $this->patch(self::URL . '/' . $thread['id'], ['resolved' => TRUE]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $resolved = self::decodeResponse($response)['thread'];
    self::assertTrue($resolved['resolved']);
    self::assertCount(2, $resolved['comments']);

    // The listing includes resolved threads.
    self::assertSame([$thread['id']], $this->listThreadIds());

    // Reopen: 200.
    $response = $this->patch(self::URL . '/' . $thread['id'], ['resolved' => FALSE]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $reopened = self::decodeResponse($response)['thread'];
    self::assertFalse($reopened['resolved']);
    self::assertCount(2, $reopened['comments']);
  }

  /**
   * Tests that a thread can be anchored to the surface instead of a component.
   */
  public function testSurfaceLevelThread(): void {
    $response = $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => NULL,
      'body' => 'Where is the call to action?',
    ]);
    self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    self::assertNull(self::decodeResponse($response)['thread']['componentUuid']);
  }

  /**
   * Tests that where in a component a comment was left round-trips.
   */
  public function testOffsetRoundTrips(): void {
    $response = $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => self::COMPONENT_UUID,
      'offsetX' => 0.25,
      'offsetY' => 0.75,
      'body' => 'Left three quarters of the way down.',
    ]);
    self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $thread = self::decodeResponse($response)['thread'];
    self::assertSame(0.25, $thread['offsetX']);
    self::assertSame(0.75, $thread['offsetY']);

    // A thread with no point recorded reports none.
    $response = $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => self::COMPONENT_UUID,
      'body' => 'Started from the sidebar.',
    ]);
    $thread = self::decodeResponse($response)['thread'];
    self::assertNull($thread['offsetX']);
    self::assertNull($thread['offsetY']);
  }

  /**
   * Tests that invalid input is a 422, never a 500, and stores nothing.
   *
   * @param string $method
   *   The request method.
   * @param string $path
   *   The path below the comment collection. Thread 1 exists.
   * @param array $body
   *   The request body. A request that starts a thread is completed with
   *   valid values.
   * @param non-empty-string $pointer_prefix
   *   What the pointer of the reported error starts with.
   * @param bool $skip_openapi_validation
   *   Whether to bypass the OpenAPI request validator, which does not run in
   *   production, to reach the controller's own type guard.
   */
  #[DataProvider('providerInvalidInput')]
  public function testInvalidInputIsUnprocessable(string $method, string $path, array $body, string $pointer_prefix, bool $skip_openapi_validation): void {
    $valid = [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => self::COMPONENT_UUID,
      'body' => 'Valid.',
    ];
    self::assertSame('1', self::decodeResponse($this->post(self::URL, $valid))['thread']['id']);

    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($skip_openapi_validation) {
      $server['HTTP_X_NO_OPENAPI_VALIDATION'] = '1';
    }
    $response = $this->request(Request::create(
      self::URL . $path,
      $method,
      server: $server,
      content: $method === 'GET' ? NULL : (string) \json_encode($path === '' ? $body + $valid : $body, JSON_THROW_ON_ERROR),
    ));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertStringStartsWith($pointer_prefix, self::decodeResponse($response)['errors'][0]['source']['pointer']);
    self::assertSame(['1'], $this->listThreadIds());
  }

  public static function providerInvalidInput(): array {
    return [
      'empty body' => ['POST', '', ['body' => ''], 'body', FALSE],
      'whitespace body' => ['POST', '', ['body' => '   '], 'body', FALSE],
      'whitespace reply' => ['POST', '/1/replies', ['body' => '  '], 'body', FALSE],
      'component UUID: not a UUID' => ['POST', '', ['componentUuid' => 'not-a-real-uuid'], 'componentUuid', FALSE],
      'component UUID: number' => ['POST', '', ['componentUuid' => '12345'], 'componentUuid', FALSE],
      'component UUID: truncated' => ['POST', '', ['componentUuid' => \substr(self::COMPONENT_UUID, 0, 23)], 'componentUuid', FALSE],
      'offset right of the component' => ['POST', '', ['offsetX' => 1.5, 'offsetY' => 0.5], 'offset', FALSE],
      'offset above the component' => ['POST', '', ['offsetX' => 0.5, 'offsetY' => -0.2], 'offset', FALSE],
      'offset without Y' => ['POST', '', ['offsetX' => 0.5], 'offset', FALSE],
      'offset without X' => ['POST', '', ['offsetY' => 0.5], 'offset', FALSE],
      'offset without a component' => ['POST', '', ['componentUuid' => NULL, 'offsetX' => 0.5, 'offsetY' => 0.5], 'offset', FALSE],
      'offset that is not a number' => ['POST', '', ['offsetX' => 'middle', 'offsetY' => 0.5], 'offset', TRUE],
      'unsupported surface type' => ['POST', '', ['surfaceType' => 'node', 'componentUuid' => NULL], 'surfaceType', FALSE],
      'unsupported surface type, listing' => ['GET', '?surfaceType=nonsense&surfaceId=1', [], 'surfaceType', FALSE],
      'non-boolean resolved' => ['PATCH', '/1', ['resolved' => 'yes'], 'resolved', TRUE],
    ];
  }

  /**
   * Tests that commenting on a surface that does not exist is a 404.
   */
  public function testUnknownSurfaceIsNotFound(): void {
    $this->expectException(NotFoundHttpException::class);
    $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => '99999',
      'componentUuid' => NULL,
      'body' => 'This page does not exist.',
    ]);
  }

  /**
   * Tests that page edit access alone grants no access to the comment API.
   */
  public function testPageEditAccessDoesNotGrantCommentAccess(): void {
    $page_editor = $this->createUser(['access content', Page::EDIT_PERMISSION]);
    self::assertInstanceOf(UserInterface::class, $page_editor);
    $this->setCurrentUser($page_editor);

    $this->expectException(AccessDeniedHttpException::class);
    $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => NULL,
      'body' => 'Editing is not commenting.',
    ]);
  }

  /**
   * Tests that listing comment threads requires the view permission.
   */
  public function testListingRequiresViewPermission(): void {
    $nobody = $this->createUser(['access content']);
    self::assertInstanceOf(UserInterface::class, $nobody);
    $this->setCurrentUser($nobody);

    $this->expectException(AccessDeniedHttpException::class);
    $this->request(Request::create(\sprintf(
      '%s?surfaceType=%s&surfaceId=%s',
      self::URL,
      Page::ENTITY_TYPE_ID,
      $this->page->id(),
    ), 'GET'));
  }

  /**
   * Tests that the mention autocomplete offers only comment readers.
   */
  public function testMentionableUsersAreScopedToCommentReaders(): void {
    // Every candidate needs `access content` too, to view the published page.
    $reader = $this->createUser(['access content', CommentThread::VIEW_PERMISSION], 'mentionable-reader');
    $writer = $this->createUser(['access content', CommentThread::CREATE_PERMISSION], 'mentionable-writer');
    $editor_without_comments = $this->createUser(['access content', Page::EDIT_PERMISSION], 'mentionable-editor');
    $blocked = $this->createUser(['access content', CommentThread::VIEW_PERMISSION], 'mentionable-blocked');
    self::assertInstanceOf(UserInterface::class, $blocked);
    $blocked->block()->save();
    // More accounts than the result limit, none of them commenters, and all
    // sorting ahead of the reader.
    for ($i = 0; $i < 25; $i++) {
      $this->createUser([], \sprintf('aaa-bystander-%02d', $i));
    }

    $response = $this->request(Request::create($this->mentionableUsersUrl(), 'GET'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $names = \array_column(self::decodeResponse($response)['users'], 'displayName');

    self::assertContains($reader->getAccountName(), $names);
    // Creating comments includes reading them.
    self::assertContains($writer->getAccountName(), $names);
    self::assertNotContains($editor_without_comments->getAccountName(), $names);
    self::assertNotContains($blocked->getAccountName(), $names);
    // The anonymous user is never mentionable.
    self::assertNotContains('', $names);
  }

  /**
   * Tests that the mention autocomplete filters by what has been typed.
   */
  public function testMentionableUsersAreFilteredByQuery(): void {
    $this->createUser(['access content', CommentThread::VIEW_PERMISSION], 'zoe-reviewer');
    $this->createUser(['access content', CommentThread::VIEW_PERMISSION], 'quentin-reviewer');

    $response = $this->request(Request::create($this->mentionableUsersUrl('zoe'), 'GET'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $names = \array_column(self::decodeResponse($response)['users'], 'displayName');
    self::assertSame(['zoe-reviewer'], $names);
  }

  /**
   * Tests that a mention is stored by ID and resolved to a name at read time.
   */
  public function testMentionsResolveAtReadTime(): void {
    $mentioned = $this->createUser(['access content', CommentThread::VIEW_PERMISSION], 'original-name');
    self::assertInstanceOf(UserInterface::class, $mentioned);

    $response = $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => NULL,
      'body' => \sprintf('Can @[user:%d] look, and @[user:9999] who is gone?', $mentioned->id()),
    ]);
    self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    $mentions = self::decodeResponse($response)['thread']['comments'][0]['mentions'];
    self::assertSame([
      ['uid' => (int) $mentioned->id(), 'displayName' => 'original-name'],
      ['uid' => 9999, 'displayName' => NULL],
    ], $mentions);

    // The stored comment follows a rename.
    $mentioned->setUsername('renamed')->save();
    $response = $this->request(Request::create(\sprintf(
      '%s?surfaceType=%s&surfaceId=%s',
      self::URL,
      Page::ENTITY_TYPE_ID,
      $this->page->id(),
    ), 'GET'));
    $threads = self::decodeResponse($response)['threads'];
    self::assertSame('renamed', $threads[0]['comments'][0]['mentions'][0]['displayName']);
  }

  /**
   * Tests that the autocomplete honors view access to the surface itself.
   */
  public function testMentionableUsersRespectSurfaceAccess(): void {
    // Both hold the comment permission. Only one of them can view the page.
    $can_view_page = $this->createUser(['access content', CommentThread::VIEW_PERMISSION], 'reader-with-page-access');
    $cannot_view_page = $this->createUser([CommentThread::VIEW_PERMISSION], 'reader-without-page-access');
    self::assertInstanceOf(UserInterface::class, $can_view_page);
    self::assertInstanceOf(UserInterface::class, $cannot_view_page);
    self::assertTrue($this->page->access('view', $can_view_page));
    self::assertFalse($this->page->access('view', $cannot_view_page));

    $names = \array_column(
      self::decodeResponse($this->request(Request::create($this->mentionableUsersUrl(), 'GET')))['users'],
      'displayName',
    );

    self::assertContains($can_view_page->getAccountName(), $names);
    self::assertNotContains($cannot_view_page->getAccountName(), $names);
  }

  /**
   * Builds the mention autocomplete URL for the test page.
   */
  private function mentionableUsersUrl(string $query = ''): string {
    return \sprintf(
      '%s/mentionable-users?surfaceType=%s&surfaceId=%s&q=%s',
      self::URL,
      Page::ENTITY_TYPE_ID,
      $this->page->id(),
      \urlencode($query),
    );
  }

  /**
   * Lists the thread IDs on the test page.
   */
  private function listThreadIds(): array {
    $url = \sprintf(
      '%s?surfaceType=%s&surfaceId=%s',
      self::URL,
      Page::ENTITY_TYPE_ID,
      $this->page->id(),
    );
    $response = $this->request(Request::create($url, 'GET'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    return \array_column(self::decodeResponse($response)['threads'], 'id');
  }

  /**
   * Sends a JSON POST request.
   */
  private function post(string $url, array $body): Response {
    return $this->request(Request::create(
      $url,
      'POST',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: (string) \json_encode($body, JSON_THROW_ON_ERROR),
    ));
  }

  /**
   * Sends a JSON PATCH request.
   */
  private function patch(string $url, array $body): Response {
    return $this->request(Request::create(
      $url,
      'PATCH',
      server: ['CONTENT_TYPE' => 'application/json'],
      content: (string) \json_encode($body, JSON_THROW_ON_ERROR),
    ));
  }

}
