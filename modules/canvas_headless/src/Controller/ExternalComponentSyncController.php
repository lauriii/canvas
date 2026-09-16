<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas\CanvasNotificationHandler;
use Drupal\canvas_headless\ExternalComponentSync;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Coordinates browser-fetched external component synchronization.
 */
final class ExternalComponentSyncController {

  private const string NOTIFICATION_KEY = 'headless-component-sync';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CanvasNotificationHandler $notificationHandler,
    private readonly PreviewUrlGeneratorInterface $previewUrlGenerator,
    private readonly ExternalComponentSync $synchronizer,
  ) {}

  /**
   * Starts synchronization and returns an assertion for the metadata request.
   */
  public function start(Request $request): JsonResponse {
    $frontend = $this->getConfiguredFrontend($request);
    $assertion = $this->previewUrlGenerator->issueForPath('/');
    if ($assertion === NULL) {
      return new JsonResponse(['error' => 'A preview assertion could not be issued.'], Response::HTTP_FORBIDDEN);
    }

    $this->notificationHandler->create([
      'type' => 'processing',
      'key' => self::NOTIFICATION_KEY,
      'title' => 'Component sync in progress',
      'message' => \sprintf('Synchronizing components from %s.', $frontend->baseUrl),
    ]);

    return new JsonResponse([
      'assertion' => $assertion,
      'metadataPath' => ExternalComponentSync::COMPONENT_METADATA_PATH,
    ]);
  }

  /**
   * Applies browser-fetched metadata and reports the result.
   */
  public function complete(Request $request): JsonResponse {
    $frontend = $this->getConfiguredFrontend($request);
    $data = $this->decodeRequest($request);
    $payload = $data['payload'] ?? NULL;
    if (!\is_array($payload)) {
      return $this->errorResponse($frontend, 'The request must contain a component metadata payload.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    try {
      $result = $this->synchronizer->synchronize($frontend, $payload);
    }
    catch (\Throwable $e) {
      return $this->errorResponse($frontend, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    $issue_count = \count($result['warnings']) + \count($result['errors']);
    $summary = \sprintf(
      'Created %d, updated %d, and left %d unchanged.',
      $result['created'],
      $result['updated'],
      $result['unchanged'],
    );
    if ($issue_count > 0) {
      $summary .= \sprintf(
        ' %d warning%s reported. Issues: %s',
        $issue_count,
        $issue_count === 1 ? ' was' : 's were',
        implode('; ', [...$result['warnings'], ...$result['errors']]),
      );
    }

    $this->notificationHandler->create([
      'type' => $issue_count === 0 ? 'success' : 'warning',
      'key' => self::NOTIFICATION_KEY,
      'title' => $issue_count === 0 ? 'Component sync completed' : 'Component sync completed with warnings',
      'message' => $summary,
    ]);

    return new JsonResponse(['result' => $result]);
  }

  /**
   * Records a metadata fetch failure reported by the browser.
   */
  public function fail(Request $request): JsonResponse {
    $frontend = $this->getConfiguredFrontend($request);
    $data = $this->decodeRequest($request);
    $message = \is_string($data['message'] ?? NULL) && $data['message'] !== ''
      ? $data['message']
      : 'The frontend component metadata could not be fetched.';

    return $this->errorResponse($frontend, $message);
  }

  /**
   * Gets and validates the frontend named by a request.
   */
  private function getConfiguredFrontend(Request $request): FrontendUrl {
    $data = $this->decodeRequest($request);
    $requested_url = $data['frontendUrl'] ?? NULL;
    if (!\is_string($requested_url)) {
      throw new BadRequestHttpException('The request must contain a frontendUrl string.');
    }
    $requested_frontend = FrontendUrl::fromConfig($requested_url);
    if ($requested_frontend === NULL) {
      throw new BadRequestHttpException('The frontend URL is invalid.');
    }

    $configured = $this->configFactory->get('canvas_headless.settings')->get('frontends');
    foreach (\is_array($configured) ? $configured : [] as $item) {
      $frontend = FrontendUrl::fromConfig((string) (\is_array($item) ? ($item['url'] ?? '') : ''));
      if ($frontend?->baseUrl === $requested_frontend->baseUrl) {
        return $frontend;
      }
    }
    throw new BadRequestHttpException('The frontend is not configured for this site.');
  }

  /**
   * Decodes a JSON request body.
   *
   * @return array<string, mixed>
   *   The decoded request data.
   */
  private static function decodeRequest(Request $request): array {
    try {
      $data = json_decode((string) $request->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new BadRequestHttpException('The request body must contain valid JSON.', $e);
    }
    if (!\is_array($data)) {
      throw new BadRequestHttpException('The request body must be a JSON object.');
    }
    return $data;
  }

  /**
   * Creates an error notification and response.
   */
  private function errorResponse(FrontendUrl $frontend, string $message, int $status = Response::HTTP_OK): JsonResponse {
    $this->notificationHandler->create([
      'type' => 'error',
      'key' => self::NOTIFICATION_KEY,
      'title' => 'Component sync failed',
      'message' => \sprintf('Could not synchronize components from %s: %s', $frontend->baseUrl, $message),
    ]);
    return new JsonResponse(['error' => $message], $status);
  }

}
