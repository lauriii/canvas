<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\CanvasNotificationHandler;
use Drupal\canvas\Event\PushEvent;
use Drupal\canvas\Push\PushStatus;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API controller for CLI push lifecycle signals.
 *
 * @internal This HTTP API is intended only for the Canvas CLI.
 */
final class ApiPushController extends ApiControllerBase {

  private const string NOTIFICATION_KEY = 'cli-push';

  public function __construct(
    private readonly CanvasNotificationHandler $notificationHandler,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Signals that a CLI push has started.
   */
  public function start(): Response {
    $this->notificationHandler->create([
      'type' => 'processing',
      'key' => self::NOTIFICATION_KEY,
      'title' => 'Push in progress',
      'message' => 'Components and assets are being synced.',
    ]);

    $this->eventDispatcher->dispatch(new PushEvent(PushStatus::Started));

    return new Response('', 204);
  }

  /**
   * Signals that a CLI push completed successfully.
   */
  public function complete(): Response {
    $this->notificationHandler->create([
      'type' => 'success',
      'key' => self::NOTIFICATION_KEY,
      'title' => 'Push completed',
      'message' => 'Components and assets have been synced successfully.',
    ]);

    $this->eventDispatcher->dispatch(new PushEvent(PushStatus::Completed));

    return new Response('', 204);
  }

  /**
   * Signals that a CLI push failed.
   *
   * Accepts an optional JSON body: { "message": "..." }
   */
  public function fail(Request $request): Response {
    $errorMessage = NULL;
    $body = (string) $request->getContent();
    if (!empty($body)) {
      try {
        $data = json_decode($body, TRUE, flags: JSON_THROW_ON_ERROR);
        if (\is_array($data) && isset($data['message']) && \is_string($data['message'])) {
          $errorMessage = (string) $data['message'];
        }
      }
      catch (\JsonException) {
        // Ignore malformed body; proceed without an error message.
      }
    }

    $this->notificationHandler->create([
      'type' => 'error',
      'key' => self::NOTIFICATION_KEY,
      'title' => 'Push failed',
      'message' => $errorMessage ?? 'The CLI push did not complete successfully.',
    ]);

    $this->eventDispatcher->dispatch(new PushEvent(PushStatus::Failed));

    return new Response('', 204);
  }

}
