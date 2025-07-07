<?php

namespace Drupal\experience_builder\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * Checks access to the XB UI: requires >=1 component tree to be editable.
 *
 * Ignores per-entity field access control; relies on 'edit' access to an XB
 * field.
 *
 * @see \Drupal\experience_builder\Access\ComponentTreeEditAccessCheck
 *
 * @internal
 */
class XbUiAccessCheck implements AccessInterface {

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function access(AccountInterface $account): AccessResultInterface {
    $access = AccessResult::neutral('Requires >=1 content entity type with an XB field that can be created or edited.');

    $field_map = $this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    foreach ($field_map as $entity_type_id => $detail) {
      $access_control_handler = $this->entityTypeManager->getAccessControlHandler($entity_type_id);

      $field_names = \array_keys($detail);
      // This assumes one component tree field per bundle/entity.
      // If this assumption is willing to change, will need to be updated in
      // https://www.drupal.org/i/3526189.
      foreach ($field_names as $field_name) {
        $bundles = $detail[$field_name]['bundles'];
        foreach ($bundles as $bundle) {
          $entity_create_access = $access_control_handler->createAccess($bundle, $account, return_as_object: TRUE);

          // Create a dummy entity; needed for entity `update` access checking.
          $dummy = $this->entityTypeManager->getStorage($entity_type_id)->create([
            $this->entityTypeManager->getDefinition($entity_type_id)->getKey('bundle') => $bundle,
          ]);
          assert($dummy instanceof FieldableEntityInterface);

          $entity_update_access = $dummy->access('update', $account, TRUE);
          $xb_field = $dummy->get($field_name);
          assert($xb_field instanceof ComponentTreeItemList);
          $xb_field_edit_access = $xb_field->access('edit', $account, TRUE);

          // Grant access if the current user can:
          // 1. create such a content entity (and set the XB field)
          $access = $access->orIf($entity_create_access->andIf($xb_field_edit_access));
          // 2. edit such a content entity (and update the XB field)
          $access = $access->orIf($entity_update_access->andIf($xb_field_edit_access));
          // 3. edit code components, as there might a "component developer role"
          $access = $access->orIf(AccessResult::allowedIfHasPermission($account, JavaScriptComponent::ADMIN_PERMISSION));
          // If we have access to edit a single XB-field in a single bundle,
          // or code components, we must grant access to XB and can avoid extra
          // checks.
          if ($access->isAllowed()) {
            return $access;
          }
        }
      }
    }
    return $access;
  }

}
