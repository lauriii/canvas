<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Controller\HtmlFormController;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpFoundation\Request;

final class ExperienceBuilderController {

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

  public function content() : HtmlResponse {
    return (new HtmlResponse(self::HTML))->setAttachments([
      'library' => [
        'experience_builder/xb-ui',
      ],
      'drupalSettings' => [],
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
   * @todo Remove in https://www.drupal.org/project/experience_builder/issues/3461422
   */
  public function horribleFormHack(Request $request, RouteMatchInterface $route_match): HtmlResponse {
    // @phpstan-ignore-next-line
    $form = \Drupal::service(HtmlFormController::class)->getContentResult($request, $route_match);
    // @phpstan-ignore-next-line
    return \Drupal::service(BareHtmlPageRendererInterface::class)->renderBarePage($form, 'this is a horrible hack that helps us move forward', 'page');
  }

}
