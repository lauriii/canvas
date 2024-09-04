<?php

namespace Drupal\experience_builder\Render\MainContent;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Asset\AssetCollectionRendererInterface;
use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Render\MainContent\MainContentRendererInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Asset\AttachedAssets;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Main content renderer for XB endpoints that return HTML responses.
 *
 * Generates a response with:
 * - `Content-Type` response header set to `text/html`
 * - The response body containing the requested markup, without wrappers, and
 *   without a containing `<html>` or `<body>` tag
 * - The `Attach-Css` response header containing a JSON blob that is expected by
 *   the `add_css` AJAX command.
 * - The `Attach-Js` response header containing a JSON blob that is expected by
 *   the `add_js` AJAX command.
 * - The `Attach-Settings` response header containing a JSON blob that is expected by
 *   the `settings` AJAX command.
 *
 * @see \Drupal\Core\Ajax\AddCssCommand
 * @see \Drupal\Core\Ajax\AddJsCommand
 * @see \Drupal\Core\Ajax\SettingsCommand
 * @see ui/src/services/processResponseAssets.ts
 */
final class XBEndpointRenderer implements MainContentRendererInterface {

  public function __construct(
    protected ElementInfoManagerInterface $element_info_manager,
    protected RendererInterface $renderer,
    protected AssetResolverInterface $assetResolver,
    protected AssetCollectionRendererInterface $cssCollectionRenderer,
    protected AssetCollectionRendererInterface $jsCollectionRenderer,
    protected RequestStack $requestStack,
    protected ModuleHandlerInterface $moduleHandler,
    protected LanguageManagerInterface $languageManager,
  ) {

  }

  /**
   * {@inheritdoc}
   *
   * This renderer has a specific purpose: to make the assets and settings in
   * '#attached' available to requests made by the XB UI.
   *
   * This takes the MainContentRendererInterface output then and adds three
   * additional headers: Attach-Css, Attach-Js, and Attach-Settings with the
   * asset information structured in the manner expected by the CSS, JS and
   * settings methods in Drupal.AjaxCommands.
   *
   * @see ui/src/services/processResponseAssets.ts
   */
  public function renderResponse(array $main_content, Request $request, RouteMatchInterface $route_match): Response {
    $response = new Response(headers: [
      'Content-Type' => 'text/html; charset=UTF-8',
    ]);
    $html = $this->renderer->renderRoot($main_content);
    $assets = AttachedAssets::createFromRenderArray($main_content);
    $response->setContent($html);

    // Collect CSS, JS and settings, which will then be added to headers which
    // can be parsed by the client and added to the page using Drupal.Ajax()
    $get_css = $this->assetResolver->getCssAssets($assets, FALSE);
    $css_array = $this->cssCollectionRenderer->render($get_css);
    $css_for_ajax = array_map(fn($item) =>
      array_diff_key($item['#attributes'], ['rel' => 'rel']), $css_array);

    $response->headers->set('Attach-Css', Json::encode($css_for_ajax));

    [$head_assets, $foot_assets] = $this->assetResolver->getJsAssets($assets, FALSE);
    $head_array = $this->jsCollectionRenderer->render($head_assets);
    $foot_array = $this->jsCollectionRenderer->render($foot_assets);
    $js_for_ajax = array_map(
      fn($item) => array_diff_key($item['#attributes'], ['rel' => 'rel']),
      [...$head_array, ...$foot_array]
    );
    $response->headers->set('Attach-Js', Json::encode($js_for_ajax));

    $settings = $assets->getSettings();
    $response->headers->set('Attach-Settings', Json::encode($settings));

    return $response;
  }

}
