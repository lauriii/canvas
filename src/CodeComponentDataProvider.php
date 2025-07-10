<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Breadcrumb\ChainBreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Provides a service to expose site metadata to drupalSettings for JS components.
 *
 * This includes site branding, breadcrumbs, page title and base URL.
 * Intended for use with dynamic JavaScript components such as those in Experience Builder.
 */
readonly final class CodeComponentDataProvider {

  public const string V0 = 'v0';
  public const string XB_DATA_KEY = 'xbData';

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private RequestStack $requestStack,
    private RouteMatchInterface $routeMatch,
    private TitleResolverInterface $titleResolver,
    private ChainBreadcrumbBuilderInterface $breadcrumbManager,
  ) {}

  /**
   * Returns the BaseUrl for V0 of drupalSettings.xbData.
   *
   * @return array[]
   */
  public function getXbDataBaseUrlV0(): array {
    $request = $this->requestStack->getCurrentRequest();
    \assert($request instanceof Request);

    return [
      self::V0 => [
        // Match `drupalSettings.path.baseUrl`: a root-relative base URL with a
        // trailing slash.
        // @see \Drupal\system\Hook\SystemHooks::jsSettingsAlter()
        'baseUrl' => $request->getBaseUrl() . '/',
      ],
    ];
  }

  /**
   * Returns the Branding array for V0 of drupalSettings.xbData.
   *
   * @return array[]
   */
  public function getXbDataBrandingV0(): array {
    $site_config = $this->configFactory->get('system.site');
    return [
      self::V0 => [
        'branding' => [
          'homeUrl' => $site_config->get('page')['front'] ?? '',
          'siteName' => $site_config->get('name') ?? '',
          'siteSlogan' => $site_config->get('slogan') ?? '',
        ],
      ],
    ];
  }

  /**
   * Returns the Breadcrumbs for V0 of drupalSettings.xbData.
   *
   * @return array[]
   */
  public function getXbDataBreadcrumbsV0(): array {
    return [
      self::V0 => [
        'breadcrumbs' => array_map(static function (Link $link) {
          $url = $link->getUrl();
          return [
            'key' => $url->getRouteName() ?? '',
            'text' => $link->getText(),
            'url' => $url->toString() ?? '',
          ];
        }, $this->breadcrumbManager->build($this->routeMatch)->getLinks()),
      ],
    ];
  }

  /**
   * Returns the PageTitle for V0 of drupalSettings.xbData.
   *
   * @return array[]
   */
  public function getXbDataPageTitleV0(): array {
    $request = $this->requestStack->getCurrentRequest();
    \assert($request instanceof Request);
    $route = $this->routeMatch->getRouteObject();
    \assert($route instanceof Route);
    return [
      self::V0 => [
        // @todo improve title in https://www.drupal.org/i/3502371
        'pageTitle' => $this->titleResolver->getTitle($request, $route) ?: '',
      ],
    ];
  }

  /**
   * Parses the js code and attach the associated library.
   *
   * @param string $jsCode
   *   The JavaScript code.
   *
   * @return array|string[]
   *   The array of the drupalSettings libraries.
   */
  public static function getRequiredXbDataLibraries(string $jsCode): array {
    // @todo Improve how is this being done https://drupal.org/i/3533458
    // Using the compiled variables because drupalSettings.xbData.v0 was not
    // reliable and we will always have the compiled variable.
    $map = [
      'getSiteData' => [
        'experience_builder/xbData.v0.baseUrl',
        'experience_builder/xbData.v0.branding',
      ],
      'getPageData' => [
        'experience_builder/xbData.v0.breadcrumbs',
        'experience_builder/xbData.v0.pageTitle',
      ],
    ];
    $libraries = [];
    foreach ($map as $var => $needed_libraries) {
      if (str_contains($jsCode, $var)) {
        $libraries = \array_merge($libraries, $needed_libraries);
      }
    }
    return $libraries;
  }

}
