<?php

declare(strict_types=1);

namespace Drupal\canvas\Service;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\Core\DrupalKernelInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;

/**
 * Decorates module_installer to regenerate Canvas components after install.
 *
 * Component regeneration used to run eagerly during the module install
 * request — from ComponentSourceHooks::modulesInstalled() and from
 * BlockManagerDecorator::clearCachedDefinitions(). The latter fires from
 * ModuleInstaller::doInstall() *before* a just-installed content-entity
 * module's schema is created, so regenerating there ran block plugin
 * discovery, whose block_content derivative deriver queries the block_content
 * entity storage against a table that did not exist yet — aborting the install
 * with core.extension already half-written and wedging the site.
 *
 * Running the regeneration here, after $this->inner->install() returns,
 * guarantees every newly-installed module's schema exists first. The same
 * module_installer service backs both `drush en` and the /admin/modules UI
 * form, so both install pathways are covered.
 *
 * Installing Canvas itself is not covered here (Canvas' services, including
 * this decorator, are not in the container yet when Canvas installs); Canvas'
 * own components come from its config/install and the next cache rebuild
 * (hook_rebuild). Config sync is skipped: the imported configuration already
 * contains the Component entities, matching the previous is_syncing early
 * return in ComponentSourceHooks::modulesInstalled().
 *
 * @see \Drupal\canvas\Hook\ComponentSourceHooks::modulesInstalled()
 * @see \Drupal\canvas\Block\BlockManagerDecorator::clearCachedDefinitions()
 * @see https://www.drupal.org/project/canvas/issues/3582851
 * @see https://git.drupalcode.org/project/canvas/-/merge_requests/860
 *   The upstream direction is to make component generation lazy
 *   (cache-miss-driven) rather than eager; once that lands, this decorator
 *   can be removed.
 *
 * @internal
 */
final class CanvasModuleInstallerDecorator implements ModuleInstallerInterface {

  public function __construct(
    private readonly ModuleInstallerInterface $inner,
    private readonly DrupalKernelInterface $kernel,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function install(array $module_list, $enable_dependencies = TRUE) {
    // Capture the config-sync state *before* delegating: install() reboots the
    // kernel and rebuilds the service container, which replaces
    // `config.installer` with a fresh instance whose isSyncing flag is FALSE.
    // So checking \Drupal::isConfigSyncing() after install() returns would
    // wrongly report "not syncing" mid-import. Core captures its own sync
    // status the same way — before doInstall() runs — and passes it to
    // hook_modules_installed.
    // @see \Drupal\Core\Extension\ModuleInstaller::install()
    $is_syncing = \Drupal::isConfigSyncing();
    $result = $this->inner->install($module_list, $enable_dependencies);
    // Regenerate now that every newly-installed schema exists. Skip during
    // config sync: the imported configuration already carries the Component
    // entities, and regenerating mid-import would cause config drift or fail on
    // not-yet-imported dependent config.
    if ($result && !$is_syncing) {
      // Resolve the manager from the *live* container. Installing a module
      // reboots the kernel and rebuilds the service container, so the instance
      // injected into this decorator — built before install() ran — is now
      // orphaned. Core itself re-fetches its own dependencies from the rebuilt
      // container for the same reason. Resolving live also ensures the
      // generated prop shapes are written to the PersistentPropShapeRepository
      // that outlives the install (the orphaned one never has its ::destruct()
      // called).
      // @see \Drupal\Core\Extension\ModuleInstaller::updateKernel()
      // @see \Drupal\canvas\PropShape\PersistentPropShapeRepository
      $component_source_manager = $this->kernel->getContainer()->get(ComponentSourceManager::class);
      \assert($component_source_manager instanceof ComponentSourceManager);
      $component_source_manager->generateComponents();
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(array $module_list, $uninstall_dependents = TRUE) {
    return $this->inner->uninstall($module_list, $uninstall_dependents);
  }

  /**
   * {@inheritdoc}
   */
  public function addUninstallValidator(ModuleUninstallValidatorInterface $uninstall_validator) {
    $this->inner->addUninstallValidator($uninstall_validator);
  }

  /**
   * {@inheritdoc}
   */
  public function validateUninstall(array $module_list) {
    return $this->inner->validateUninstall($module_list);
  }

}
