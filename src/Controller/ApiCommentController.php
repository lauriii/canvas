<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\Comment;
use Drupal\canvas\Entity\CommentThread;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * HTTP API controller for Canvas comment threads.
 *
 * Every endpoint requires view access to the commented surface, never update
 * access. Invalid input is answered with a 422, never a 500.
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
  ) {}

  /**
   * Lists the comment threads anchored to one surface.
   */
  public function list(Request $request): JsonResponse {
    $surface = $this->loadSurface($request->query->get('surfaceType'), $request->query->get('surfaceId'));
    if ($surface instanceof JsonResponse) {
      return $surface;
    }

    $storage = $this->entityTypeManager->getStorage(CommentThread::ENTITY_TYPE_ID);
    // The route permission and the surface check above control access.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('surface_type', $surface->getEntityTypeId())
      ->condition('surface_id', (string) $surface->id())
      ->sort('created')
      ->sort('id')
      ->execute();
    /** @var \Drupal\canvas\Entity\CommentThread[] $threads */
    $threads = $storage->loadMultiple($ids);

    return new JsonResponse(['threads' => $this->normalizeThreads($threads)]);
  }

  /**
   * Starts a new comment thread, with its first comment.
   */
  public function post(Request $request): JsonResponse {
    $data = static::decode($request);
    $surface = $this->loadSurface($data['surfaceType'] ?? NULL, $data['surfaceId'] ?? NULL);
    if ($surface instanceof JsonResponse) {
      return $surface;
    }
    $component_uuid = $data['componentUuid'] ?? NULL;
    if ($component_uuid !== NULL && !\is_string($component_uuid)) {
      return self::createInputViolationResponse('componentUuid', 'This value must be a string or null.');
    }
    // Checked here because the OpenAPI request validator does not run in
    // production. A thread on the surface itself sends NULL, not an empty
    // string.
    if (\is_string($component_uuid) && !Uuid::isValid($component_uuid)) {
      return self::createInputViolationResponse('componentUuid', 'This value must be a UUID.');
    }

    $offset = self::readOffset($data);
    if ($offset instanceof JsonResponse) {
      return $offset;
    }
    if ($offset !== NULL && $component_uuid === NULL) {
      return self::createInputViolationResponse('offsetX', 'An offset requires a `componentUuid` to be an offset within.');
    }

    // @todo Verify that `componentUuid` matches a component instance in the surface's component tree.
    $thread = CommentThread::create([
      'surface_type' => $surface->getEntityTypeId(),
      'surface_id' => (string) $surface->id(),
      'component_uuid' => $component_uuid,
      'offset_x' => $offset['x'] ?? NULL,
      'offset_y' => $offset['y'] ?? NULL,
      'uid' => $this->currentUser->id(),
    ]);
    $comment = Comment::create([
      'body' => self::getBody($data),
      'uid' => $this->currentUser->id(),
    ]);

    // The `thread` reference is set once the thread has an ID.
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

    $canvas_comment_thread->set('resolved', $data['resolved']);
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
   * Only active users who can read comments and view the surface are offered.
   */
  public function mentionableUsers(Request $request): JsonResponse {
    $query = $request->query->get('q');
    $query = \is_string($query) ? \trim($query) : '';
    $surface = $this->loadSurface($request->query->get('surfaceType'), $request->query->get('surfaceId'));
    if ($surface instanceof JsonResponse) {
      return $surface;
    }

    $storage = $this->entityTypeManager->getStorage('user');
    // Editors rarely hold `access user profiles`, so the query is not access
    // checked. The comment permission, checked per user below, scopes it.
    $user_query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      // Skip the anonymous account.
      ->condition('uid', 0, '>')
      ->sort('name')
      ->range(0, self::MENTION_RESULT_LIMIT);
    if ($query !== '') {
      $user_query->condition('name', $query, 'CONTAINS');
    }

    // Narrow the query to roles that can read comments, so the result limit
    // is not spent on accounts the per-user checks below reject. An account
    // that holds the permission through an access policy alone is not offered.
    $reader_role_ids = [];
    foreach ($this->entityTypeManager->getStorage('user_role')->loadMultiple() as $role) {
      \assert($role instanceof RoleInterface);
      // Creating comments includes reading them.
      if ($role->hasPermission(CommentThread::VIEW_PERMISSION) || $role->hasPermission(CommentThread::CREATE_PERMISSION)) {
        $reader_role_ids[] = $role->id();
      }
    }
    // The authenticated role is not stored per user, so it cannot be queried.
    if (!\in_array(RoleInterface::AUTHENTICATED_ID, $reader_role_ids, TRUE)) {
      if ($reader_role_ids === []) {
        return new JsonResponse(['users' => []]);
      }
      $user_query->condition('roles', $reader_role_ids, 'IN');
    }

    $users = $storage->loadMultiple($user_query->execute());
    $mentionable = [];
    foreach ($users as $user) {
      \assert($user instanceof UserInterface);
      $can_read_comments = $user->hasPermission(CommentThread::VIEW_PERMISSION) || $user->hasPermission(CommentThread::CREATE_PERMISSION);
      if (!$can_read_comments || !$surface->access('view', $user)) {
        continue;
      }
      $mentionable[] = [
        'uid' => (int) $user->id(),
        'displayName' => (string) $user->getDisplayName(),
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
  private function loadSurface(mixed $surface_type, mixed $surface_id): EntityInterface|JsonResponse {
    $surface_type = \is_string($surface_type) ? $surface_type : '';
    $surface_id = \is_string($surface_id) ? $surface_id : '';
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
    if (!$surface->access('view', $this->currentUser)) {
      throw new AccessDeniedHttpException(\sprintf('You do not have access to the `%s` surface `%s`.', $surface_type, $surface_id));
    }
    return $surface;
  }

  /**
   * Builds the client-side representation of a comment thread.
   */
  private function normalizeThread(CommentThread $thread): array {
    return $this->normalizeThreads([$thread])[0];
  }

  /**
   * Builds the client-side representation of several comment threads.
   *
   * Comments, authors and mentioned users are loaded together for all threads.
   *
   * @param \Drupal\canvas\Entity\CommentThread[] $threads
   *   The threads to normalize.
   *
   * @return array[]
   *   The normalized threads, in the order they were given.
   */
  private function normalizeThreads(array $threads): array {
    if ($threads === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage(Comment::ENTITY_TYPE_ID);
    // The route permission and the surface check control access.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('thread', \array_map(static fn (CommentThread $thread): int|string|null => $thread->id(), $threads), 'IN')
      ->sort('created')
      ->sort('id')
      ->execute();

    // Mentions are stored as `@[user:123]` and resolved to a name at read
    // time. Authors are loaded in the same query as the mentioned users.
    $comments_by_thread = [];
    $uids = [];
    foreach ($storage->loadMultiple($ids) as $comment) {
      \assert($comment instanceof Comment);
      $comments_by_thread[$comment->get('thread')->target_id][] = $comment;
      $uids += \array_flip(self::extractMentionedUids($comment->getBody()));
      $uids[(int) $comment->getOwnerId()] = TRUE;
    }
    foreach ($threads as $thread) {
      $uids[(int) $thread->getOwnerId()] = TRUE;
    }
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(\array_keys($uids));

    $normalized = [];
    foreach ($threads as $thread) {
      $normalized_comments = [];
      foreach ($comments_by_thread[(string) $thread->id()] ?? [] as $comment) {
        $normalized_comments[] = [
          'id' => (string) $comment->id(),
          'body' => $comment->getBody(),
          'created' => $comment->getCreatedTime(),
          'author' => self::normalizeAuthor($comment),
          'mentions' => self::normalizeMentions($comment->getBody(), $users),
        ];
      }
      $offset = $thread->getOffset();
      $normalized[] = [
        'id' => (string) $thread->id(),
        'surfaceType' => $thread->getSurfaceType(),
        'surfaceId' => $thread->getSurfaceId(),
        'componentUuid' => $thread->getComponentUuid(),
        'offsetX' => $offset['x'] ?? NULL,
        'offsetY' => $offset['y'] ?? NULL,
        'resolved' => $thread->isResolved(),
        'created' => $thread->getCreatedTime(),
        'author' => self::normalizeAuthor($thread),
        'comments' => $normalized_comments,
      ];
    }
    return $normalized;
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
   * A mention of a deleted user is reported with a NULL name.
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
   */
  private static function normalizeAuthor(EntityOwnerInterface $entity): array {
    $owner = $entity->getOwner();
    return [
      'uid' => (int) $entity->getOwnerId(),
      'displayName' => $owner instanceof UserInterface ? (string) $owner->getDisplayName() : '',
    ];
  }

  /**
   * Reads the point within the component that a comment was left at.
   *
   * Both fractions are required together, and each must be from 0 to 1.
   *
   * @return array|null|\Symfony\Component\HttpFoundation\JsonResponse
   *   The `x` and `y` fractions, NULL when none was sent, or a 422 response.
   */
  private static function readOffset(array $data): array|NULL|JsonResponse {
    $x = $data['offsetX'] ?? NULL;
    $y = $data['offsetY'] ?? NULL;
    if ($x === NULL && $y === NULL) {
      return NULL;
    }
    foreach (['offsetX' => $x, 'offsetY' => $y] as $pointer => $value) {
      if (!\is_int($value) && !\is_float($value)) {
        return self::createInputViolationResponse($pointer, 'This value is required when the other offset is given, and must be a number.');
      }
      if ($value < 0 || $value > 1) {
        return self::createInputViolationResponse($pointer, 'This value must be between 0 and 1.');
      }
    }
    return ['x' => (float) $x, 'y' => (float) $y];
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
    return self::createJsonApiStyleErrorResponse($message, $pointer, Response::HTTP_UNPROCESSABLE_ENTITY);
  }

}
