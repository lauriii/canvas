<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Drupal\canvas\ComponentIncompatibilityReasonRepository;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\VersionedConfigEntityBase;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\canvas\Attribute\ComponentSource;
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
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    private readonly ComponentIncompatibilityReasonRepository $reasonRepository,
    private readonly ClassResolverInterface $classResolver,
    private readonly ConfigInstallerInterface $configInstaller,
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

    // Do not auto-create/update Canvas configuration when syncing config o
    // deploying.
    // @todo Introduce a "Canvas development mode" similar to Twig's: https://www.drupal.org/node/3359728
    if ($this->configInstaller->isSyncing()) {
      return $this;
    }

    $source_definitions = $this->getDefinitions();
    if ($source_id !== NULL) {
      // Filter the set of definitions down to just the one that was asked for,
      // if any.
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
    return $this;
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
    $component_ids = array_keys($discovery->discover());
    if ($source_specific_ids !== NULL) {
      // Filter the discovered component IDs down to just those that were asked
      // for, if any.
      $component_ids = array_intersect($component_ids, $source_specific_ids);
    }
    $eligible_component_ids = [];
    foreach ($component_ids as $source_specific_component_id) {
      try {
        $discovery->checkRequirements($source_specific_component_id);
        $eligible_component_ids[] = $source_specific_component_id;
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
      if (!array_key_exists($component_id, $existing_components)) {
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

}
