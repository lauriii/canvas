<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Menu;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * The "Layout" local task that opens an entity in the Canvas editor.
 *
 * Placed on a content entity's canonical route, this task links to the generic
 * Canvas editor route (`canvas.boot.entity`), whose access requirements
 * (`_entity_access: entity.update` plus `_canvas_component_tree_edit_access`)
 * hide the tab unless the bundle has an enabled `full` view mode content
 * template and the user may update the entity.
 *
 * The canonical route exposes the entity under a type-specific parameter name
 * (for example `node`), while the editor route expects `entity_type` and
 * `entity`. This class maps between them by reading the entity out of the route
 * match, so the same task works for any content entity type.
 *
 * @see \Drupal\canvas\Access\ComponentTreeEditAccessCheck
 * @see \Drupal\canvas\Storage\ComponentTreeLoader::hasContentTemplate()
 * @internal
 */
final class ContentTemplateLayoutTask extends LocalTaskDefault {

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match): array {
    foreach ($route_match->getParameters() as $parameter) {
      if ($parameter instanceof FieldableEntityInterface) {
        return [
          'entity_type' => $parameter->getEntityTypeId(),
          'entity' => $parameter->id(),
        ];
      }
    }
    return parent::getRouteParameters($route_match);
  }

}
