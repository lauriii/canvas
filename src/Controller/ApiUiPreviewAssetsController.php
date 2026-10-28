<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AssetRenderer;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Theme\ThemeManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides the global assets to load in component preview <iframe>s.
 *
 * This route is not claimed by CanvasThemeNegotiator, so the active theme is
 * the front-end theme, which allows for perfect component previews.
 *
 * @see \Drupal\canvas\Theme\CanvasThemeNegotiator
 * @see ui/src/components/ComponentPreview.tsx
 *
 * @internal This HTTP API is intended only for the Canvas UI. This controller
 *   and associated route may change at any time.
 */
final class ApiUiPreviewAssetsController extends ApiControllerBase {

  public function __construct(
    private readonly AssetRenderer $assetRenderer,
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  public function __invoke(): JsonResponse {
    $preview_assets = (new AttachedAssets())->setLibraries([
      'system/base',
      ...$this->themeManager->getActiveTheme()->getLibraries(),
    ]);
    // This response is deliberately not cacheable: the JS footer contains
    // drupalSettings, which hook_js_settings_alter() implementations fill with
    // per-request values (such as user.uid) without bubbling cacheability.
    // @see \Drupal\user\Hook\UserHooks::jsSettingsAlter()
    return new JsonResponse([
      'css' => (string) $this->assetRenderer->renderCssAssets($preview_assets),
      'jsHeader' => (string) $this->assetRenderer->renderJsHeaderAssets($preview_assets),
      'jsFooter' => (string) $this->assetRenderer->renderJsFooterAssets($preview_assets),
    ]);
  }

}
