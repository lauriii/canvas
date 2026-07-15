<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Session\AccountInterface;
use Drupal\editor\Plugin\EditorManager;

/**
 * HTTP API delivering text editor settings and assets for the Canvas UI.
 *
 * Serves the native (client-side rendered) formatted text widgets: returns,
 * for every text format the current user is permitted to use, the same editor
 * settings (`drupalSettings.editor.formats`) and asset libraries that the
 * editor module attaches to a server-built `text_format` element. The route
 * uses the `canvas_template` wrapper format, so the response carries resolved
 * CSS/JS asset URLs and settings in the structure that
 * `processResponseAssets` loads client-side; the client fetches this once per
 * session and skips assets that are already present.
 *
 * Formats the current user cannot use are never included, so their editor
 * configuration is not exposed. The lightweight permitted-format list itself
 * (id, label, editor plugin id) is delivered with the editor boot settings.
 *
 * @see \Drupal\canvas\Controller\CanvasController
 * @see \Drupal\canvas\Render\MainContent\CanvasTemplateRenderer
 * @see \Drupal\editor\Element::preRenderTextFormat()
 * @see ui/src/services/textEditorSettings.ts
 * @see docs/adr/0017-client-side-field-widgets.md
 *
 * @internal This HTTP API is intended only for the Canvas UI. This controller
 *   and its associated route may change at any time.
 */
final class ApiTextEditorSettingsController {

  public function __construct(
    private readonly EditorManager $editorManager,
    private readonly AccountInterface $currentUser,
  ) {}

  public function __invoke(): array {
    // The same permission-gated format list that the `text_format` element
    // offers on server-built forms. Canvas's own locked formats are included
    // through their always-allowed `use` access hook.
    // @see \Drupal\canvas\Hook\ShapeMatchingHooks::filterFormatAccess()
    $format_ids = \array_keys(\filter_formats($this->currentUser));

    // Editor settings and asset libraries exactly as the editor module
    // attaches them to a server-built `text_format` element; formats without
    // an associated editor contribute nothing here.
    return [
      '#attached' => $this->editorManager->getAttachments($format_ids),
    ];
  }

}
