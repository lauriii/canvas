<?php

declare(strict_types=1);

namespace Drupal\canvas_headless_test\Hook;

use Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for canvas_headless tests.
 */
class CanvasHeadlessTestHooks {

  /**
   * Enables a second template entity type without changing Canvas's node limit.
   */
  #[Hook('config_schema_info_alter')]
  public static function configSchemaInfoAlter(array &$definitions): void {
    $definitions['canvas.content_template.*.*.*']['mapping']['content_entity_type_id']['constraints']['Choice'][] = 'taxonomy_term';
  }

  /**
   * Installs the template-aware view builder for the test's taxonomy terms.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface[] $definitions
   *   Entity type definitions.
   */
  #[Hook('entity_type_alter')]
  public static function entityTypeAlter(array &$definitions): void {
    $term_type = $definitions['taxonomy_term'] ?? NULL;
    if ($term_type instanceof EntityTypeInterface) {
      $term_type->setHandlerClass(ContentTemplateAwareViewBuilder::DECORATED_HANDLER_KEY, $term_type->getViewBuilderClass())
        ->setViewBuilderClass(ContentTemplateAwareViewBuilder::class);
    }
  }

  /**
   * Implements hook_canvas_headless_safe_permissions().
   *
   * Declares the preview permission itself preview-safe. No real module
   * should do this — it is exactly the permission whose absence on tokens
   * keeps a preview token from minting fresh assertions. Tests enable this
   * module to prove the minting routes reject bearer tokens by
   * authentication method, not merely because the ceiling withholds the
   * permission.
   * Also declares revision viewing for the taxonomy template test fixture.
   */
  #[Hook('canvas_headless_safe_permissions')]
  public static function safePermissions(): array {
    return [
      PreviewUrlGeneratorInterface::PREVIEW_PERMISSION,
      'view term revisions in topics',
    ];
  }

}
