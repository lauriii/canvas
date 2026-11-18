<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\Core\Entity\ContentEntityNullStorage;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides information about entity types eligible for Canvas templates.
 */
final readonly class ContentTemplateRepository {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns whether an entity type supports content templates.
   *
   * A content entity type is eligible if 3 conditions are met: 1) opted into
   * Field UI, 2) stored, and 3) does not have a component tree of its own.
   * `comment` is excluded. The reason for each condition, with examples, is in
   * docs/config-management.md.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type to check.
   *
   * @return bool
   *   TRUE if the entity type supports content templates.
   *
   * @see docs/config-management.md
   * @see docs/adr/0004-page-entity-type.md
   */
  public static function isSupportedEntityType(EntityTypeInterface $entity_type): bool {
    return $entity_type instanceof ContentEntityTypeInterface
      // 1. It opted into Field UI (`field_ui_base_route`).
      && (bool) $entity_type->get('field_ui_base_route')
      // 2. It is stored: its entity storage is not ContentEntityNullStorage.
      && !\is_a($entity_type->getStorageClass(), ContentEntityNullStorage::class, TRUE)
      // 3. It does not implement ComponentTreeEntityInterface.
      && !$entity_type->entityClassImplements(ComponentTreeEntityInterface::class)
      // TRICKY: `comment` is excluded by name. A comment is rendered in a
      // thread, whose threading and indentation a content template would lose.
      // @see \Drupal\comment\CommentViewBuilder::alterBuild()
      && $entity_type->id() !== 'comment';
  }

  /**
   * Returns the name of Field UI's view mode display route for an entity type.
   *
   * Canvas replaces this route's controller, and the content template
   * permission decides access to it when the template is enabled.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return string
   *   The route name.
   *
   * @see \Drupal\field_ui\Routing\RouteSubscriber::alterRoutes()
   * @see \Drupal\canvas\Routing\ViewModeRouteSubscriber
   * @see \Drupal\canvas\Access\ViewModeAccessCheck
   */
  public static function getViewModeDisplayRouteName(string $entity_type_id): string {
    return "entity.entity_view_display.$entity_type_id.view_mode";
  }

  /**
   * Returns entity type IDs that support content templates.
   *
   * @return string[]
   *   An array of entity type IDs.
   */
  public function getSupportedEntityTypeIds(): array {
    $definitions = array_filter(
      $this->entityTypeManager->getDefinitions(),
      self::isSupportedEntityType(...),
    );
    return \array_keys($definitions);
  }

  /**
   * Returns the label for a collection of bundles.
   *
   * For entity types with a bundle entity type this is that bundle entity
   * type's collection label (for example: "Content types" for `node`). For
   * entity types without one it is the entity type's own collection label (for
   * example: "Users" for `user`).
   *
   * @param string $entity_type_id
   *   The entity type ID.
   *
   * @return string
   *   The collection label.
   */
  public function getBundleCollectionLabel(string $entity_type_id): string {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    \assert($entity_type instanceof EntityTypeInterface);
    $bundle_entity_type_id = $entity_type->getBundleEntityType();
    if ($bundle_entity_type_id === NULL) {
      return (string) $entity_type->getCollectionLabel();
    }
    $bundle_entity_type = $this->entityTypeManager->getDefinition($bundle_entity_type_id);
    \assert($bundle_entity_type instanceof EntityTypeInterface);
    return (string) $bundle_entity_type->getCollectionLabel();
  }

}
