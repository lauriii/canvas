<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

/**
 * A component tree config entity that renders its own editor preview.
 *
 * A content entity's tree previews as the page it belongs to, and a content
 * template previews against a chosen preview entity. Self-rendering config
 * entities are the third case: the entity itself decides what its editing
 * preview looks like, with no preview entity and no global page regions. A
 * pattern previews its tree as-is; an entity whose tree is a template for
 * query results previews it repeated once per result row.
 *
 * The editor edits the stored tree (the client's layout and model come from
 * ComponentTreeEntityInterface::getComponentTree() as usual); only the
 * preview iframe's content comes from this interface. When the preview
 * repeats the tree, exactly one repetition must render with editing
 * annotations (isPreview) so each component instance appears once to the
 * client.
 *
 * @internal
 *
 * @see \Drupal\canvas\Controller\ApiLayoutController::buildPreviewRenderable()
 */
interface PreviewRenderableInterface extends ComponentTreeEntityInterface {

  /**
   * Builds the render array for this entity's editor preview.
   *
   * @return array
   *   A renderable array in the shape
   *   ComponentTreeItemList::toRenderable() produces: keyed by the tree root
   *   UUID.
   */
  public function buildPreviewRenderable(): array;

}
