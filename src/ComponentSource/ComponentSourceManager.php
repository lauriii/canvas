<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\canvas\Attribute\ComponentSource;
use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentIncompatibilityReasonRepository;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\VersionedConfigEntityBase;
use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Update\UpdateKernel;

/**
 * Defines a plugin manager for component source plugins.
 *
 * @see \Drupal\canvas\Attribute\ComponentSource
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface
 * @see \Drupal\canvas\ComponentSource\ComponentSourceBase
 *
 * @phpstan-import-type ComponentSourceSpecificId from \Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface
 */
final class ComponentSourceManager extends DefaultPluginManager {

  /**
   * TRUE if we're running in an update kernel.
   *
   * @var bool
   */
  private readonly bool $isUpdateKernel;

  /**
   * @param \Traversable<string, string> $namespaces
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   */
  /**
   * TRUE while ::generateComponents() is on the call stack.
   *
   * Component generation saves Component config entities; with a workspace
   * active those saves switch workspace context, every switch dispatches
   * WorkspaceSwitchEvent, workspace_config reacts by clearing entity field
   * definitions, layout_builder reacts to that by clearing block plugin
   * definitions, and BlockManagerDecorator::clearCachedDefinitions() would
   * re-enter this method — recursing until memory or patience runs out.
   */
  private bool $generating = FALSE;

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    private readonly ComponentIncompatibilityReasonRepository $reasonRepository,
    private readonly ClassResolverInterface $classResolver,
    private readonly ConfigInstallerInterface $configInstaller,
    private readonly TypedConfigManagerInterface $typedConfigManager,
    DrupalKernel $kernel,
  ) {
    parent::__construct(
      'Plugin/Canvas/ComponentSource',
      $namespaces,
      $module_handler,
      ComponentSourceInterface::class,
      ComponentSource::class
    );
    $this->alterInfo('canvas_component_source');
    $this->setCacheBackend($cache_backend, 'canvas_component_source');
    $this->isUpdateKernel = $kernel instanceof UpdateKernel;
  }

  /**
   * Generates Component config entities for all eligible discovered components.
   *
   * @param string|null $source_id
   *   (optional) A ComponentSource plugin ID. If omitted, will (re)generate
   *   Component config entities for all ComponentSource plugins.
   * @param list<string>|null $source_specific_ids
   *   (optional) A list of source-specific IDs in the given $source_id. If
   *   omitted, will (re)generate Component config entities for all components.
   *
   * @return $this
   */
  public function generateComponents(?string $source_id = NULL, ?array $source_specific_ids = NULL): self {
    \assert($source_specific_ids === NULL || \array_is_list($source_specific_ids));
    if ($this->isUpdateKernel) {
      return $this;
    }
    // Re-entrancy guard: generation triggers cache invalidations (workspace
    // switches during config saves, entity field definition rebuilds) whose
    // listeners can call back into this method. One generation pass is
    // already doing the work; re-entering would recurse indefinitely.
    if ($this->generating) {
      return $this;
    }

    // Do not auto-create/update Canvas configuration when syncing config o
    // deploying.
    // @todo Introduce a "Canvas development mode" similar to Twig's: https://www.drupal.org/node/3359728
    if ($this->configInstaller->isSyncing()) {
      return $this;
    }
    // @todo Fix upstream core bug in Recipes: it inconsistently claims to be
    // syncing when installing modules, but not when installing configuration.
    // Even though it is listed under `import`, and that should hence match the
    // behavior of the /admin/config/development/configuration/single/import UI.
    if (\in_array('installRecipeConfig', array_column(debug_backtrace(), 'function'), TRUE)) {
      // Assert the bug is still present. This will start failing as soon as the
      // upstream bug is fixed.
      // @phpstan-ignore-next-line function.alreadyNarrowedType
      \assert(!$this->configInstaller->isSyncing());
      return $this;
    }

    // TRICKY: since Drupal 11.4, ModuleInstaller installs modules in groups
    // that share a single container rebuild: every module in a group has its
    // services available before any module in that group is installed. While
    // Canvas is being installed, another module's hook_module_preinstall()
    // can therefore trigger component generation — e.g. Workspaces applies
    // entity definition updates, whose `entity_field_info` cache tag
    // invalidation makes Layout Builder (if present) clear the block plugin
    // definitions, which reaches this method via BlockManagerDecorator — at a
    // point where the typed config manager still holds definitions cached
    // before Canvas was added. Version hashes cannot be computed without
    // Canvas's config schema, so skip; component generation happens in
    // hook_modules_installed() at the end of every module install, after all
    // plugin caches (including config schema) have been cleared.
    // @see \Drupal\canvas\Block\BlockManagerDecorator::clearCachedDefinitions()
    // @see \Drupal\canvas\ComponentSource\ComponentSourceBase::generateVersionHash()
    // @see \Drupal\canvas\Hook\ComponentSourceHooks::modulesInstalled()
    if (!$this->typedConfigManager->hasConfigSchema('canvas.component_source_settings.*')) {
      return $this;
    }

    $this->generating = TRUE;
    try {
      // Generated Component config mirrors discovered code (block plugins,
      // SDCs), not editorial intent: it must write to Live, never stage into
      // the active workspace, or every workspace would fill up with
      // machine-generated pending config changes.
      self::executeOutsideWorkspace(function () use ($source_id, $source_specific_ids): void {
        $source_definitions = $this->getDefinitions();
        if ($source_id !== NULL) {
          // Filter the set of definitions down to just the one that was asked
          // for, if any.
          $source_definitions = array_filter($source_definitions, fn($key) => $key === $source_id, ARRAY_FILTER_USE_KEY);
        }

        $existing_components = Component::loadMultiple();
        \assert(Inspector::assertAllObjects($existing_components, Component::class));
        foreach ($source_definitions as $source_definition_id => $definition) {
          if ($definition['discovery'] === FALSE) {
            continue;
          }
          // @todo use static cache
          $discovery = $this->classResolver->getInstanceFromDefinition($definition['discovery']);
          \assert($discovery instanceof ComponentCandidatesDiscoveryInterface);
          $this->generateComponentsForSource($source_definition_id, $discovery, $existing_components, $source_specific_ids);
        }
      });
    }
    finally {
      $this->generating = FALSE;
    }
    return $this;
  }

  /**
   * Runs $callable outside any active workspace.
   */
  private static function executeOutsideWorkspace(callable $callable): mixed {
    $manager = \Drupal::hasService('workspaces.manager') ? \Drupal::service('workspaces.manager') : NULL;
    if ($manager === NULL || !$manager->hasActiveWorkspace()) {
      return $callable();
    }
    return $manager->executeOutsideWorkspace($callable);
  }

  /**
   * Generates a new Component entity or new version on it if it already exists.
   *
   * @param string $source_id
   *   A ComponentSource plugin ID.
   * @param \Drupal\canvas\ComponentSource\ComponentCandidatesDiscoveryInterface $discovery
   *   The discovery object for this component source plugin.
   * @param array<Component> $existing_components
   *   The already existing Component entities, keyed by ID, so we know when we
   *   create a new one or a new version for an existing one.
   * @param list<ComponentSourceSpecificId>|null $source_specific_ids
   *   (optional) A list of source-specific IDs in the given $source_id. If
   *   omitted, will (re)generate Component config entities for all components.
   */
  private function generateComponentsForSource(string $source_id, ComponentCandidatesDiscoveryInterface $discovery, array $existing_components, ?array $source_specific_ids = NULL): void {
    \assert($source_specific_ids === NULL || \array_is_list($source_specific_ids));
    // Discover and check requirements.
    $component_ids = \array_keys($discovery->discover());
    if ($source_specific_ids !== NULL) {
      // Filter the discovered component IDs down to just those that were asked
      // for, if any.
      $component_ids = array_intersect($component_ids, $source_specific_ids);
    }
    // Load all current reasons once before checking requirements, so we can
    // distinguish auto-disabled from manually-disabled components below.
    $all_current_reasons = $this->reasonRepository->getReasons();

    $eligible_component_ids = [];
    foreach ($component_ids as $source_specific_component_id) {
      try {
        $discovery->checkRequirements($source_specific_component_id);
        $eligible_component_ids[] = $source_specific_component_id;
        // Clear any previously stored auto-disable reasons. Leave a manual
        // disable intact: that is an explicit site-owner decision.
        $component_id = $discovery::getComponentConfigEntityId($source_specific_component_id);
        $current_reasons = $all_current_reasons[$source_id][$component_id] ?? [];
        if ($current_reasons !== [ComponentIncompatibilityReasonRepository::MANUALLY_DISABLED_REASON]) {
          $this->reasonRepository->removeReason($source_id, $component_id);
        }
      }
      catch (ComponentDoesNotMeetRequirementsException $e) {
        $this->reasonRepository->storeReasons(
          $source_id,
          $discovery::getComponentConfigEntityId($source_specific_component_id),
          $e->getMessages()
        );
      }
    }

    // Any components that does not meet requirements: check if they already
    // have a Component config entity, disable them.
    $ineligible_component_ids = array_diff($component_ids, $eligible_component_ids);
    foreach ($ineligible_component_ids as $source_specific_component_id) {
      $component_id = $discovery::getComponentConfigEntityId($source_specific_component_id);
      $component = $existing_components[$component_id] ?? NULL;
      // Existing component trees may depend on this Component config entity.
      // Avoid breaking those dependencies (which for some config entities would
      // result in their deletion), but disallow creating more instances
      // of this Component, by disabling it.
      // (Existing instances of this component may fail to render, but robust
      // error handling must graciously handle that.)
      // @see \Drupal\canvas\Element\RenderSafeComponentContainer
      if ($component) {
        $component->disable()->save();
      }
    }

    // All other components:
    // 1. create a Component config entity if it does not exist yet, or
    // 2. if the computed settings changed, create a new version on the existing
    // 3. if other metadata changed, update it (no new version!)
    foreach ($eligible_component_ids as $source_specific_component_id) {
      $component_id = $discovery::getComponentConfigEntityId($source_specific_component_id);

      // Compute the source-specific settings and the associated version hash.
      $source_specific_settings = $discovery->computeComponentSettings($source_specific_component_id);
      $source = $this->createInstance($source_id, [
        'local_source_id' => $source_specific_component_id,
        ...$source_specific_settings,
      ]);
      \assert($source instanceof ComponentSourceBase);
      $version = $source->generateVersionHash();

      // Compute more trivial Component config entity metadata that may change,
      // but typically changes rarely:
      // - label
      // - (optional) status
      $current_metadata = $discovery->computeCurrentComponentMetadata($source_specific_component_id);

      // 1. Create a Component config entity if it does not exist yet.
      if (!\array_key_exists($component_id, $existing_components)) {
        // Only the initial `status` can be specified by the source: the site
        // owner can modify the status of Component config entities, so it must
        // remain unchanged. (Except if the component stops meeting the
        // requirements, then it will be automatically disabled, see above.)
        $initial_status = $discovery->computeInitialComponentStatus($source_specific_component_id);
        // Only the initial `provider` is respected; if the provider changes,
        // that implies a backwards compatibility break that makes the provider
        // responsible for providing an update path.
        $initial_provider = $discovery->computeInitialComponentProvider($source_specific_component_id);
        $component = Component::create([
          'id' => $component_id,
          'provider' => $initial_provider,
          'source' => $source_id,
          'status' => $initial_status,
          'versioned_properties' => [VersionedConfigEntityBase::ACTIVE_VERSION => ['settings' => $source_specific_settings]],
          'active_version' => $version,
          'source_local_id' => $source_specific_component_id,
        ] + $current_metadata);
        $component->save();
        continue;
      }

      $component = $existing_components[$component_id];
      // @phpstan-ignore-next-line booleanNot.alwaysTrue, function.alreadyNarrowedType
      \assert($component instanceof ComponentInterface);
      $needs_update = FALSE;

      // 2. The computed source-specific settings need to change due to changes
      //    in the underlying component: create a new version on the existing
      //    Component config entity.
      if ($version !== $component->getActiveVersion()) {
        $component->createVersion($version)
          ->deleteVersionIfExists(ComponentInterface::FALLBACK_VERSION)
          ->setSettings($source_specific_settings);
        $needs_update = TRUE;
      }

      // 3. if other metadata changed, update it (no new version!)
      foreach ($current_metadata as $property_name => $property_value) {
        \assert(!$component->isVersionedProperty($property_name));
        if ($component->get($property_name) !== $property_value) {
          $component->set($property_name, $property_value);
          $needs_update = TRUE;
        }
      }

      if ($needs_update) {
        $component->save();
      }
    }
  }

  /**
   * Updates component instances to the active (aka latest) version if possible.
   *
   * Runs the same updater loop on every translation — default translation
   * first, then each non-default translation. For content entities, each
   * translation's ComponentTreeItemList is updated in-place. For config
   * entities, a merged (base + override) ComponentTreeItemList is built,
   * updaters run on it, and then the translatable subset is stored back into
   * the StagedLanguageConfigOverride.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $component_tree
   *   The component tree containing instances to update.
   *
   * @return bool
   *   TRUE if any component instance was updated, FALSE otherwise.
   */
  public function updateComponentInstances(ComponentTreeItemList $component_tree): bool {
    // Source updates from the default translation so every translation —
    // including the default — converges on the new version; when triggered from
    // a non-default one (e.g. previewing it), redirect to the default tree.
    // Relies on Canvas's symmetric translation (shared tree structure, incl.
    // component_version), currently enforced by
    // ComponentTreeSymmetricalTranslationConstraint.
    // @todo When asymmetric translation lands (each translation owns its tree),
    //   skip this redirect for asymmetrically translated fields. See
    //   https://git.drupalcode.org/project/canvas/-/work_items/3571130.
    $host = $component_tree->getParent() !== NULL ? $component_tree->getEntity() : NULL;
    if ($host instanceof ContentEntityInterface && !$host->isDefaultTranslation()) {
      $field_name = $component_tree->getName();
      $default = $host->getUntranslated();
      // Only redirect when getUntranslated() resolves to a distinct
      // translation that actually reports as the default. A reconstructed
      // single-translation entity (e.g. an auto-save snapshot stored with
      // setDefaultTranslationEnforced(FALSE)) returns itself and keeps
      // reporting non-default, so redirecting again would recurse forever;
      // reconcile its own tree in place instead.
      if ($default !== $host && $default->isDefaultTranslation() && \is_string($field_name) && $default->hasField($field_name)) {
        $default_tree = $default->get($field_name);
        \assert($default_tree instanceof ComponentTreeItemList);
        return $this->updateComponentInstances($default_tree);
      }
    }

    $wasModified = $this->runUpdatersOnComponentTreeItemList($component_tree);

    // Then update each non-default translation.
    if ($host instanceof ContentEntityInterface) {
      $field_name = $component_tree->getName();
      \assert(\is_string($field_name));
      foreach ($host->getTranslationLanguages(include_default: FALSE) as $language) {
        $translation = $host->getTranslation($language->getId());
        \assert($translation instanceof FieldableEntityInterface);
        if (!$translation->hasField($field_name)) {
          continue;
        }
        $translation_tree = $translation->get($field_name);
        \assert($translation_tree instanceof ComponentTreeItemList);
        if ($this->runUpdatersOnComponentTreeItemList($translation_tree)) {
          $wasModified = TRUE;
        }
      }
    }
    elseif ($host instanceof ComponentTreeConfigEntityBase) {
      foreach ($host->getTranslationLanguages(include_default: FALSE) as $langcode => $language) {
        if ($this->updateConfigEntityTranslation($host, $langcode)) {
          $wasModified = TRUE;
        }
      }
    }

    return $wasModified;
  }

  /**
   * Runs all applicable updaters on a ComponentTreeItemList in-place.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList $tree
   *   The tree to update. Modified in-place.
   *
   * @return bool
   *   TRUE if at least one item was updated.
   */
  private function runUpdatersOnComponentTreeItemList(ComponentTreeItemList $tree): bool {
    $wasModified = FALSE;
    foreach ($tree as $item) {
      \assert($item instanceof ComponentTreeItem);
      $component = $item->getComponent();
      if ($component === NULL) {
        continue;
      }
      $component_source = $component->getComponentSource();
      $updater_class = $component_source->getPluginDefinition()['updater'] ?? FALSE;
      if (!$updater_class) {
        continue;
      }
      $updater = $this->classResolver->getInstanceFromDefinition($updater_class);
      \assert($updater instanceof ComponentInstanceUpdaterInterface);
      if ($updater->isUpdateNeeded($item) && $updater->canUpdate($item)) {
        $update_result = $updater->update($item);
        \assert($update_result === ComponentInstanceUpdateAttemptResult::Latest);
        $wasModified = TRUE;
      }
    }
    return $wasModified;
  }

  /**
   * Updates a single config entity translation's sparse override.
   *
   * Rebuilds the full V1 translated tree (base config + sparse override),
   * runs the same updater on it, then re-derives the sparse override using
   * translatability classification:
   *  - orphaned props (deleted in new version) are pruned by the updater
   *  - props that flipped translatable→non-translatable are dropped
   *  - new props are never injected (absent from $prior → filtered out)
   *
   * @param \Drupal\canvas\Entity\ComponentTreeConfigEntityBase $entity
   *   The config entity whose default translation was just updated.
   * @param string $langcode
   *   The non-default language code whose override to update.
   *
   * @return bool
   *   TRUE if the staged override was changed.
   */
  private function updateConfigEntityTranslation(ComponentTreeConfigEntityBase $entity, string $langcode): bool {
    $staged = $entity->getTranslation($langcode);
    // @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::$component_tree
    $prior = $staged->getData('component_tree');
    if (!\is_array($prior) || $prior === []) {
      return FALSE;
    }

    // Rebuild the full V1 translated tree and run the updater on it.
    $full = $entity->getTranslatedComponentTree($langcode);
    if (!$this->runUpdatersOnComponentTreeItemList($full)) {
      return FALSE;
    }

    // Re-derive the sparse override: keep only keys that were translated
    // before, are still translatable in the new version, and still exist
    // post-update. array_intersect_key with three arrays: drops orphans
    // (absent from $item_inputs), now-non-translatable keys (absent from
    // $translatable), and new props (absent from $prior_inputs).
    $dirty = FALSE;
    foreach ($full as $item) {
      \assert($item instanceof ComponentTreeItem);
      $uuid = $item->getUuid();
      $prior_inputs = $prior[$uuid]['inputs'] ?? NULL;
      if (!\is_array($prior_inputs) || $prior_inputs === []) {
        continue;
      }
      $inputs_typed_data = $item->get('inputs');
      \assert($inputs_typed_data instanceof ComponentInputs);
      // @todo Translatability is version-derived: a key that becomes
      //   non-translatable in the new version must drop from the sparse
      //   override. Today that change is only reachable via a BC break (an
      //   unsafe change, which canUpdate() blocks), so it cannot be exercised
      //   here. Add the test when safe translatability changes become possible:
      //   https://git.drupalcode.org/project/canvas/-/work_items/3587711
      $translatable = \array_flip($inputs_typed_data->getTranslatableInputKeys());
      $item_inputs = $item->getInputs() ?? [];
      $new = \array_intersect_key($item_inputs, $translatable, $prior_inputs);

      if ($new === $prior_inputs) {
        continue;
      }
      if ($new === []) {
        $staged->clearData("component_tree.$uuid");
      }
      else {
        $staged->setData("component_tree.$uuid.inputs", $new);
      }
      $dirty = TRUE;
    }

    if ($dirty && empty($staged->getData('component_tree'))) {
      $staged->clearData('component_tree');
    }
    return $dirty;
  }

}
