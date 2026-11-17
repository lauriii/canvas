<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\DrupalSettings\Provider;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas_ssr\DrupalSettings\DataValue;
use Drupal\Core\Breadcrumb\ChainBreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Supplies Canvas's page data, which getPageData() reads.
 *
 * In the browser, Canvas sets each value in drupalSettings for a component
 * that declares its key. During SSR this builds the same values, from the
 * same CodeComponentDataProvider.
 *
 * @see \Drupal\canvas\Hook\ComponentSourceHooks::jsSettingsAlter()
 */
final readonly class CanvasPageDataProvider implements PageDataProviderInterface {

  public function __construct(
    private CodeComponentDataProvider $dataProvider,
    private RouteMatchInterface $routeMatch,
    private ChainBreadcrumbBuilderInterface $breadcrumbManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getPageData(): array {
    // Built once for all the values that use it.
    $cacheability = NULL;
    $shared = function () use (&$cacheability): CacheableMetadata {
      return $cacheability ??= $this->cacheability();
    };
    $values = [];
    foreach ([
      'pageTitle' => $this->dataProvider->getCanvasDataPageTitleV0(...),
      'breadcrumbs' => $this->dataProvider->getCanvasDataBreadcrumbsV0(...),
    ] as $name => $getter) {
      $values[CodeComponentDataProvider::V0 . '.' . $name] = new DataValue(
        static fn(): mixed => $getter()[CodeComponentDataProvider::V0][$name] ?? NULL,
        $shared,
      );
    }
    // Only a page with a main entity has one. Built once, with what Canvas
    // bubbles for a component that declares the key: for each translation,
    // whether the current user may view it, and so the access result's
    // cacheability, and the languages and their URLs.
    $built = NULL;
    $build_main_entity = function () use (&$built, $shared): array {
      if ($built === NULL) {
        $cacheability = CacheableMetadata::createFromObject($shared())
          ->addCacheContexts(['languages:' . LanguageInterface::TYPE_CONTENT])
          ->addCacheTags(['config:configurable_language_list', 'config:language.negotiation']);
        $main_entity = $this->dataProvider->getCanvasDataMainEntityV0($cacheability)[CodeComponentDataProvider::V0]['mainEntity'] ?? NULL;
        $built = [$main_entity, $cacheability];
      }

      return $built;
    };
    $values[CodeComponentDataProvider::V0 . '.mainEntity'] = new DataValue(
      static fn(): mixed => $build_main_entity()[0],
      static fn(): CacheableMetadata => $build_main_entity()[1],
    );

    return $values;
  }

  /**
   * Builds the page data's cacheability.
   */
  private function cacheability(): CacheableMetadata {
    // A title or a breadcrumb may be of a translation, in the content
    // language.
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['route', 'languages:' . LanguageInterface::TYPE_CONTENT]);
    foreach ($this->routeMatch->getParameters()->all() as $parameter) {
      if ($parameter instanceof CacheableDependencyInterface) {
        $cacheability->addCacheableDependency($parameter);
      }
    }
    $cacheability->addCacheableDependency($this->breadcrumbManager->build($this->routeMatch));

    return $cacheability;
  }

}
