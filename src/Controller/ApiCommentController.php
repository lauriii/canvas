<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\Comment;
use Drupal\canvas\Entity\CommentThread;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Datetime\TimeInterface;
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
