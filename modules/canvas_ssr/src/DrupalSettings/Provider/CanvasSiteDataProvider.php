<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\DrupalSettings\Provider;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas_ssr\DrupalSettings\DataValue;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Supplies Canvas's site data, which getSiteData() and JsonApiClient read.
 *
 * Canvas's hooks read it too: useSiteContext(), useJsonApiClient() and
 * useDateFormatter().
 *
 * In the browser, Canvas sets each value in drupalSettings for a component
 * that declares its key. During SSR this builds the same values, from the
 * same CodeComponentDataProvider. None varies by route.
 *
 * @see \Drupal\canvas\Hook\ComponentSourceHooks::jsSettingsAlter()
 */
final readonly class CanvasSiteDataProvider implements SiteDataProviderInterface {

  public function __construct(
    private CodeComponentDataProvider $dataProvider,
    private ThemeManagerInterface $themeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSiteData(): array {
    // Built once for all the values that use it.
    $cacheability = NULL;
    $shared = function () use (&$cacheability): CacheableMetadata {
      return $cacheability ??= $this->cacheability();
    };
    $values = [];
    foreach ([
      'branding' => $this->dataProvider->getCanvasDataBrandingV0(...),
      'baseUrl' => $this->dataProvider->getCanvasDataBaseUrlV0(...),
      'themeAssets' => $this->dataProvider->getCanvasDataThemeAssetsV0(...),
      'jsonapiSettings' => $this->dataProvider->getCanvasDataJsonApiSettingsV0(...),
    ] as $name => $getter) {
      $values[CodeComponentDataProvider::V0 . '.' . $name] = new DataValue(
        static fn(): mixed => $getter()[CodeComponentDataProvider::V0][$name] ?? NULL,
        $shared,
      );
    }

    // Canvas after 1.12 gives the interface language. It varies by
    // language, so it has cacheability of its own.
    $langcode_getter = $this->langcodeGetter();
    if ($langcode_getter !== NULL) {
      $values[CodeComponentDataProvider::V0 . '.langcode'] = new DataValue(
        static fn(): mixed => $langcode_getter()[CodeComponentDataProvider::V0]['langcode'] ?? NULL,
        static fn(): CacheableMetadata => (new CacheableMetadata())
          ->addCacheContexts(['languages:language_interface']),
      );
    }

    return $values;
  }

  /**
   * Returns Canvas's getter of the interface language, if it has one.
   *
   * @return (\Closure(): array<string, array<string, mixed>>)|null
   *   The getter, or NULL on a Canvas that doesn't give the language.
   */
  private function langcodeGetter(): ?\Closure {
    $name = 'getCanvasDataLangcodeV0';
    if (!\in_array($name, \get_class_methods($this->dataProvider), TRUE)) {
      return NULL;
    }

    return (new \ReflectionMethod($this->dataProvider, $name))->getClosure($this->dataProvider);
  }

  /**
   * Builds the site data's cacheability.
   */
  private function cacheability(): CacheableMetadata {
    $active_theme = $this->themeManager->getActiveTheme()->getName();

    return (new CacheableMetadata())
      ->addCacheTags(['config:system.site', "config:$active_theme.settings"])
      ->addCacheContexts(['url.site', 'theme']);
  }

}
