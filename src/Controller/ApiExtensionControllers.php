<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Extension\CanvasExtensionInterface;
use Drupal\canvas\Extension\CanvasExtensionPluginManager;
use Drupal\Core\Cache\CacheableJsonResponse;

/**
 * HTTP API for interacting with Canvas extensions.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiExtensionControllers {

  public function __construct(
    private readonly CanvasExtensionPluginManager $extensionPluginManager,
  ) {}

  /**
   * Returns a list of Canvas extensions with its metadata.
   */
  public function list(): CacheableJsonResponse {
    /** @var \Drupal\canvas\Extension\CanvasExtensionInterface[] $extensions */
    $extensions = $this->extensionPluginManager->getDefinitions();

    $extension_list = [];
    foreach ($extensions as $id => $extension) {
      $extension_list[$id] = $this->normalize($extension);
    }

    $json_response = new CacheableJsonResponse($extension_list);
    $json_response->addCacheableDependency($this->extensionPluginManager);

    return $json_response;
  }

  /**
   * Normalizes extension.
   *
   * @param \Drupal\canvas\Extension\CanvasExtensionInterface $extension
   *   The extension to prepare data for.
   *
   * @return array
   *   An associative array containing the normalized extension.
   */
  private function normalize(CanvasExtensionInterface $extension): array {
    return [
      'id' => $extension->id(),
      'name' => $extension->label(),
      'description' => $extension->getDescription(),
      'icon' => $extension->getIcon(),
      'url' => $extension->getUrl(),
      'type' => $extension->getType()->value,
      'api_version' => $extension->getApiVersion(),
      // NOTE: We don't expose permissions, that's a security risk.
      // @todo But we should filter the extensions the current account can access.
      //   Also applies to CanvasController.
    ];
  }

}
