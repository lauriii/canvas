<?php

declare(strict_types=1);

namespace Drupal\canvas\CoreBugFix;

use Drupal\Core\Config\Entity\Query\QueryFactory;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * @internal
 *
 * @todo Fix upstream in core in https://www.drupal.org/project/drupal/issues/2862699
 */
final class ConfigEntityQueryFactory extends QueryFactory {

  /**
   * {@inheritdoc}
   */
  public function get(EntityTypeInterface $entity_type, $conjunction) {
    return new ConfigEntityQuery($entity_type, $conjunction, $this->configFactory, $this->keyValueFactory, $this->namespaces);
  }

}
