<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\canvas\Entity\JavaScriptComponent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines storage handler for JavascriptComponents.
 */
final class JavascriptComponentStorage extends CanvasAssetStorage {

  private ConfigInstallerInterface $configInstaller;
  private ComponentSourceManager $componentSourceManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    $instance = parent::createInstance($container, $entity_type);
    $instance->configInstaller = $container->get(ConfigInstallerInterface::class);
    $instance->componentSourceManager = $container->get(ComponentSourceManager::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doPostSave(EntityInterface $entity, $update): void {
    parent::doPostSave($entity, $update);
    \assert($entity instanceof JavascriptComponent);
    // @todo Fix upstream core bug in Recipes: it inconsistently claims to be
    // syncing when installing modules, but not when installing configuration.
    // Even though it is listed under `import`, and that should hence match the
    // behavior of the /admin/config/development/configuration/single/import UI.
    if (in_array('installRecipeConfig', array_column(debug_backtrace(), 'function'), TRUE)) {
      // Assert the bug is still present. This will start failing as soon as the
      // upstream bug is fixed.
      \assert(!$this->configInstaller->isSyncing());
      return;
    }
    if ($this->configInstaller->isSyncing()) {
      return;
    }
    $this->componentSourceManager->generateComponents(JsComponent::SOURCE_PLUGIN_ID, [$entity->id()]);
  }

}
