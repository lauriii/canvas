<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines storage handler for JavascriptComponents.
 */
final class JavascriptComponentStorage extends CanvasAssetStorage {

  private ComponentSourceManager $componentSourceManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    $instance = parent::createInstance($container, $entity_type);
    $instance->componentSourceManager = $container->get(ComponentSourceManager::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doPostSave(EntityInterface $entity, $update): void {
    parent::doPostSave($entity, $update);
    \assert($entity instanceof JavascriptComponent);
    $this->componentSourceManager->generateComponents(JsComponent::SOURCE_PLUGIN_ID, [$entity->id()]);
  }

}
