<?php

declare(strict_types=1);

namespace Drupal\canvas\Audit;

use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;

/**
 * Audits usage of Canvas Component entities in component trees.
 *
 * A component instance stores the component ID and version it uses, so the
 * entity query is exact and no confirmation step is needed.
 */
final class ComponentAudit extends ConfigAuditBase {

  /**
   * {@inheritdoc}
   */
  protected function addContentFieldConditions(QueryInterface $query, ConditionInterface $or_group, string $field_name, ConfigEntityInterface $target, array $version_ids): void {
    \assert($target instanceof ComponentInterface);
    if ($version_ids) {
      $and_group = $query->andConditionGroup();
      $and_group->condition(\sprintf('%s.component_id', $field_name), [$target->id()], 'IN');
      $and_group->condition(\sprintf('%s.component_version', $field_name), $version_ids, 'IN');
      $or_group->condition($and_group);
      return;
    }
    $or_group->condition(\sprintf('%s.component_id', $field_name), [$target->id()], 'IN');
  }

  /**
   * {@inheritdoc}
   */
  protected function componentTreeUsesAuditTarget(ComponentTreeItemList $tree, ConfigEntityInterface $target): bool {
    \assert($target instanceof ComponentInterface);
    return \in_array($target->id(), $tree->getComponentIdList(), TRUE);
  }

}
