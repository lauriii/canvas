<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Builds the page and site context returned with routed Canvas content.
 *
 * The context carries the same data Drupal-rendered Code Components read
 * through `usePageContext()` and `useSiteContext()`, generated for the routed
 * request so language, access, cacheability, and draft-preview semantics are
 * Drupal's. Theme assets come from the configured default theme without request
 * theme negotiation; the frontend chooses whether to use them.
 *
 * @see \Drupal\canvas\CodeComponentDataProvider
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */
final class CanvasContentContextBuilder {

  public function __construct(
    private readonly CodeComponentDataProvider $codeComponentDataProvider,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the context for the current routed request.
   *
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   Receives the cacheability of the generated data.
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $rendered_entity
   *   The entity whose content is being returned, when there is one: in a
   *   preview this is the selected auto-saved copy in the rendered
   *   translation, so the page title and main entity describe the same
   *   entity as the content and the document head. NULL for routes without
   *   an entity, where the route's title and parameters apply.
   *
   * @return array{page: array{pageTitle: string, breadcrumbs: array, mainEntity: array|null}, site: array{branding: array, baseUrl: string, themeAssets: array}}
   *   The context, shaped like `CanvasContext` in the `drupal-canvas` package.
   */
  public function build(RefinableCacheableDependencyInterface $cacheability, ?ContentEntityInterface $rendered_entity = NULL): array {
    $provider = $this->codeComponentDataProvider;
    $v0 = CodeComponentDataProvider::V0;

    // Parity with the document head: the rendered entity's label.
    // @see \Drupal\canvas_headless\CanvasContentHeadBuilder::build()
    $page_title = $rendered_entity !== NULL
      ? (string) ($rendered_entity->label() ?? '')
      : $provider->getCanvasDataPageTitleV0()[$v0]['pageTitle'];
    $breadcrumbs = $provider->getCanvasDataBreadcrumbsV0($cacheability)[$v0]['breadcrumbs'];
    $main_entity = $provider->getCanvasDataMainEntityV0($cacheability, $rendered_entity)[$v0]['mainEntity'] ?? NULL;
    if ($rendered_entity !== NULL) {
      $cacheability->addCacheableDependency($rendered_entity);
    }
    // The translation list, when present, depends on the enabled languages
    // and the negotiated content language.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::renderComponent()
    if ($main_entity !== NULL) {
      $cacheability->addCacheTags(['config:configurable_language_list', 'config:language.negotiation']);
      $cacheability->addCacheContexts(['languages:language_content']);
    }

    $branding = $provider->getCanvasDataBrandingV0()[$v0]['branding'];
    $base_url = $provider->getCanvasDataBaseUrlV0()[$v0]['baseUrl'];
    $cacheability->addCacheableDependency($this->configFactory->get('system.site'));
    $cacheability->addCacheableDependency(
      (new CacheableMetadata())->addCacheContexts(['url.site', 'route']),
    );

    $theme_config = $this->configFactory->get('system.theme');
    $cacheability->addCacheableDependency($theme_config);
    $theme = $theme_config->get('default');
    $theme_assets = [
      'logo' => ['url' => ''],
      'favicon' => ['url' => '', 'mimeType' => ''],
    ];
    // Never pass NULL: that would select the request's active theme instead.
    if (\is_string($theme) && $theme !== '') {
      $theme_assets = $provider->getCanvasDataThemeAssetsV0($theme)[$v0]['themeAssets'];
      // Match the configuration inputs of Drupal's theme settings provider.
      // File URLs and favicon MIME settings are resolved by that provider, not
      // by loading managed file entities or inspecting their contents here.
      foreach (['core.extension', 'system.theme.global', $theme . '.settings'] as $config_name) {
        $cacheability->addCacheableDependency($this->configFactory->get($config_name));
      }
      // ThemeSettings uses this tag. System's ConfigCacheTag subscriber also
      // invalidates it on the first save of previously absent theme settings,
      // when Config::save() does not yet invalidate the config's own tag.
      $cacheability->addCacheTags(['rendered']);
    }

    return [
      'page' => [
        'pageTitle' => (string) $page_title,
        'breadcrumbs' => $breadcrumbs,
        'mainEntity' => $main_entity,
      ],
      'site' => [
        'branding' => $branding,
        'baseUrl' => $base_url,
        'themeAssets' => $theme_assets,
      ],
    ];
  }

}
