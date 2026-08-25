<?php

declare(strict_types=1);

namespace Drupal\canvas\EventSubscriber;

use Drupal\canvas\Audit\ColorAudit;
use Drupal\canvas\Audit\RevisionAuditEnum;
use Drupal\canvas\Entity\Color;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\ConfigImportValidateEventSubscriberBase;
use Drupal\Core\Config\ConfigManagerInterface;

/**
 * Blocks config imports that would delete an in-use Brand Kit color.
 *
 * Core's config importer deletes configuration entities using the low-level
 * Config::delete() in \Drupal\Core\Config\ConfigImporter::importConfig(), which
 * bypasses \Drupal\Core\Config\Entity\ConfigEntityBase::preDelete() and thus
 * neither runs the delete access check nor invokes
 * \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::onDependencyRemoval(),
 * which inlines the color's literal value so dependent trees keep rendering.
 *
 * Component trees stored in *config* are protected by core itself: they declare
 * the color as a config dependency, so \Drupal\Core\EventSubscriber\
 * ConfigImportSubscriber::validateDependencies() rejects a source tree that
 * deletes the color while a config entity still depends on it. Component trees
 * stored in *content*, and unsaved auto-saves, have no such graph. This
 * subscriber covers exactly that gap.
 *
 * A color usage in any revision counts, not just the default or latest one: a
 * dangling `canvas-color:` reference in a prior revision cannot be repaired
 * once the Color config entity is gone.
 *
 * @see \Drupal\canvas\EntityHandlers\ColorAccessControlHandler::checkAccess()
 * @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::onDependencyRemoval()
 * @see \Drupal\canvas\EventSubscriber\ComponentConfigImportValidator
 * @todo Remove the block once core routes config-import deletions through onDependencyRemoval() in https://www.drupal.org/project/drupal/issues/3610722, which triggers the inlining; https://www.drupal.org/project/drupal/issues/2414951 would instead detect and reject such imports in core, replacing this block rather than removing the need for it.
 */
final class ColorConfigImportValidator extends ConfigImportValidateEventSubscriberBase {

  public function __construct(
    private readonly ConfigManagerInterface $configManager,
    private readonly ColorAudit $colorAudit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function onConfigImporterValidate(ConfigImporterEvent $event): void {
    $config_importer = $event->getConfigImporter();
    $delete_list = $config_importer->getStorageComparer()->getChangelist('delete');
    if ($delete_list === []) {
      return;
    }

    foreach ($delete_list as $name) {
      if ($this->configManager->getEntityTypeIdByName($name) !== Color::ENTITY_TYPE_ID) {
        continue;
      }
      $color = $this->configManager->loadConfigEntityByName($name);
      if (!$color instanceof Color) {
        continue;
      }
      // TRICKY: only content and auto-save usages are inspected, never config
      // ones. ColorAudit answers config usage from the *current* dependency
      // graph, but the import may well be deleting or updating those config
      // entities in the same operation — trusting it would block legitimate
      // imports. Config usage is core's to validate.
      if (!$this->colorAudit->hasContentUsages($color, RevisionAuditEnum::All) && $this->colorAudit->getAutoSavesUsingAuditTarget($color) === []) {
        continue;
      }
      $config_importer->logError((string) $this->t('Unable to import: the configuration change would delete the in-use Brand Kit color %label (%id). Deleting it via configuration import bypasses Canvas safeguards and would leave dangling color references in existing content. Remove those usages first, or delete the color through the UI so its value can be inlined.', [
        '%label' => $color->label(),
        '%id' => $color->id(),
      ]));
    }
  }

}
