<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas_headless\FrontendUrl;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages the list of headless frontends for the Canvas UI.
 *
 * The list lives in the canvas_headless.settings simple configuration; its
 * order is the display order, and the first entry is the editor's default
 * preview frontend. PATCH replaces the whole list: the screen manages the
 * list as one unit (add, remove, reorder), so item-level operations would
 * only add failure modes.
 */
final class FrontendsController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('config.factory'));
  }

  /**
   * Returns the ordered list of frontends.
   */
  public function get(): JsonResponse {
    return new JsonResponse(['frontends' => $this->currentList()]);
  }

  /**
   * Replaces the list of frontends.
   */
  public function patch(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!\is_array($payload) || !\array_key_exists('frontends', $payload) || !\is_array($payload['frontends']) || !\array_is_list($payload['frontends'])) {
      return new JsonResponse(['error' => 'The request body must contain a "frontends" list.'], Response::HTTP_BAD_REQUEST);
    }

    $frontends = [];
    $seen_origins = [];
    foreach ($payload['frontends'] as $delta => $item) {
      $url = \is_array($item) ? ($item['url'] ?? NULL) : NULL;
      if (!\is_string($url)) {
        return new JsonResponse(['error' => \sprintf('Frontend %d must be an object with a "url" string.', $delta)], Response::HTTP_UNPROCESSABLE_ENTITY);
      }
      // FrontendUrl accepts exactly the set of URLs the config schema
      // accepts, so a list that passes here also passes validation on save.
      $frontend = FrontendUrl::fromConfig($url);
      if ($frontend === NULL) {
        return new JsonResponse(['error' => \sprintf('"%s" is not a valid frontend URL. It must be an absolute http:// or https:// URL with no credentials, query, fragment, dot path segments, or trailing slash.', $url)], Response::HTTP_UNPROCESSABLE_ENTITY);
      }
      // Two entries for the same base URL would be the same frontend listed
      // twice; the canonical form catches spellings that differ only in
      // case.
      if (isset($seen_origins[$frontend->baseUrl])) {
        return new JsonResponse(['error' => \sprintf('"%s" is already in the list.', $url)], Response::HTTP_UNPROCESSABLE_ENTITY);
      }
      $seen_origins[$frontend->baseUrl] = TRUE;
      $frontends[] = ['url' => $url];
    }

    $this->configFactory->getEditable('canvas_headless.settings')
      ->set('frontends', $frontends)
      ->save();

    return new JsonResponse(['frontends' => $this->currentList()]);
  }

  /**
   * Reads the stored list, normalized for the response.
   *
   * @return array<int, array{url: string}>
   *   The ordered frontends.
   */
  private function currentList(): array {
    $stored = $this->configFactory->get('canvas_headless.settings')->get('frontends');
    if (!\is_array($stored)) {
      return [];
    }
    $frontends = [];
    foreach ($stored as $item) {
      if (\is_array($item) && \is_string($item['url'] ?? NULL)) {
        $frontends[] = ['url' => $item['url']];
      }
    }
    return $frontends;
  }

}
