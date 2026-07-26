<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\Comment;
use Drupal\canvas\Entity\CommentThread;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * HTTP API controller for Canvas comment threads.
 *
 * Every endpoint requires view access to the commented surface, and never
 * update access: commenting is independent of editing.
 *
 * Semantically invalid input never results in a 500: it either reaches an
 * entity constraint, or is reported through the same JSON:API-style error
 * objects that entity constraint violations produce.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These
 *   controllers and associated routes may change at any time.
 */
final class ApiCommentController extends ApiControllerBase {

  /**
   * The entity type IDs of the surfaces that can be commented on.
   *
   * @todo Support the `page_region`, `pattern` and `content_template` surfaces.
   */
  private const array SUPPORTED_SURFACE_TYPES = [Page::ENTITY_TYPE_ID];

  /**
   * How many users the mention autocomplete offers at once.
   */
  private const int MENTION_RESULT_LIMIT = 20;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Lists the comment threads anchored to one surface.
   */
  public function list(Request $request): JsonResponse {
    $surface_type = $request->query->get('surfaceType');
    $surface_id = $request->query->get('surfaceId');
    $surface = $this->loadSurface(
      \is_string($surface_type) ? $surface_type : '',
      \is_string($surface_id) ? $surface_id : '',
    );
    if ($surface instanceof JsonResponse) {
      return $surface;
    }

    $storage = $this->entityTypeManager->getStorage(CommentThread::ENTITY_TYPE_ID);
    // Access is enforced by the route's `view canvas comments` permission plus
    // the view access check on the surface performed above.
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('surface_type', $surface->getEntityTypeId())
      ->condition('surface_id', (string) $surface->id())
      ->sort('created')
      ->sort('id');
    if (!self::parseBoolean($request->query->get('includeResolved'))) {
      $query->condition('resolved', 0);
    }
    $ids = $query->execute();
    $threads = $storage->loadMultiple($ids);

    $normalized = [];
    foreach ($ids as $id) {
      $thread = $threads[$id] ?? NULL;
      if ($thread instanceof CommentThread) {
        $normalized[] = $this->normalizeThread($thread);
      }
    }

    return new JsonResponse(['threads' => $normalized]);
  }

  /**
   * Starts a new comment thread, with its first comment.
   */
  public function post(Request $request): JsonResponse {
    $data = static::decode($request);
    $surface_type = $data['surfaceType'] ?? NULL;
    $surface_id = $data['surfaceId'] ?? NULL;
    $surface = $this->loadSurface(
      \is_string($surface_type) ? $surface_type : '',
      \is_string($surface_id) ? $surface_id : '',
    );
    if ($surface instanceof JsonResponse) {
      return $surface;
    }
    $component_uuid = $data['componentUuid'] ?? NULL;
    if ($component_uuid !== NULL && !\is_string($component_uuid)) {
      return self::createInputViolationResponse('componentUuid', 'This value must be a string or null.');
    }
    // The anchor is validated here rather than by `format: uuid` in
    // openapi.yml, because the OpenAPI request validator runs only outside
    // production and rethrows: it would turn a malformed UUID into a 500 in
    // development while leaving it entirely unchecked in production. An empty
    // string is rejected too; a thread anchored to the surface itself is
    // addressed with NULL.
    if (\is_string($component_uuid) && !Uuid::isValid($component_uuid)) {
      return self::createInputViolationResponse('componentUuid', 'This value must be a UUID.');
    }

    // @todo Verify that `componentUuid` matches a component instance in the surface's component tree.
    $thread = CommentThread::create([
      'surface_type' => $surface->getEntityTypeId(),
      'surface_id' => (string) $surface->id(),
      'component_uuid' => $component_uuid,
      'uid' => $this->currentUser->id(),
    ]);
    $comment = Comment::create([
      'body' => self::getBody($data),
      'uid' => $this->currentUser->id(),
    ]);

    // The `thread` reference can only be populated once the thread has an ID,
    // so its violations are irrelevant until then.
    $comment_violations = $comment->validate()->filterByFields(['thread']);
    $response = static::createJsonResponseFromViolationSets($thread->validate(), $comment_violations);
    if ($response instanceof JsonResponse) {
      return $response;
    }

    $thread->save();
    $comment->set('thread', $thread->id());
    $comment->save();

    return new JsonResponse(['thread' => $this->normalizeThread($thread)], Response::HTTP_CREATED);
  }

  /**
   * Appends a comment to an existing thread.
   */
  public function reply(Request $request, CommentThread $canvas_comment_thread): JsonResponse {
    $data = static::decode($request);
    $surface = $this->loadSurface($canvas_comment_thread->getSurfaceType(), $canvas_comment_thread->getSurfaceId());
    if ($surface instanceof JsonResponse) {
      return $surface;
    }

    $comment = Comment::create([
      'thread' => $canvas_comment_thread->id(),
      'body' => self::getBody($data),
      'uid' => $this->currentUser->id(),
    ]);
    $response = static::createJsonResponseFromViolationSets($comment->validate());
    if ($response instanceof JsonResponse) {
      return $response;
    }
    $comment->save();

    return new JsonResponse(['thread' => $this->normalizeThread($canvas_comment_thread)], Response::HTTP_CREATED);
  }

  /**
   * Resolves or reopens an existing thread.
   */
  public function patch(Request $request, CommentThread $canvas_comment_thread): JsonResponse {
    $data = static::decode($request);
    if (!\array_key_exists('resolved', $data) || !\is_bool($data['resolved'])) {
      return self::createInputViolationResponse('resolved', 'This value is required and must be a boolean.');
    }
    $surface = $this->loadSurface($canvas_comment_thread->getSurfaceType(), $canvas_comment_thread->getSurfaceId());
    if ($surface instanceof JsonResponse) {
      return $surface;
    }

    if ($data['resolved']) {
      $canvas_comment_thread->resolve((int) $this->currentUser->id(), $this->time->getRequestTime());
    }
    else {
      $canvas_comment_thread->reopen();
    }
    $response = static::createJsonResponseFromViolationSets($canvas_comment_thread->validate());
    if ($response instanceof JsonResponse) {
      return $response;
    }
    $canvas_comment_thread->save();

    return new JsonResponse(['thread' => $this->normalizeThread($canvas_comment_thread)]);
  }

  /**
   * Lists the users the current user may mention in a comment.
   *
   * The result is deliberately narrow. Only users who could read the thread
   * are offered, so the autocomplete cannot be used to enumerate accounts that
   * the searcher would otherwise never see, and blocked users are excluded
   * because mentioning them can never reach anybody.
   */
  public function mentionableUsers(Request $request): JsonResponse {
    $query = $request->query->get('q');
    $query = \is_string($query) ? \trim($query) : '';
    $surface_type = $request->query->get('surfaceType');
    $surface_id = $request->query->get('surfaceId');
    $surface = $this->loadSurface(
      \is_string($surface_type) ? $surface_type : '',
      \is_string($surface_id) ? $surface_id : '',
    );
    if ($surface instanceof JsonResponse) {
      return $surface;
    }

    $storage = $this->entityTypeManager->getStorage('user');
    // Access checking is deliberately off: `access user profiles` is not a
    // permission ordinary editors hold, so honouring it would make the
    // autocomplete permanently empty for exactly the people meant to use it.
    // The scoping is the comment permission instead, checked per user below,
    // and the route already requires the caller to hold `create canvas
    // comments`. What is exposed is therefore the set of people who can read
    // the thread being written, which is the set worth naming in it.
    $user_query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      // The anonymous account can neither hold a permission nor be notified.
      ->condition('uid', 0, '>')
      ->sort('name')
      ->range(0, self::MENTION_RESULT_LIMIT);
    if ($query !== '') {
      $user_query->condition('name', $query, 'CONTAINS');
    }

    $users = $storage->loadMultiple($user_query->execute());
    $mentionable = [];
    foreach ($users as $user) {
      \assert($user instanceof UserInterface);
      // Both halves are needed for a mention to be meaningful: the permission
      // to read comments at all, and view access to this particular surface.
      // Without the second, an editor would be offered somebody who cannot
      // open the unpublished page the thread is on.
      if (!$user->hasPermission(CommentThread::VIEW_PERMISSION) || !$surface->access('view', $user)) {
        continue;
      }
      $mentionable[] = [
        'uid' => (int) $user->id(),
        'displayName' => (string) $user->getDisplayName(),
        'avatar' => NULL,
      ];
    }

    return new JsonResponse(['users' => $mentionable]);
  }

  /**
   * Loads the commented surface, requiring view access to it.
   *
   * @return \Drupal\Core\Entity\EntityInterface|\Symfony\Component\HttpFoundation\JsonResponse
   *   The surface entity, or a 422 response when the surface cannot be
   *   addressed at all.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the surface does not exist.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when the current user may not view the surface.
   */
  private function loadSurface(string $surface_type, string $surface_id): EntityInterface|JsonResponse {
    if (!\in_array($surface_type, self::SUPPORTED_SURFACE_TYPES, TRUE)) {
      return self::createInputViolationResponse('surfaceType', \sprintf('`%s` is not a surface that supports commenting.', $surface_type));
    }
    if ($surface_id === '') {
      return self::createInputViolationResponse('surfaceId', 'This value is required.');
    }
    $surface = $this->entityTypeManager->getStorage($surface_type)->load($surface_id);
    if (!$surface instanceof EntityInterface) {
      throw new NotFoundHttpException(\sprintf('The `%s` surface `%s` does not exist.', $surface_type, $surface_id));
    }
    // Commenting requires view access to the surface, never update access.
    if (!$surface->access('view', $this->currentUser)) {
      throw new AccessDeniedHttpException(\sprintf('You do not have access to the `%s` surface `%s`.', $surface_type, $surface_id));
    }
    return $surface;
  }

  /**
   * Builds the client-side representation of a comment thread.
   */
  private function normalizeThread(CommentThread $thread): array {
    $storage = $this->entityTypeManager->getStorage(Comment::ENTITY_TYPE_ID);
    // Access is enforced by the route permission and the surface view access
    // check; individual comments are never hidden within a visible thread.
    // @todo Load the comments of all listed threads in a single query.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('thread', $thread->id())
      ->sort('created')
      ->sort('id')
      ->execute();
    $comments = $storage->loadMultiple($ids);

    // Mentions are stored as `@[user:123]`, so the display name is resolved at
    // read time. That is what makes a mention survive a rename: the comment
    // records who was named, never what they were called at the time.
    $mentioned_uids = [];
    foreach ($comments as $comment) {
      \assert($comment instanceof Comment);
      $mentioned_uids += \array_flip(self::extractMentionedUids($comment->getBody()));
    }
    $mentioned_users = $mentioned_uids === []
      ? []
      : $this->entityTypeManager->getStorage('user')->loadMultiple(\array_keys($mentioned_uids));

    $normalized_comments = [];
    foreach ($ids as $id) {
      $comment = $comments[$id] ?? NULL;
      if ($comment instanceof Comment) {
        $normalized_comments[] = [
          'id' => (string) $comment->id(),
          'body' => $comment->getBody(),
          'created' => $comment->getCreatedTime(),
          'changed' => $comment->getChangedTime(),
          'author' => self::normalizeAuthor($comment),
          'mentions' => self::normalizeMentions($comment->getBody(), $mentioned_users),
        ];
      }
    }

    return [
      'id' => (string) $thread->id(),
      'uuid' => (string) $thread->uuid(),
      'surfaceType' => $thread->getSurfaceType(),
      'surfaceId' => $thread->getSurfaceId(),
      'componentUuid' => $thread->getComponentUuid(),
      'resolved' => $thread->isResolved(),
      'created' => $thread->getCreatedTime(),
      'changed' => $thread->getChangedTime(),
      'author' => self::normalizeAuthor($thread),
      'comments' => $normalized_comments,
    ];
  }

  /**
   * The stored form of a mention: the user's ID, never their name.
   */
  private const string MENTION_PATTERN = '/@\[user:(\d+)\]/';

  /**
   * Extracts the user IDs mentioned in a comment body.
   *
   * @return int[]
   *   The mentioned user IDs, in order of first appearance.
   */
  private static function extractMentionedUids(string $body): array {
    \preg_match_all(self::MENTION_PATTERN, $body, $matches);
    return \array_map(\intval(...), \array_unique($matches[1]));
  }

  /**
   * Resolves a body's mention tokens to the names to render them with.
   *
   * A mention of a deleted user keeps its token in the body and is reported
   * with a NULL name, so the client can render it as an unavailable user
   * rather than silently dropping the fact that somebody was named.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $mentioned_users
   *   Users already loaded for this thread, keyed by user ID.
   */
  private static function normalizeMentions(string $body, array $mentioned_users): array {
    $mentions = [];
    foreach (self::extractMentionedUids($body) as $uid) {
      $user = $mentioned_users[$uid] ?? NULL;
      $mentions[] = [
        'uid' => $uid,
        'displayName' => $user instanceof UserInterface ? (string) $user->getDisplayName() : NULL,
      ];
    }
    return $mentions;
  }

  /**
   * Builds the client-side representation of an author.
   *
   * @todo Populate `avatar` once \Drupal\canvas\Controller\ApiAutoSaveController::buildAvatarUrl() is extracted into a service that both controllers can use.
   */
  private static function normalizeAuthor(EntityOwnerInterface $entity): array {
    $owner = $entity->getOwner();
    return [
      'uid' => (int) $entity->getOwnerId(),
      'displayName' => $owner instanceof UserInterface ? (string) $owner->getDisplayName() : '',
      'avatar' => NULL,
    ];
  }

  /**
   * Extracts the comment body from decoded request data.
   *
   * Absent, non-string and whitespace-only bodies all become the empty string,
   * which the `body` field's constraints reject with a 422.
   */
  private static function getBody(array $data): string {
    $body = $data['body'] ?? NULL;
    return \is_string($body) ? \trim($body) : '';
  }

  /**
   * Builds a 422 response for input that cannot reach an entity constraint.
   */
  private static function createInputViolationResponse(string $pointer, string $message): JsonResponse {
    $response = static::createJsonResponseFromViolationSets(new ConstraintViolationList([
      new ConstraintViolation($message, NULL, [], NULL, $pointer, NULL),
    ]));
    \assert($response instanceof JsonResponse);
    return $response;
  }

  /**
   * Interprets a query parameter as a boolean.
   */
  private static function parseBoolean(mixed $value): bool {
    if (!\is_string($value)) {
      return FALSE;
    }
    return !\in_array(\strtolower($value), ['', '0', 'false'], TRUE);
  }

}
