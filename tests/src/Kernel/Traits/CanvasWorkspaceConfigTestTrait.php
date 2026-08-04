<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Traits;

use Drupal\canvas\CanvasServiceProvider;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\workspace_config\EarlyWorkspaceResolver;
use Drupal\workspace_config\KeyValue\WorkspaceConfigKeyValueFactory;
use Drupal\workspace_config\KeyValue\WorkspaceConfigKeyValueInformation;

/**
 * Reproduces the production key-value wiring of a workspace_config site.
 *
 * KernelTestBase replaces `keyvalue` with an in-memory factory and marks the
 * definition synthetic, which makes workspace_config skip its decoration: a
 * kernel test therefore does not see the per-workspace key-value overlay that
 * every real site running workspace_config gets. Re-apply the decoration, and
 * pin Canvas's staging bookkeeping to the undecorated factory exactly as
 * CanvasServiceProvider does outside tests, so tests exercise the wiring the
 * site runs.
 *
 * @see \Drupal\workspace_config\WorkspaceConfigServiceProvider::alter()
 * @see \Drupal\canvas\CanvasServiceProvider::registerWorkspaceInvariantKeyValueFactory()
 */
trait CanvasWorkspaceConfigTestTrait {

  /**
   * Pins Canvas staging bookkeeping to the pristine key-value factory.
   *
   * Call from the test's ::register(), which runs before every service
   * provider's ::alter().
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container, as passed to the test's ::register().
   */
  protected function registerCanvasStagingKeyValue(ContainerBuilder $container): void {
    // Registering the definition here stops CanvasServiceProvider from aliasing
    // the id to `keyvalue`, which ::enableWorkspaceConfigKeyValueOverlay() is
    // about to make workspace-aware.
    $container->register(CanvasServiceProvider::STAGING_KEY_VALUE_SERVICE, $this->keyValue::class)
      ->setSynthetic(TRUE);
    $container->set(CanvasServiceProvider::STAGING_KEY_VALUE_SERVICE, $this->keyValue);
  }

  /**
   * Makes `keyvalue` overlay collections per workspace, as on a real site.
   *
   * Call at the end of ::setUp(), once the container is final: the factory
   * resolves the workspace resolver only once and then caches it, so a factory
   * built before the last container rebuild would hold a resolver that never
   * receives WorkspaceSwitchEvent and would silently report Live forever.
   *
   * @see \Drupal\workspace_config\KeyValue\WorkspaceConfigKeyValueFactory::get()
   */
  protected function enableWorkspaceConfigKeyValueOverlay(): void {
    $this->container->set('keyvalue', new WorkspaceConfigKeyValueFactory(
      $this->keyValue,
      $this->container->get(EarlyWorkspaceResolver::class),
      new PhpSerialize(),
      Database::getConnection(),
      $this->container->get(WorkspaceConfigKeyValueInformation::class),
    ));
  }

}
