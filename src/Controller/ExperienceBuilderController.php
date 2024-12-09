<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\AssetRenderer;

final class ExperienceBuilderController {

  public function __construct(
    private readonly AssetRenderer $assetRenderer,
    protected ThemeManagerInterface $themeManager,
  ) {}

  private const HTML = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport"
        content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
  <css-placeholder token="CSS-HERE-PLEASE">
  <js-placeholder token="JS-HERE-PLEASE">
  <title>Drupal Experience Builder</title>
</head>
<body>
  <div id="experience-builder" class="experience-builder-container">Loading Experience Builder…</div>
</body>
</html>
HTML;

  public function __invoke(string $entity_type, ?EntityInterface $entity) : HtmlResponse {
    $libraries = [
      'system/base',
      ...$this->themeManager->getActiveTheme()->getLibraries(),
    ];
    $assets = (new AttachedAssets())->setLibraries($libraries);

    return (new HtmlResponse(self::HTML))->setAttachments([
      'library' => [
        'experience_builder/xb-ui',
      ],
      'drupalSettings' => [
        'xb' => [
          'base' => \sprintf('xb/%s/%s', $entity_type, $entity?->id()),
          'entityType' => $entity_type,
          'entity' => $entity?->id(),
          // Allow for perfect component previews, by letting the client side
          // know what global assets to load in component preview <iframe>s.
          // @see ui/src/components/ComponentPreview.tsx
          'global_assets' => [
            'css' => $this->assetRenderer->renderCssAssets($assets),
            'js_header' => $this->assetRenderer->renderJsHeaderAssets($assets),
            'js_footer' => $this->assetRenderer->renderJsFooterAssets($assets),
          ],
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

}
