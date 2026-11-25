<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas_headless\FrontendManager;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Manages the list of headless frontends for the Canvas UI and CLI.
 *
 * The list lives in the canvas_headless.settings simple configuration. Its
 * order is the display order, and the first entry is the editor's default
 * preview frontend.
 *
 * The UI uses PATCH to add, remove, or reorder entries as one list. The
 * request includes a hash of the ordered URLs to reject stale saves. The
 * CLI uses POST and DELETE to change one URL without replacing the rest
 * of the list.
 */
final class FrontendsController implements ContainerInjectionInterface {

  public function __construct(
    private readonly FrontendManager $frontends,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(FrontendManager::class));
  }

  /**
   * Grants read access to users who can preview or administer frontends.
   */
  public static function accessGet(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, PreviewUrlGeneratorInterface::PREVIEW_PERMISSION)
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer canvas headless frontends'));
  }

  /**
   * Returns the ordered list of frontends and its URL-list hash.
   */
  public function get(): JsonResponse {
    return new JsonResponse($this->frontends->get());
  }

  /**
   * Replaces the list only when the client has the current URL-list hash.
   */
  public function patch(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!\is_array($payload) || !\is_array($payload['frontends'] ?? NULL) || !\array_is_list($payload['frontends']) || !\is_string($payload['frontendUrlsHash'] ?? NULL)) {
      return new JsonResponse(['error' => 'The request body must contain a "frontends" list and a "frontendUrlsHash" string.'], Response::HTTP_BAD_REQUEST);
    }
    return self::respond(fn() => $this->frontends->replace($payload['frontends'], $payload['frontendUrlsHash']));
  }

  /**
   * Registers a frontend, optionally making it the default atomically.
   */
  public function post(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!\is_array($payload) || !\is_string($payload['url'] ?? NULL) || (\array_key_exists('default', $payload) && !\is_bool($payload['default']))) {
      return new JsonResponse(['error' => 'The request body must contain a "url" string and an optional "default" boolean.'], Response::HTTP_BAD_REQUEST);
    }
    return self::respond(fn() => $this->frontends->add($payload['url'], $payload['default'] ?? FALSE));
  }

  /**
   * Removes a frontend and reports the outcome.
   */
  public function delete(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!\is_array($payload) || \count($payload) !== 1 || !\is_string($payload['url'] ?? NULL)) {
      return new JsonResponse(['error' => 'The request body must contain only a "url" string.'], Response::HTTP_BAD_REQUEST);
    }
    return self::respond(fn() => $this->frontends->remove($payload['url']));
  }

  /**
   * Converts validation, conflict, and lock failures into JSON responses.
   */
  private static function respond(callable $operation): JsonResponse {
    try {
      return new JsonResponse($operation());
    }
    catch (HttpExceptionInterface $exception) {
      return new JsonResponse(['error' => $exception->getMessage()], $exception->getStatusCode(), $exception->getHeaders());
    }
  }

}
