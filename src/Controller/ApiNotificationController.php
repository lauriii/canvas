<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\CanvasNotificationHandler;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * REST API controller for Canvas notifications.
 *
 * @internal This HTTP API is intended only for the Canvas UI.
 */
final class ApiNotificationController extends ApiControllerBase {

  public function __construct(
    private readonly CanvasNotificationHandler $notificationHandler,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Returns recent notifications for the authenticated user.
   */
  public function list(): JsonResponse {
    $uid = (int) $this->currentUser->id();
    $notifications = $this->notificationHandler->getRecent($uid);

    return new JsonResponse([
      'data' => [
        'notifications' => $notifications,
      ],
    ]);
  }

  /**
   * Marks notifications as read for the authenticated user.
   */
  public function markRead(Request $request): Response {
    $data = static::decode($request);
    if (!isset($data['ids']) || !\is_array($data['ids'])) {
      throw new BadRequestHttpException('Missing or invalid "ids" field.');
    }
    $uid = (int) $this->currentUser->id();
    $this->notificationHandler->markRead($uid, $data['ids']);
    return new Response('', 204);
  }

}
