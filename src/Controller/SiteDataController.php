<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class SiteDataController extends ApiControllerBase {

  public function __construct(
    private readonly CodeComponentDataProvider $codeComponentDataProvider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  public function get(): CacheableJsonResponse {
    $provider = $this->codeComponentDataProvider;
    $data = NestedArray::mergeDeep(
      $provider->getCanvasDataThemeAssetsV0(),
      $provider->getCanvasDataJsonApiSettingsV0(),
      $provider->getCanvasDataBrandingV0(),
      $provider->getCanvasDataBaseUrlV0(),
    );
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['url.site']);
    $cacheability->addCacheableDependency($this->configFactory->get('system.site'));
    $cacheability->addCacheableDependency($this->configFactory->get('system.theme'));
    $cacheability->addCacheableDependency($this->configFactory->get('system.theme.global'));
    $cacheability->addCacheableDependency(
      $this->configFactory->get($this->themeManager->getActiveTheme()->getName() . '.settings')
    );
    $response = new CacheableJsonResponse($data[CodeComponentDataProvider::V0]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
