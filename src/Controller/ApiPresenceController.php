<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AvatarUrlBuilder;
use Drupal\canvas\Presence\PresenceManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Shows who else has an entity open in the Canvas editor.
 *
 * @see \Drupal\canvas\Presence\PresenceManager
 *
 * @internal This HTTP API is intended only for the Canvas UI.
 */
final class ApiPresenceController extends ApiControllerBase {

  /**
   * Caps the selection a session can store.
   */
  private const int MAX_SELECTION = 100;

  public function __construct(
    private readonly PresenceManager $presenceManager,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly AvatarUrlBuilder $avatarUrlBuilder,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Refreshes the current tab's session and returns everyone else's.
   */
  public function heartbeat(Request $request, EntityInterface $entity): JsonResponse {
    $body = static::decode($request);
    $client_instance_id = self::validateClientInstanceId($body['clientInstanceId'] ?? NULL);
    $selection = $body['selection'] ?? [];
    if (!\is_array($selection) || !\array_is_list($selection) || \count($selection) > self::MAX_SELECTION || \array_filter($selection, static fn ($uuid) => !\is_string($uuid))) {
      throw new BadRequestHttpException('Invalid selection.');
    }
    $active = $body['active'] ?? NULL;
    if (!\is_bool($active)) {
      throw new BadRequestHttpException('Invalid active.');
    }

    $uid = (int) $this->currentUser->id();
    $this->presenceManager->join($entity, $client_instance_id, $uid, $selection, $active);

    $sessions = $this->presenceManager->getSessions($entity);
    unset($sessions[$client_instance_id]);
    $author = $this->autoSaveManager->getAutoSaveAuthor($entity);

    $uids = \array_column($sessions, 'uid');
    if ($author !== NULL) {
      $uids[] = $author['owner'];
    }
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(\array_unique($uids));

    $data = [];
    foreach ($sessions as $id => $session) {
      $user = $users[$session['uid']] ?? NULL;
      // A session of a since deleted user.
      if (!$user instanceof UserInterface) {
        continue;
      }
      $data[] = [
        'clientInstanceId' => (string) $id,
        'self' => $session['uid'] === $uid,
        'selection' => $session['selection'],
        'active' => $session['active'],
      ] + $this->describeUser($user);
    }

    $pending_changes_by = NULL;
    $author_user = $author !== NULL ? ($users[$author['owner']] ?? NULL) : NULL;
    if ($author !== NULL && $author_user instanceof UserInterface) {
      $pending_changes_by = [
        'clientInstanceId' => $author['client_id'],
        'updated' => $author['updated'],
      ] + $this->describeUser($author_user);
    }

    return new JsonResponse([
      'sessions' => $data,
      'pendingChangesBy' => $pending_changes_by,
    ]);
  }

  /**
   * Ends one of the current user's sessions.
   */
  public function leave(Request $request, EntityInterface $entity): Response {
    $client_instance_id = self::validateClientInstanceId($request->query->get('clientInstanceId'));
    $this->presenceManager->leave($entity, $client_instance_id, (int) $this->currentUser->id());
    return new Response(status: Response::HTTP_NO_CONTENT);
  }

  /**
   * @return array{uid: int, displayName: string, avatar: ?string}
   */
  private function describeUser(UserInterface $user): array {
    $uid = (int) $user->id();
    return [
      'uid' => $uid,
      'displayName' => (string) $user->getDisplayName(),
      'avatar' => $this->avatarUrlBuilder->build($user),
    ];
  }

  private static function validateClientInstanceId(mixed $value): string {
    if (!\is_string($value) || \preg_match('/^[a-zA-Z0-9-]{1,64}$/', $value) !== 1) {
      throw new BadRequestHttpException('Invalid clientInstanceId.');
    }
    return $value;
  }

}
