<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\Audit\ComponentAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\VersionedConfigEntityBase;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\ConfigImportValidateEventSubscriberBase;
use Drupal\Core\Config\ConfigManagerInterface;

/**
 * Blocks config imports that would delete in-use Canvas components.
 *
 * Core's config importer deletes configuration entities using the low-level
 * Config::delete() in \Drupal\Core\Config\ConfigImporter::importConfig(), which
 * bypasses \Drupal\Core\Config\Entity\ConfigEntityBase::preDelete() and thus
 * never invokes \Drupal\canvas\Entity\Component::onDependencyRemoval(). That
 * method is what switches a still-used Component to its `fallback` source so
 * dependent content keeps rendering. When it is skipped, deleting a code
 * component (canvas.js_component.*) and/or its tracking Component config entity
 * (canvas.component.js.*) via `drush config:import` leaves dangling references
 * in content, producing a white screen of death on every affected page.
 *
 * Until the core bug is fixed, this subscriber blocks such imports: it rejects
 * any import that would delete a Component config entity (vector A), or delete
 * configuration a retained Component depends on (vector B, e.g. a code
 * component's backing js_component), while that Component still has usages.
 *
 * @see \Drupal\canvas\Entity\Component::onDependencyRemoval()
 * @see \Drupal\Core\Config\ConfigImporter::importConfig()
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::calculateDependencies()
 * @todo Remove the block once core routes config-import deletions through onDependencyRemoval() in https://www.drupal.org/project/drupal/issues/3610722, which triggers the fallback; https://www.drupal.org/project/drupal/issues/2414951 would instead detect and reject such imports in core, replacing this block rather than removing the need for it.
 */
final class ComponentConfigImportValidator extends ConfigImportValidateEventSubscriberBase {

  public function __construct(
    private readonly ConfigManagerInterface $configManager,
    private readonly ComponentAudit $componentAudit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function onConfigImporterValidate(ConfigImporterEvent $event): void {
    $config_importer = $event->getConfigImporter();
    $storage_comparer = $config_importer->getStorageComparer();
    $delete_list = $storage_comparer->getChangelist('delete');
    if ($delete_list === []) {
      return;
    }
    // Configuration whose UUID differs between the active and the incoming
    // configuration is deleted and created again under the same name, for
    // example when an update created it separately on each environment.
    // @see \Drupal\Core\Config\StorageComparer::addChangelistUpdate()
    $recreate_list = array_intersect($delete_list, $storage_comparer->getChangelist('create'));

    // Gather the Component config entities endangered by this import, keyed by
    // config name so the same Component is never reported twice.
    $endangered = [];

    // Vector A: a Component config entity is itself being deleted.
    $non_component_deletes = [];
    foreach ($delete_list as $name) {
      if ($this->configManager->getEntityTypeIdByName($name) === Component::ENTITY_TYPE_ID) {
        $component = $this->configManager->loadConfigEntityByName($name);
        if ($component instanceof Component && !(\in_array($name, $recreate_list, TRUE) && self::isRecreatedWithAllVersions($component, $storage_comparer->getSourceStorage()->read($name)))) {
          $endangered[$name] = $component;
        }
        continue;
      }
      $non_component_deletes[] = $name;
    }

    // Vector B: configuration a retained Component depends on is being deleted
    // (e.g. a code component's canvas.js_component.* config entity). Such a
    // Component survives the import but points to now-missing configuration.
    if ($non_component_deletes !== []) {
      foreach ($this->configManager->findConfigEntityDependenciesAsEntities('config', $non_component_deletes) as $dependent) {
        if (!$dependent instanceof Component) {
          continue;
        }
        $name = $dependent->getConfigDependencyName();
        // Skip Components already covered by vector A.
        if (isset($endangered[$name]) || \in_array($name, $delete_list, TRUE)) {
          continue;
        }
        $endangered[$name] = $dependent;
      }
    }

    // Block the import for every endangered Component that still has usages.
    // This mirrors the predicate in Component::onDependencyRemoval(), which
    // would otherwise apply the fallback.
    foreach ($endangered as $component) {
      if (!$this->componentAudit->hasUsages($component, RevisionAuditEnum::All) && !$this->componentAudit->hasUsages($component, RevisionAuditEnum::AutoSave)) {
        continue;
      }
      $config_importer->logError((string) $this->t('Unable to import: the configuration change would delete the in-use Canvas component %label (%id), or configuration it depends on. Deleting it via configuration import bypasses Canvas safeguards and would break existing content. Remove the component instances first, or delete the component through the UI so its fallback can be applied.', [
        '%label' => $component->label(),
        '%id' => $component->id(),
      ]));
    }
  }

  /**
   * Checks whether a recreated Component keeps everything its usages need.
   *
   * Content references Components by ID and version, not by UUID. Recreating a
   * Component with the same source and all of its versions therefore keeps
   * every usage working.
   *
   * @param \Drupal\canvas\Entity\Component $component
   *   The active Component that is deleted and created again.
   * @param array<string, mixed>|false $incoming
   *   The incoming configuration for the same name.
   *
   * @return bool
   *   TRUE if the incoming configuration has the same source and every version
   *   of the active Component, FALSE otherwise.
   */
  private static function isRecreatedWithAllVersions(Component $component, array|false $incoming): bool {
    if ($incoming === FALSE || ($incoming['source'] ?? NULL) !== $component->get('source') || !isset($incoming['active_version'])) {
      return FALSE;
    }
    $incoming_versions = [
      (string) $incoming['active_version'],
      ...\array_map('strval', \array_diff(\array_keys($incoming['versioned_properties'] ?? []), [VersionedConfigEntityBase::ACTIVE_VERSION])),
    ];
    return \array_diff($component->getVersions(), $incoming_versions) === [];
  }

}
