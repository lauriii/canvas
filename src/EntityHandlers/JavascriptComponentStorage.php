<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EntityHandlers;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\experience_builder\ComponentDoesNotMeetRequirementsException;
use Drupal\experience_builder\ComponentIncompatibilityReasonRepository;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\JavaScriptComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent;
use Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\JsComponent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines storage handler for JavascriptComponents.
 */
final class JavascriptComponentStorage extends XbAssetStorage {

  private ConfigInstallerInterface $configInstaller;
  private EntityTypeManagerInterface $entityTypeManager;
  private ComponentIncompatibilityReasonRepository $componentIncompatibilityReasonRepository;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    $instance = parent::createInstance($container, $entity_type);
    $instance->configInstaller = $container->get(ConfigInstallerInterface::class);
    $instance->entityTypeManager = $container->get(EntityTypeManagerInterface::class);
    $instance->componentIncompatibilityReasonRepository = $container->get(ComponentIncompatibilityReasonRepository::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doPostSave(EntityInterface $entity, $update): void {
    parent::doPostSave($entity, $update);
    \assert($entity instanceof JavascriptComponent);
    $this->activateBlockOverride($entity);
    if ($this->configInstaller->isSyncing()) {
      return;
    }
    $this->createOrUpdateComponentEntity($entity);
  }

  /**
   * Activates the block override, if any, if enabled, and invalidates caches.
   *
   * ⚠️ This is highly experimental and *will* be refactored.
   */
  private function activateBlockOverride(JavaScriptComponent $entity): void {
    $block_override = $entity->get('block_override');

    // Nothing to do if there is no block override, or the overriding code
    // component is disabled.
    if ($block_override === NULL || $entity->status() === FALSE) {
      return;
    }

    $component_storage = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID);
    $ids = $component_storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition(
        'id',
        sprintf('%s.%s.', BlockComponent::SOURCE_PLUGIN_ID, $block_override),
        'STARTS_WITH',
      )
      ->execute();

    $overridden_block_components = $component_storage->loadMultiple($ids);

    // Ensure all existing block component instances' cached representations are
    // invalidated, to force them to be re-rendered. They will now be rendered
    // using a JavaScriptComponent instead.
    // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\BlockComponent::renderComponent()
    Cache::invalidateTags(array_reduce(
      $overridden_block_components,
      // @phpstan-ignore-next-line
      static fn (array $all_cache_tags, Component $component): array => [
        ...$all_cache_tags,
        ...$component->getCacheTagsToInvalidate(),
      ],
      []
    ));
  }

  /**
   * Gets the corresponding component entity for the given JS component.
   *
   * @param \Drupal\experience_builder\Entity\JavaScriptComponent $entity
   *   Javascript component being saved.
   */
  protected function createOrUpdateComponentEntity(JavaScriptComponent $entity): void {
    $storage = $this->entityTypeManager->getStorage(Component::ENTITY_TYPE_ID);
    $component_id = JsComponent::componentIdFromJavascriptComponentId((string) $entity->id());
    $component = $storage->load($component_id);
    if ($component instanceof Component) {
      try {
        $component = JsComponent::updateConfigEntity($entity, $component);
        $this->componentIncompatibilityReasonRepository->removeReason(JsComponent::SOURCE_PLUGIN_ID, $component_id);
      }
      catch (ComponentDoesNotMeetRequirementsException $e) {
        $this->handleComponentDoesNotMeetRequirementsException($component_id, $e);
        $component->disable();
      }
      $component->save();
      return;
    }

    // Before exposing a JavaScriptComponent as an XB Component for the first
    // time, it must be flagged as being added to XB's component library.
    if ($entity->status() === FALSE) {
      return;
    }
    try {
      $component = JsComponent::createConfigEntity($entity);
      $component->save();
    }
    catch (ComponentDoesNotMeetRequirementsException $e) {
      $this->handleComponentDoesNotMeetRequirementsException($component_id, $e);
    }
  }

  private function handleComponentDoesNotMeetRequirementsException(string $component_id, ComponentDoesNotMeetRequirementsException $e): void {
    $this->componentIncompatibilityReasonRepository->storeReason(JsComponent::SOURCE_PLUGIN_ID, $component_id, $e->getMessage());
  }

}
