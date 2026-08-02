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

    // This user may comment, and may only *view* the page: it holds no Canvas
    // Page permission at all. Commenting must never require edit access.
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
    self::assertIsInt($thread['changed']);
    self::assertSame([
      'uid' => (int) $this->commenter->id(),
      'displayName' => $this->commenter->getDisplayName(),
      'avatar' => NULL,
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

    // Unresolved threads are listed by default.
    self::assertSame([$thread['id']], $this->listThreadIds());

    // Resolve: 200, and the comments survive.
    $response = $this->patch(self::URL . '/' . $thread['id'], ['resolved' => TRUE]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $resolved = self::decodeResponse($response)['thread'];
    self::assertTrue($resolved['resolved']);
    self::assertCount(2, $resolved['comments']);

    // Resolved threads are excluded by default, and included on request.
    self::assertSame([], $this->listThreadIds());
    self::assertSame([$thread['id']], $this->listThreadIds('1'));

    // Reopen: 200, and the thread is listed again.
    $response = $this->patch(self::URL . '/' . $thread['id'], ['resolved' => FALSE]);
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $reopened = self::decodeResponse($response)['thread'];
    self::assertFalse($reopened['resolved']);
    self::assertCount(2, $reopened['comments']);
    self::assertSame([$thread['id']], $this->listThreadIds());
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
   * Tests that a blank comment body is a 422, never a 500.
   */
  public function testBlankBodyIsUnprocessable(): void {
    foreach (['', '   '] as $blank_body) {
      $response = $this->post(self::URL, [
        'surfaceType' => Page::ENTITY_TYPE_ID,
        'surfaceId' => (string) $this->page->id(),
        'componentUuid' => self::COMPONENT_UUID,
        'body' => $blank_body,
      ]);
      self::assertNotSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
      self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
      $errors = self::decodeResponse($response)['errors'];
      self::assertNotSame([], $errors);
      self::assertStringStartsWith('body', $errors[0]['source']['pointer']);
    }
    // None of the rejected requests created a thread.
    self::assertSame([], $this->listThreadIds('1'));

    // A blank reply is rejected the same way.
    $response = $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => self::COMPONENT_UUID,
      'body' => 'Valid.',
    ]);
    $thread_id = self::decodeResponse($response)['thread']['id'];
    $response = $this->post(self::URL . '/' . $thread_id . '/replies', ['body' => '  ']);
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
  }

  /**
   * Tests that a malformed component UUID is a 422, never a 500.
   *
   * `componentUuid` is declared `format: uuid` in openapi.yml, so a malformed
   * value that reaches the OpenAPI request validator throws instead of being
   * reported as invalid input.
   */
  public function testMalformedComponentUuidIsUnprocessable(): void {
    $malformed_values = [
      'not-a-real-uuid',
      '12345',
      // A truncated UUID: the right alphabet, the wrong shape.
      \substr(self::COMPONENT_UUID, 0, 23),
    ];
    foreach ($malformed_values as $malformed) {
      $response = $this->post(self::URL, [
        'surfaceType' => Page::ENTITY_TYPE_ID,
        'surfaceId' => (string) $this->page->id(),
        'componentUuid' => $malformed,
        'body' => 'Anchored to a malformed UUID.',
      ]);
      self::assertNotSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
      self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
      self::assertSame(
        'componentUuid',
        self::decodeResponse($response)['errors'][0]['source']['pointer'],
      );
    }
    // None of the rejected requests created a thread.
    self::assertSame([], $this->listThreadIds('1'));
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

    // A thread with no point recorded reports none, rather than a corner
    // dressed up as one.
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
   * Tests that an offset outside the component's box is a 422.
   */
  public function testUnusableOffsetIsUnprocessable(): void {
    $cases = [
      // Outside the box in either direction is not a point on the component.
      ['offsetX' => 1.5, 'offsetY' => 0.5],
      ['offsetX' => 0.5, 'offsetY' => -0.2],
      // Half a point is not a point.
      ['offsetX' => 0.5],
      ['offsetY' => 0.5],
    ];
    foreach ($cases as $case) {
      $response = $this->post(self::URL, $case + [
        'surfaceType' => Page::ENTITY_TYPE_ID,
        'surfaceId' => (string) $this->page->id(),
        'componentUuid' => self::COMPONENT_UUID,
        'body' => 'Anchored nowhere in particular.',
      ]);
      self::assertNotSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
      self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
      self::assertStringStartsWith(
        'offset',
        self::decodeResponse($response)['errors'][0]['source']['pointer'],
      );
    }

    // A fraction of nothing: there is no box to be a fraction of.
    $response = $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => NULL,
      'offsetX' => 0.5,
      'offsetY' => 0.5,
      'body' => 'On the page as a whole, but at a point?',
    ]);
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

    // An offset that is not a number at all is caught by the OpenAPI request
    // validator, which only runs outside production. Bypassing it proves the
    // controller guards the type itself, which is what production relies on.
    $response = $this->request(Request::create(
      self::URL,
      'POST',
      server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NO_OPENAPI_VALIDATION' => '1',
      ],
      content: (string) \json_encode([
        'surfaceType' => Page::ENTITY_TYPE_ID,
        'surfaceId' => (string) $this->page->id(),
        'componentUuid' => self::COMPONENT_UUID,
        'offsetX' => 'middle',
        'offsetY' => 0.5,
        'body' => 'Anchored nowhere in particular.',
      ], JSON_THROW_ON_ERROR),
    ));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

    self::assertSame([], $this->listThreadIds('1'));
  }

  /**
   * Tests that an unsupported surface type is a 422, never a 500.
   */
  public function testUnsupportedSurfaceTypeIsUnprocessable(): void {
    $response = $this->post(self::URL, [
      'surfaceType' => 'node',
      'surfaceId' => '1',
      'componentUuid' => NULL,
      'body' => 'Nodes are not a Canvas surface.',
    ]);
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertSame(
      'surfaceType',
      self::decodeResponse($response)['errors'][0]['source']['pointer'],
    );

    $response = $this->request(Request::create(self::URL . '?surfaceType=nonsense&surfaceId=1', 'GET'));
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
  }

  /**
   * Tests that a non-boolean `resolved` is a 422, never a 500.
   */
  public function testNonBooleanResolvedIsUnprocessable(): void {
    $response = $this->post(self::URL, [
      'surfaceType' => Page::ENTITY_TYPE_ID,
      'surfaceId' => (string) $this->page->id(),
      'componentUuid' => NULL,
      'body' => 'Valid.',
    ]);
    $thread_id = self::decodeResponse($response)['thread']['id'];

    // `openapi.yml` types `resolved` as a boolean, so the OpenAPI request
    // validator rejects this body before the controller sees it. Disable that
    // validator to reach the controller's own defensive guard.
    $response = $this->request(Request::create(
      self::URL . '/' . $thread_id,
      'PATCH',
      server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_NO_OPENAPI_VALIDATION' => '1',
      ],
      content: (string) \json_encode(['resolved' => 'yes'], JSON_THROW_ON_ERROR),
    ));
    self::assertNotSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
    self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    self::assertSame(
      'resolved',
      self::decodeResponse($response)['errors'][0]['source']['pointer'],
    );
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
    // Every candidate needs `access content` too: eligibility is the comment
    // permission *and* view access to the surface, and without the former a
    // published page is not viewable either.
    $reader = $this->createUser(['access content', CommentThread::VIEW_PERMISSION], 'mentionable-reader');
    $editor_without_comments = $this->createUser(['access content', Page::EDIT_PERMISSION], 'mentionable-editor');
    $blocked = $this->createUser(['access content', CommentThread::VIEW_PERMISSION], 'mentionable-blocked');
    self::assertInstanceOf(UserInterface::class, $blocked);
    $blocked->block()->save();

    $response = $this->request(Request::create($this->mentionableUsersUrl(), 'GET'));
    self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    $names = \array_column(self::decodeResponse($response)['users'], 'displayName');

    // Someone who can read comments can be named in one.
    self::assertContains($reader->getAccountName(), $names);
    // Someone who can edit the page but not read comments cannot: they would
    // be named in a conversation they have no way to open.
    self::assertNotContains($editor_without_comments->getAccountName(), $names);
    // A blocked account can never be reached, so offering it is misleading.
    self::assertNotContains($blocked->getAccountName(), $names);
    // The anonymous user is never mentionable.
    self::assertNotContains('', $names);
    self::assertSame([], \array_filter($names, static fn (string $name): bool => $name === ''));
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

    // Renaming the user changes what the same stored comment renders as: the
    // body records who was named, never what they were called at the time.
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
   * Tests that the autocomplete honours view access to the surface itself.
   */
  public function testMentionableUsersRespectSurfaceAccess(): void {
    // Both hold the comment permission. Only one of them can view content, so
    // only one of them can open the page the thread would live on.
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
    // Naming somebody in a conversation on a page they cannot open would be an
    // empty gesture, so the comment permission alone is not enough.
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
  private function listThreadIds(string $include_resolved = '0'): array {
    $url = \sprintf(
      '%s?surfaceType=%s&surfaceId=%s&includeResolved=%s',
      self::URL,
      Page::ENTITY_TYPE_ID,
      $this->page->id(),
      $include_resolved,
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
