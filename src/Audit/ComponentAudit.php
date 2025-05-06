<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Audit;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityDependency;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * @todo Improve in https://www.drupal.org/project/experience_builder/issues/3522953.
 */
final class ComponentAudit {

  public function __construct(
    private readonly ConfigManagerInterface $configManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $fieldManager,
  ) {}

  /**
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function getContentRevisionsUsingComponent(Component $component): array {
    $dependents = [];
    $field_map = $this->fieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    foreach ($field_map as $entity_type_id => $fields) {
      foreach ($fields as $field_name => $field_info) {
        $entity_storage = $this->entityTypeManager
          ->getStorage($entity_type_id);
        // @todo Decide if having a SqlEntityStorageInterface storage should be
        //   a required XB characteristic. See https://www.drupal.org/i/3498525.
        if (!$entity_storage instanceof SqlEntityStorageInterface) {
          throw new \LogicException('@todo not yet supported!');
        }
        // Per https://www.drupal.org/i/3498525, being revisionable is one of
        // the required characteristics for enabling XB in content entity types.
        assert($entity_storage instanceof RevisionableStorageInterface);
        // @todo We need a pager for content entities listed, to be implemented
        //   in https://www.drupal.org/i/3522196.
        $entityQuery = $entity_storage->getQuery()
          ->allRevisions()
          ->condition($field_name . '.deps_config', "%{$component->getConfigDependencyName()}%", 'LIKE')
          ->accessCheck(TRUE);
        $ids = $entityQuery->execute();
        /** @var \Drupal\Core\Entity\ContentEntityInterface[] $dependents */
        $dependents = array_merge($dependents, $entity_storage->loadMultipleRevisions(array_keys($ids)));
      }
    }
    return $dependents;
  }

  /**
   * @return array<\Drupal\Core\Config\Entity\ConfigEntityInterface>
   */
  public function getConfigEntityDependenciesUsingComponent(Component $component, string $config_id): array {
    $config_entity_definition = $this->entityTypeManager->getDefinition($config_id);
    assert($config_entity_definition instanceof ConfigEntityTypeInterface);
    $config_prefix = $config_entity_definition->getConfigPrefix() . '.';
    $dependents = $this->configManager->getConfigDependencyManager()->getDependentEntities('config', $component->getConfigDependencyName());
    $dependents = array_filter($dependents, fn(ConfigEntityDependency $dependency) => str_starts_with($dependency->getConfigDependencyName(), $config_prefix));
    $dependencies = array_map(fn(ConfigEntityDependency $dependency): ?EntityInterface => $this->entityTypeManager->getStorage($config_id)->load(str_replace($config_prefix, '', $dependency->getConfigDependencyName())), $dependents);
    assert(Inspector::assertAllObjects($dependencies, ConfigEntityInterface::class));
    return $dependencies;
  }

}
