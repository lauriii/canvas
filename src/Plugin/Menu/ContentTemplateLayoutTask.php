<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Menu;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * The "Edit in Canvas" local task: opens an entity in the Canvas editor.
 *
 * The access requirements of the linked `canvas.boot.entity` route hide the tab
 * unless the entity has an editable component tree and the user may update it.
 * The canonical route names its entity parameter per entity type (for example
 * `node`), so this maps it to the editor route's `entity_type` and `entity`.
 *
 * @see \Drupal\canvas\Access\ComponentTreeEditAccessCheck
 * @see \Drupal\canvas\Storage\ComponentTreeLoader::hasContentTemplateWithExposedSlots()
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
