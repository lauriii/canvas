<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\AssetRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExperienceBuilderController {

  public function __construct(
    private readonly AssetRenderer $assetRenderer,
    protected ThemeManagerInterface $themeManager,
    protected readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'plugin.manager.field.widget')]
    protected readonly WidgetPluginManager $fieldWidgetPluginManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
    private readonly RendererInterface $renderer,
  ) {}

  private const HTML = <<<HTML
<!doctype html>
<html {{ attributes }}>
<head>
  <meta charset="UTF-8">
  <meta name="viewport"
        content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
  <css-placeholder token="CSS-HERE-PLEASE">
  <js-placeholder token="JS-HERE-PLEASE">
  <title>Drupal Experience Builder</title>
  <style>
    .experience-builder-loading {
      font-family: sans-serif;
      opacity: 0.5;
      display: flex;
      justify-content: center;
      align-items: center;
      inset: 0;
      position: fixed;
      animation: pulseLoading 2s infinite;
    }

    @keyframes pulseLoading {
      0%, 100% {
          opacity: 1;
      }
      50% {
          opacity: 0.5;
      }
    }
  </style>
</head>
<body>
  <div id="experience-builder" class="experience-builder-container"><div class="experience-builder-loading">Loading Experience Builder…</div></div>
</body>
</html>
HTML;

  public function __invoke(string $entity_type, ?EntityInterface $entity) : HtmlResponse {
    assert($this->validateTransformAssetLibraries());

    // TRICKY: don't use core/modules/system/templates/html.html.twig nor that
    // of a theme, because those include the skip link, which assumes the
    // presence of #main-content, which does not exist in the XB UI.
    $html = [
      '#type' => 'inline_template',
      '#template' => self::HTML,
      '#context' => [
        'attributes' => $this->getHtmlTagAttributes(),
      ],
    ];
    $html = $this->renderer->renderInIsolation($html);
    // List of libraries to load in the preview iframe.
    $preview_libraries = [
      'system/base',
      ...$this->themeManager->getActiveTheme()->getLibraries(),
    ];

    // Assets for the preview <iframe>s. They will be rendered by
    // \Drupal\experience_builder\AssetRenderer and added to `drupalSettings` in
    // the response. They are used when rendering the preview <iframe>s.
    // @see ui/src/components/ComponentPreview.tsx
    $preview_assets = (new AttachedAssets())->setLibraries($preview_libraries);

    $demo_mode = $this->configFactory->get('experience_builder.settings')->get('demo_mode');
    $xb_module_path = $this->moduleHandler->getModule('experience_builder')->getPath();

    return (new HtmlResponse((string) $html))->setAttachments([
      'library' => [
        'experience_builder/xb-ui',
        ...$this->getTransformAssetLibraries(),
      ],
      'drupalSettings' => [
        'xb' => [
          'base' => \sprintf('xb/%s/%s', $entity_type, $entity?->id()),
          'entityType' => $entity_type,
          'entity' => $entity?->id(),
          'demo_mode' => $demo_mode,
          // Allow for perfect component previews, by letting the client side
          // know what global assets to load in component preview <iframe>s.
          // @see ui/src/components/ComponentPreview.tsx
          'global_assets' => [
            'css' => $this->assetRenderer->renderCssAssets($preview_assets),
            'js_header' => $this->assetRenderer->renderJsHeaderAssets($preview_assets),
            'js_footer' => $this->assetRenderer->renderJsFooterAssets($preview_assets),
          ],
          'xbModulePath' => $xb_module_path,
        ],
      ],
      // This *could* use the \Drupal\Core\Asset\AssetResolverInterface services
      // directly, but it's simpler to shape the attachments data in the shape
      // that all other Drupal pages are rendered. That allows reusing core
      // infrastructure.
      // @see \Drupal\Core\Render\HtmlResponseAttachmentsProcessor
      // Note: the tokens here are under our control, and this accepts no user
      // input. Hence these hardcoded tokens are fine.
      'html_response_attachment_placeholders' => [
        'styles' => '<css-placeholder token="CSS-HERE-PLEASE">',
        'scripts' => '<js-placeholder token="JS-HERE-PLEASE">',
      ],
    ]);
  }

  /**
   * Computes the attributes to set on the <html> tag.
   *
   * @return \Drupal\Core\Template\Attribute
   */
  private function getHtmlTagAttributes(): Attribute {
    // Create a temporary rendered html element so we can extract the attributes
    // and add them to this response. This ensures things like langcode and text
    // direction are added to the html tag as expected.
    // @see template_preprocess_html()
    // @see hook_preprocess_html()
    $html_stub = [
      '#theme' => 'html',
      'page' => [],
    ];
    $other_html = Html::load((string) $this->renderer->render($html_stub));

    // Get item 1 so it is the <html> rendered by Drupal, and not the one that
    // the DOMDocument returned by HTML::load() wraps everything in.
    $element = $other_html->getElementsByTagName('html')->item(1);

    $attributes = new Attribute();
    if ($element) {
      foreach (($element?->attributes ?? []) as $attribute) {
        $attributes->setAttribute($attribute->name, $attribute->value);
      }
    }
    return $attributes;
  }

  /**
   * Finds all asset libraries whose name starts with `xb.transform.`.
   *
   * @return string[]
   *   A list of asset libraries.
   *
   * @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase
   */
  private function getTransformAssetLibraries(): array {
    $libraries = [];
    foreach (\array_keys($this->moduleHandler->getModuleList()) as $module) {
      $module_transforms = \array_filter(\array_keys($this->libraryDiscovery->getLibrariesByExtension($module)), static fn (string $library_name) => \str_starts_with($library_name, 'xb.transform.'));
      $libraries = [
        ...$libraries,
        ...array_map(fn ($lib_name) => "$module/$lib_name", $module_transforms),
      ];
    }
    return $libraries;
  }

  /**
   * Ensures XB informs developers when using missing client-side transforms.
   */
  private function validateTransformAssetLibraries(): true {
    // Find all used client-side transforms.
    $transforms = [];
    foreach ($this->fieldWidgetPluginManager->getDefinitions() as $definition) {
      if (!isset($definition['xb']['transforms']) || !is_array($definition['xb']['transforms'])) {
        continue;
      }
      $transforms = [...$transforms, ...array_keys($definition['xb']['transforms'])];
    }
    $transforms = array_unique($transforms);

    // Detect used client-side transforms without a corresponding asset library.
    $encountered_transform_asset_libraries = array_map(
      fn (string $asset_library): string => substr($asset_library, strpos($asset_library, '/') + strlen('/xb.transform.')),
      $this->getTransformAssetLibraries(),
    );
    $missing = array_diff($transforms, $encountered_transform_asset_libraries);
    if (!empty($missing)) {
      throw new \LogicException(sprintf("Client-side transforms '%s' encountered without corresponding asset libraries.", implode("', '", $missing)));
    }

    return TRUE;
  }

}
