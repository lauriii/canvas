<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Audit;

use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityDependency;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\experience_builder\Entity\Component;

/**
 * @todo Improve in https://www.drupal.org/project/experience_builder/issues/3522953.
 */
final class ComponentAudit {

  public function __construct(
    private readonly ConfigManagerInterface $configManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentTreeDependencyRepository $dependencyRepository,
  ) {}

  /**
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   */
  public function getContentRevisionsUsingComponent(Component $component): array {
    return $this->dependencyRepository->getConfigurationDependents($component->getConfigDependencyName());
  }

  /**
   * @return array<\Drupal\Core\Config\Entity\ConfigEntityInterface>
   */
  public function getConfigEntityDependenciesUsingComponent(Component $component, string $config_entity_type_id): array {
    $config_entity_definition = $this->entityTypeManager->getDefinition($config_entity_type_id);
    assert($config_entity_definition instanceof ConfigEntityTypeInterface);
    $config_prefix = $config_entity_definition->getConfigPrefix() . '.';
    $dependents = $this->configManager->getConfigDependencyManager()->getDependentEntities('config', $component->getConfigDependencyName());
    $dependents = array_filter($dependents, fn(ConfigEntityDependency $dependency) => str_starts_with($dependency->getConfigDependencyName(), $config_prefix));
    $dependencies = array_map(fn(ConfigEntityDependency $dependency): ?EntityInterface => $this->entityTypeManager->getStorage($config_entity_type_id)->load(str_replace($config_prefix, '', $dependency->getConfigDependencyName())), $dependents);
    assert(Inspector::assertAllObjects($dependencies, ConfigEntityInterface::class));
    return $dependencies;
  }

  public function getConfigEntityUsageCount(Component $component): int {
    // @todo Add static caching in https://www.drupal.org/i/3522953 — config cannot change mid-request
    return count($this->configManager->getConfigDependencyManager()->getDependentEntities('config', $component->getConfigDependencyName()));
  }

}
