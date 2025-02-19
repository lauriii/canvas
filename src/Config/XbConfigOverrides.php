<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\JavaScriptComponent;

/**
 * Defines a config override for code components.
 */
final class XbConfigOverrides implements ConfigFactoryOverrideInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AutoSaveManager $autoSaveManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names): array {
    if (!$this->routeMatch->getRouteObject()?->getOption('_xb_use_template_draft')) {
      return [];
    }

    $loaded_code_component_config = $this->getConfigEntityIdsForConfigNames(JavaScriptComponent::class, $names);

    // We don't have an entity in scope so can't use either of
    // \Drupal\experience_builder\AutoSave\AutoSaveManager::getAutoSaveData or
    // \Drupal\experience_builder\AutoSave\AutoSaveManager::getAutoSaveKey.
    // We are currently in the process of loading the javascript component
    // entity, so even though we have the entity IDs, we can't load them, as
    // that would result in a loop.
    return $this->getOverrides(JavaScriptComponent::class, $loaded_code_component_config);
  }

  private function getConfigEntityIdsForConfigNames(string $config_entity_type_class, array $names): array {
    $code_component_type = $this->entityTypeManager->getDefinition($config_entity_type_class::ENTITY_TYPE_ID);
    \assert($code_component_type instanceof ConfigEntityTypeInterface);
    $prefix = $code_component_type->getConfigPrefix();

    $matching_names = \array_filter($names, static fn (string $name) => \str_starts_with($name, $prefix));
    if (\count($matching_names) === 0) {
      return [];
    }

    return array_combine(
      $matching_names,
      \array_map(static fn (string $name) => \mb_substr($name, mb_strlen($prefix) + 1), $matching_names),
    );
  }

  private function getOverrides(string $config_entity_type_class, array $candidates): array {
    $autosave_keys_for_config_names = \array_combine(
      \array_map(
        static fn (string $id) => \sprintf('%s:%s', $config_entity_type_class::ENTITY_TYPE_ID, $id),
        $candidates
      ),
      \array_keys($candidates),
    );

    $autoSaveData = \array_intersect_key($this->autoSaveManager->getAllAutoSaveList(), $autosave_keys_for_config_names);

    $overrides = [];
    foreach ($autoSaveData as $autosave_key => $item) {
      $config_name = $autosave_keys_for_config_names[$autosave_key];
      $overrides[$config_name] = $item['data'];
    }
    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'XbPreview';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION): ?StorableConfigBase {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    $metadata = new CacheableMetadata();
    // We can't load this from the entity-type manager because media module
    // loads config from \media_entity_type_alter and at that point the entity
    // type definitions don't exist.
    // @see \media_entity_type_alter()
    $prefix = 'experience_builder.' . JavaScriptComponent::ENTITY_TYPE_ID;
    if (!\str_starts_with($name, $prefix)) {
      return $metadata;
    }
    $metadata
      ->setCacheContexts(['route'])
      ->setCacheTags([AutoSaveManager::CACHE_TAG])
      ->setCacheMaxAge(0);
    return $metadata;
  }

}
