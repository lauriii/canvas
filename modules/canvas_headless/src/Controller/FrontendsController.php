<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Controller;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
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
 * only add failure modes. Invalidated cache tags are announced to every
 * frontend of the list; GET reports whether they arrive.
 *
 * @see \Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator
 *
 * @phpstan-type Frontend array{url: string, components: list<string>, cacheInvalidationStatus: array{state: 'ok'|'failing', lastSuccess: int, lastFailure: int|null, lastError: string|null}|null}
 */
final class FrontendsController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FrontendCacheTagInvalidator $frontendCacheTagInvalidator,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ConfigFactoryInterface::class),
      $container->get(FrontendCacheTagInvalidator::class),
      $container->get(AccountProxyInterface::class),
    );
  }

  /**
   * Grants read access to users who can preview or administer frontends.
   */
  public static function accessGet(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, PreviewUrlGeneratorInterface::PREVIEW_PERMISSION)
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer canvas headless frontends'));
  }

  /**
   * Returns the ordered list of frontends.
   */
  public function get(): JsonResponse {
    // The status names errors of the site's outgoing requests: it is for
    // those who administer the list, not for everyone who previews.
    return new JsonResponse(['frontends' => $this->currentList($this->currentUser->hasPermission('administer canvas headless frontends'))]);
  }

  /**
   * Replaces the list of frontends.
   */
  public function patch(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!\is_array($payload) || !\array_key_exists('frontends', $payload) || !\is_array($payload['frontends']) || !\array_is_list($payload['frontends'])) {
      return new JsonResponse(['error' => 'The request body must contain a "frontends" list.'], Response::HTTP_BAD_REQUEST);
    }

    $existing_frontends = [];
    foreach ($this->currentList() as $frontend) {
      $configured_frontend = FrontendUrl::fromConfig($frontend['url']);
      $existing_frontends[$configured_frontend === NULL ? $frontend['url'] : $configured_frontend->baseUrl] = $frontend;
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
      $frontends[] = [
        'url' => $url,
        'components' => $this->normalizeComponentIds($existing_frontends[$frontend->baseUrl]['components'] ?? []),
      ];
    }

    $this->configFactory->getEditable('canvas_headless.settings')
      ->set('frontends', $frontends)
      ->save();

    return new JsonResponse(['frontends' => $this->currentList(TRUE)]);
  }

  /**
   * Announces to one frontend, and reports whether it read the cache tags.
   */
  public function testCacheInvalidation(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    $url = \is_array($payload) ? ($payload['url'] ?? NULL) : NULL;
    if (!\is_string($url)) {
      return new JsonResponse(['error' => 'The request body must contain a "url" string.'], Response::HTTP_BAD_REQUEST);
    }
    // Only configured frontends are contacted.
    $base_url = FrontendUrl::fromConfig($url)?->baseUrl;
    $configured = \array_map(static fn (array $frontend): ?string => FrontendUrl::fromConfig($frontend['url'])?->baseUrl, $this->currentList());
    if ($base_url === NULL || !\in_array($base_url, $configured, TRUE)) {
      return new JsonResponse(['error' => \sprintf('"%s" is not a configured frontend.', $url)], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
    return new JsonResponse($this->frontendCacheTagInvalidator->test($base_url));
  }

  /**
   * Reads the stored list, normalized for the response.
   *
   * @param bool $with_status
   *   Whether to include the cache invalidation status.
   *
   * @return array
   *   The ordered frontends. `cacheInvalidationStatus` is NULL unless it is
   *   included and the frontend ever read announced cache tags.
   *
   * @phpstan-return list<Frontend>
   */
  private function currentList(bool $with_status = FALSE): array {
    $stored = $this->configFactory->get('canvas_headless.settings')->get('frontends');
    if (!\is_array($stored)) {
      return [];
    }
    // By canonical base URL, of the connected frontends.
    $status = $with_status ? $this->frontendCacheTagInvalidator->getStatus() : [];
    $frontends = [];
    foreach ($stored as $item) {
      if (\is_array($item) && \is_string($item['url'] ?? NULL)) {
        $frontends[] = [
          'url' => $item['url'],
          'components' => $this->normalizeComponentIds($item['components'] ?? []),
          'cacheInvalidationStatus' => $status[FrontendUrl::fromConfig($item['url'])->baseUrl ?? ''] ?? NULL,
        ];
      }
    }
    return $frontends;
  }

  /**
   * Normalizes a list of Canvas component IDs.
   *
   * @param mixed $components
   *   The stored component IDs.
   *
   * @return list<string>
   *   The normalized component IDs.
   */
  private static function normalizeComponentIds(mixed $components): array {
    if (!\is_array($components)) {
      return [];
    }
    return array_values(array_filter($components, \is_string(...)));
  }

}
