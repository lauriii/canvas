<?php

declare(strict_types=1);

namespace Drupal\canvas\Block;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Component\Plugin\FallbackPluginManagerInterface;
use Drupal\Core\Block\BlockManagerInterface;

/**
 * Decorates the block plugin manager.
 *
 * This used to eagerly regenerate the "block" component source on every
 * `clearCachedDefinitions()`, so newly added block plugins (e.g. Views blocks)
 * surfaced in Canvas immediately. That eager call was removed: it also fired
 * mid-module-install, where regenerating ran block plugin discovery — and
 * block_content's derivative deriver queries the block_content entity storage
 * before its table exists — wedging the whole site.
 *
 * Component regeneration now happens outside the install transaction, in
 * CanvasModuleInstallerDecorator (after install) and hook_rebuild (drush cr).
 * Trade-off: block components no longer track block plugin cache clears
 * synchronously, so a newly created block plugin (e.g. a Views block) does not
 * surface, and a removed one's Component entity is not cleaned up, until the
 * next cache rebuild — matching the lazy-generation direction of MR !860. A
 * Component whose block plugin has gone away degrades to a broken component, so
 * this is not data loss.
 *
 * @todo Refactor this after https://www.drupal.org/project/drupal/issues/3001284 lands.
 *
 * @see \Drupal\canvas\Service\CanvasModuleInstallerDecorator
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponentDiscovery
 * @see \Drupal\canvas\ComponentSource\ComponentSourceManager::generateComponents()
 * @see https://www.drupal.org/project/canvas/issues/3578142
 * @see https://www.drupal.org/project/canvas/issues/3582851
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
  ) {}

  /**
   * {@inheritdoc}
   *
   * Intentionally does NOT regenerate Canvas components here. This is called
   * mid-module-install (core clears the block plugin cache before the new
   * module's entity schemas are created); regenerating then runs block plugin
   * discovery, whose block_content derivative deriver queries the block_content
   * entity storage before its table exists — aborting the install with
   * core.extension already half-written. Regeneration is deferred to
   * CanvasModuleInstallerDecorator (post-install) and hook_rebuild (drush cr).
   *
   * @see https://www.drupal.org/project/canvas/issues/3582851
   */
  public function clearCachedDefinitions(): void {
    $this->decorated->clearCachedDefinitions();
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
