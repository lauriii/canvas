<?php

declare(strict_types=1);

namespace Drupal\canvas\Block;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\Component\Plugin\FallbackPluginManagerInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;

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
final readonly class BlockManagerDecorator implements BlockManagerInterface, FallbackPluginManagerInterface, CachedDiscoveryInterface {

  /**
   * The decorated block plugin manager is responsible for caching, not this!
   *
   * @phpstan-ignore pluginManagerSetsCacheBackend.missingCacheBackend
   */
  public function __construct(
    private BlockManagerInterface&FallbackPluginManagerInterface&CachedDiscoveryInterface $decorated,
    private ComponentSourceManager $componentSourceManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    $this->decorated->clearCachedDefinitions();
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
