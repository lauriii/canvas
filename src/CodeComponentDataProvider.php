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
   * Builds version 0 of the xbData array.
   *
   * @return array[]
   */
  public function getDrupalSettingsV0(): array {
    return array_replace_recursive(
      $this->getXbDataBaseUrlV0(),
      $this->getXbDataBrandingV0(),
      $this->getXbDataBreadcrumbsV0(),
      $this->getXbDataPageTitleV0(),
    );
  }

  /**
   * Returns the BaseUrl for V0 of drupalSettings.xbData.
   *
   * @return array[]
   */
  public function getXbDataBaseUrlV0(): array {
    $request = $this->requestStack->getCurrentRequest();
    \assert($request instanceof Request);
    $request->getSchemeAndHttpHost();

    return [
      self::V0 => [
        'baseUrl' => $request->getSchemeAndHttpHost(),
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
      '_drupalSettings_xbData_v0_baseUrl' => 'experience_builder/xbData.v0.baseUrl',
      '_drupalSettings_xbData_v0_branding' => 'experience_builder/xbData.v0.branding',
      '_drupalSettings_xbData_v0_breadcrumbs' => 'experience_builder/xbData.v0.breadcrumbs',
      '_drupalSettings_xbData_v0_pageTitle' => 'experience_builder/xbData.v0.pageTitle',
    ];
    $libraries = [];
    foreach ($map as $var => $library) {
      if (str_contains($jsCode, $var)) {
        $libraries[] = $library;
      }
    }
    if (!empty($libraries)) {
      return $libraries;
    }

    // There is only drupalSettings.xbData?.v0 to load the whole library
    if (preg_match('/_drupalSettings_xbData(_v0)?/', $jsCode)) {
      return ['experience_builder/xbData.v0'];
    }

    return [];
  }

  /**
   * Constructs a partial xbData array based on provided settings.
   *
   * @param array $settings
   *   An associative array of settings containing xbData keys.
   *
   * @return array
   *   A partially constructed xbData array with identified settings.
   */
  public function getPartialXbDataFromSettingsV0(array $settings): array {
    $xbData = [];
    if (isset($settings[self::XB_DATA_KEY][self::V0]['branding'])) {
      $xbData = array_replace_recursive($xbData, $this->getXbDataBrandingV0());
    }
    if (isset($settings[self::XB_DATA_KEY][self::V0]['baseUrl'])) {
      $xbData = array_replace_recursive($xbData, $this->getXbDataBaseUrlV0());
    }
    if (isset($settings[self::XB_DATA_KEY][self::V0]['breadcrumbs'])) {
      $xbData = array_replace_recursive($xbData, $this->getXbDataBreadcrumbsV0());
    }
    if (isset($settings[self::XB_DATA_KEY][self::V0]['pageTitle'])) {
      $xbData = array_replace_recursive($xbData, $this->getXbDataPageTitleV0());
    }
    return $xbData;
  }

}
