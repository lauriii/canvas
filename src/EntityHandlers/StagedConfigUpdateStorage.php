<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\Core\Config\Action\ConfigActionManager;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class StagedConfigUpdateStorage extends ConfigEntityStorage {

  use StagedConfigEntityStorageTrait;

  private ConfigActionManager $configActionManager;

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->autoSaveManager = $container->get(AutoSaveManager::class);
    $instance->configActionManager = $container->get('plugin.manager.config_action');
    return $instance;
  }

  protected function createStub(string $id): EntityInterface {
    return StagedConfigUpdate::createFromClientSide([
      'id' => $id,
      'label' => '',
      'target' => '',
      'actions' => [],
    ]);
  }

  protected function publish(EntityInterface $entity): void {
    \assert($entity instanceof StagedConfigUpdate);
    foreach ($entity->getActions() as $action) {
      $this->configActionManager->applyAction($action['name'], $entity->getTarget(), $action['input']);
    }
  }

  protected function beforeAutoSave(EntityInterface $entity): void {
    \assert($entity instanceof StagedConfigUpdate);
    $existing = $this->autoSaveManager->getAutoSaveEntity($entity);
    if ($existing->entity instanceof StagedConfigUpdate) {
      $entity->updateFromClientSide($entity->normalizeForClientSide()->values);
    }
  }

}
