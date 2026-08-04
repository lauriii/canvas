<?php

declare(strict_types=1);

namespace Drupal\canvas\Block;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Component\Plugin\FallbackPluginManagerInterface;
use Drupal\Core\Block\BlockManagerInterface;

/**
 * Decorates the block plugin manager to re-generate block Canvas components.
 *
 * When block plugin definitions are re-discovered (triggered by
 * clearCachedDefinitions()), Canvas needs to regenerate its Component config
 * entities for the "block" component source. Without this, newly added block
 * plugins (e.g. Views blocks) do not appear in Canvas until a full cache clear.
 *
 * @todo Refactor this after https://www.drupal.org/project/drupal/issues/3001284 lands.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery
 * @see \Drupal\canvas\ComponentSource\ComponentSourceManager::generateComponents()
 * @see https://www.drupal.org/project/canvas/issues/3578142
 * @internal
 */
final class BlockManagerDecorator implements BlockManagerInterface, FallbackPluginManagerInterface, CachedDiscoveryInterface {

  /**
   * Hash of the block plugin ID set the last generation pass ran against.
   *
   * Block plugin definitions are cleared far more often than they change —
   * notably on every workspace switch, where workspace_config clears entity
   * field definitions and layout_builder reacts by clearing block plugin
   * definitions. Regenerating Canvas components is only useful when the set
   * of block plugins actually changed (e.g. a new Views block), so identical
   * rediscoveries are skipped.
   */
  private ?string $lastGeneratedForPluginSet = NULL;

  /**
   * The decorated block plugin manager is responsible for caching, not this!
   *
   * @phpstan-ignore pluginManagerSetsCacheBackend.missingCacheBackend
   */
  public function __construct(
    private readonly BlockManagerInterface&FallbackPluginManagerInterface&CachedDiscoveryInterface $decorated,
    private readonly ComponentSourceManager $componentSourceManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    $this->decorated->clearCachedDefinitions();
    try {
      $ids = \array_keys($this->decorated->getDefinitions());
      \sort($ids);
      $plugin_set = \hash('xxh64', \implode("\n", $ids));
    }
    catch (\Throwable) {
      // Discovery can fail mid-install (entity types or schema not ready);
      // fall through without the memo — generateComponents() has its own
      // early-state guards, and hook_modules_installed() regenerates at the
      // end of every install.
      $plugin_set = NULL;
    }
    if ($plugin_set !== NULL && $plugin_set === $this->lastGeneratedForPluginSet) {
      return;
    }
    $this->lastGeneratedForPluginSet = $plugin_set;
    $this->componentSourceManager->generateComponents(BlockComponent::SOURCE_PLUGIN_ID);
  }

  /**
   * {@inheritdoc}
   */
  public function useCaches($use_caches = FALSE): void {
    $this->decorated->useCaches($use_caches);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE): mixed {
    return $this->decorated->getDefinition($plugin_id, $exception_on_invalid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions(): array {
    return $this->decorated->getDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id): bool {
    return $this->decorated->hasDefinition($plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []): object {
    return $this->decorated->createInstance($plugin_id, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options): object|false {
    return $this->decorated->getInstance($options);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitionsForContexts(array $contexts = []): array {
    return $this->decorated->getDefinitionsForContexts($contexts);
  }

  /**
   * {@inheritdoc}
   */
  public function getCategories(): array {
    return $this->decorated->getCategories();
  }

  /**
   * {@inheritdoc}
   */
  public function getSortedDefinitions(?array $definitions = NULL): array {
    return $this->decorated->getSortedDefinitions($definitions);
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupedDefinitions(?array $definitions = NULL): array {
    return $this->decorated->getGroupedDefinitions($definitions);
  }

  /**
   * {@inheritdoc}
   */
  public function getFilteredDefinitions($consumer, $contexts = NULL, array $extra = []): array {
    return $this->decorated->getFilteredDefinitions($consumer, $contexts, $extra);
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbackPluginId($plugin_id, array $configuration = []): string {
    return $this->decorated->getFallbackPluginId($plugin_id, $configuration);
  }

}
