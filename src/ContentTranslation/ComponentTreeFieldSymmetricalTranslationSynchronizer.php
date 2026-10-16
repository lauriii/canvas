<?php

declare(strict_types=1);

namespace Drupal\canvas\ContentTranslation;

use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\content_translation\FieldTranslationSynchronizerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Decorates field translation synchronizer to sync component instance inputs.
 *
 * After core's synchronizer handles tree-structure synchronization (the 'tree'
 * column group: uuid, parent_uuid, slot, component_id, component_version),
 * this decorator additionally synchronizes non-translatable input keys within
 * the 'inputs' JSON property across all translations.
 *
 * @see \Drupal\content_translation\FieldTranslationSynchronizer
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem
 */
final class ComponentTreeFieldSymmetricalTranslationSynchronizer implements FieldTranslationSynchronizerInterface {

  public function __construct(
    private readonly FieldTranslationSynchronizerInterface $decorated,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function synchronizeFields(ContentEntityInterface $entity, $sync_langcode, $original_langcode = NULL): void {
    \assert(\is_string($sync_langcode));
    // Forked translations are excluded from synchronization in both
    // directions: snapshot their component tree values before core's
    // synchronizer runs and restore them afterwards, rather than
    // re-implementing core's merge semantics. When the translation being
    // saved is itself forked, protect every translation: nothing the fork
    // changed may propagate outward.
    // @see https://git.drupalcode.org/project/canvas/-/work_items/3571130
    $saved_translation_is_forked = $entity->hasTranslation($sync_langcode)
      && ComponentTreeTranslationFork::isForkedTranslation($entity->getTranslation($sync_langcode));
    // Core re-sources the synchronization from an arbitrary translation when
    // a new default revision is saved from a stale loaded revision while
    // untranslatable-field changes affect all revisions — and that arbitrary
    // translation can be a forked one, which would copy the fork's tree into
    // every other translation. Protect all translations whenever that branch
    // is reachable and a fork exists; symmetric siblings then miss at most
    // one structural merge (repaired on the next regular save), which beats
    // silently absorbing a forked tree.
    // @see \Drupal\content_translation\FieldTranslationSynchronizer::synchronizeFields()
    $core_may_resync_from_arbitrary_translation = $entity->isNewRevision()
      && $entity->isDefaultRevision()
      && !$entity->isDefaultTranslationAffectedOnly()
      && self::hasForkedTranslation($entity);
    $protect_all = $saved_translation_is_forked || $core_may_resync_from_arbitrary_translation;
    $snapshot = self::snapshotProtectedComponentTreeValues($entity, $protect_all);
    $net_new = $protect_all ? [] : $this->getDefaultTranslationNewComponentInstanceUuids($entity);
    try {
      $this->decorated->synchronizeFields($entity, $sync_langcode, $original_langcode);
    }
    finally {
      self::restoreComponentTreeValues($entity, $snapshot);
    }
    if (!$protect_all) {
      $this->synchronizeComponentInstanceInputs($entity, $net_new);
    }
  }

  /**
   * Returns TRUE if any of the entity's translations is forked.
   */
  private static function hasForkedTranslation(ContentEntityInterface $entity): bool {
    foreach ($entity->getTranslationLanguages(include_default: FALSE) as $langcode => $language) {
      if (ComponentTreeTranslationFork::isForkedTranslation($entity->getTranslation($langcode))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Snapshots component tree values that synchronization must not modify.
   *
   * @param bool $protect_all
   *   TRUE to snapshot every translation's component tree values, FALSE to
   *   snapshot only forked translations'.
   *
   * @return array<string, array<string, array<int, array<string, mixed>>>>
   *   Raw field values keyed by langcode, then field name.
   */
  private static function snapshotProtectedComponentTreeValues(ContentEntityInterface $entity, bool $protect_all): array {
    $translations = $entity->getTranslationLanguages();
    if (count($translations) < 2) {
      return [];
    }

    $snapshot = [];
    foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition->getType() !== ComponentTreeItem::PLUGIN_ID) {
        continue;
      }
      foreach ($translations as $langcode => $language) {
        $translation = $entity->getTranslation($langcode);
        if ($protect_all || ComponentTreeTranslationFork::isForkedTranslation($translation)) {
          $snapshot[$langcode][$field_name] = $translation->get($field_name)->getValue();
        }
      }
    }
    return $snapshot;
  }

  /**
   * Restores component tree values captured before synchronization ran.
   *
   * Restoring values core did not touch is a no-op, which is what makes
   * snapshot/restore safe regardless of which of core's merge branches ran.
   *
   * @param array<string, array<string, array<int, array<string, mixed>>>> $snapshot
   *   The return value of ::snapshotProtectedComponentTreeValues().
   */
  private static function restoreComponentTreeValues(ContentEntityInterface $entity, array $snapshot): void {
    foreach ($snapshot as $langcode => $fields) {
      if (!$entity->hasTranslation($langcode)) {
        continue;
      }
      $translation = $entity->getTranslation($langcode);
      foreach ($fields as $field_name => $values) {
        $translation->get($field_name)->setValue($values);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function synchronizeItems(array &$field_values, array $unchanged_items, $sync_langcode, array $translations, array $properties): void {
    $this->decorated->synchronizeItems($field_values, $unchanged_items, $sync_langcode, $translations, $properties);
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldSynchronizedProperties(FieldDefinitionInterface $field_definition): array {
    return $this->decorated->getFieldSynchronizedProperties($field_definition);
  }

  /**
   * Syncs non-translatable component instance input keys across translations.
   *
   * After core's synchronizer aligns tree structure (same UUIDs, same order),
   * this propagates non-translatable input values from the default translation
   * to all other translations, for every component tree field on the entity.
   *
   * @param array<string, list<string>> $net_new
   *   UUIDs of net new component instances in the default translation, keyed
   *   by component tree field name.
   */
  private function synchronizeComponentInstanceInputs(ContentEntityInterface $entity, array $net_new): void {
    foreach ($this->allComponentTreeSymmetricalTranslations($entity) as $target_tree) {
      $field_name = $target_tree->getName();
      \assert(\is_string($field_name));
      $field_net_new = $net_new[$field_name] ?? [];
      $default_tree = $entity->getUntranslated()->get($field_name);
      \assert($default_tree instanceof ComponentTreeItemList);
      foreach ($target_tree as $delta => $target_item) {
        \assert($target_item instanceof ComponentTreeItem);
        $default_item = $default_tree->get($delta);
        \assert($default_item instanceof ComponentTreeItem);

        $default_inputs = $default_item->getInputs() ?? [];
        $target_inputs = $target_item->getInputs() ?? [];

        $inputs_typed_data = $default_item->get('inputs');
        \assert($inputs_typed_data instanceof ComponentInputs);
        $translatable_keys = \array_flip($inputs_typed_data->getTranslatableInputKeys());
        $non_translatable_inputs = \array_diff_key($default_inputs, $translatable_keys);

        // Non-translatable from default, translatable from target (or
        // default for newly added instances with no prior translation).
        $target_item->setInput(\array_merge(
          !\in_array($target_item->getUuid(), $field_net_new, TRUE)
            ? $target_inputs
            // Core's createMergedItem() merges by delta position; after a
            // prepend/reorder, target_item may carry inputs from a different
            // instance.
            // Unlike most field types, Canvas' field properties are
            // dependent: which `inputs` make sense depends on the
            // `component_id` and `component_version` field properties.
            // Rectify what core did: use the default translation's inputs,
            // not some other component instance in the current translation
            // that happened to previously live at the delta of the new
            // component instance (i.e. $target_inputs exists but is wrong!).
            // @see \Drupal\content_translation\FieldTranslationSynchronizer::createMergedItem()
            : $default_inputs,
          $non_translatable_inputs,
        ));
      }
    }
  }

  /**
   * Returns UUIDs of instances that are new in the default translation.
   *
   * "New" means not yet present in any non-default translation.
   *
   * @return array<string, list<string>>
   *   Net new component instance UUIDs, keyed by component tree field name.
   */
  private function getDefaultTranslationNewComponentInstanceUuids(ContentEntityInterface $entity): array {
    $net_new = [];
    foreach ($this->allComponentTreeSymmetricalTranslations($entity) as $target_tree) {
      $field_name = $target_tree->getName();
      \assert(\is_string($field_name));
      // All symmetric (non-forked) non-default translations must be in sync,
      // so checking only the first one suffices — per field.
      if (\array_key_exists($field_name, $net_new)) {
        continue;
      }
      $default_tree = $entity->getUntranslated()->get($field_name);
      \assert($default_tree instanceof ComponentTreeItemList);
      $items = iterator_to_array($default_tree->componentTreeItemsIterator(
        ComponentTreeItemList::doesNotExistInOtherComponentTree($target_tree)
      ));
      $net_new[$field_name] = \array_values(\array_map(
        fn (ComponentTreeItem $i) => $i->getUuid(),
        $items,
      ));
    }
    return $net_new;
  }

  /**
   * Yields each non-default translation's symmetrically translated tree.
   *
   * Iterates every component tree field in symmetrical translation mode (tree
   * synced, inputs translatable) and yields each non-default translation's
   * field item list. Forked translations own their trees and are skipped.
   *
   * @return \Generator<int, ComponentTreeItemList>
   */
  private function allComponentTreeSymmetricalTranslations(ContentEntityInterface $entity): \Generator {
    $translations = $entity->getTranslationLanguages();
    if (count($translations) < 2) {
      return;
    }

    $default_translation = $entity->getUntranslated();
    $default_langcode = $default_translation->language()->getId();

    foreach ($default_translation->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition->getType() !== ComponentTreeItem::PLUGIN_ID) {
        continue;
      }
      if (!self::isSymmetricallyTranslated($this->decorated, $field_definition)) {
        continue;
      }

      foreach ($translations as $langcode => $language) {
        if ($langcode === $default_langcode) {
          continue;
        }
        $translation = $entity->getTranslation($langcode);
        if (ComponentTreeTranslationFork::isForkedTranslation($translation)) {
          continue;
        }
        $target_tree = $translation->get($field_name);
        \assert($target_tree instanceof ComponentTreeItemList);
        yield $target_tree;
      }
    }
  }

  public static function isTreeSynced(FieldTranslationSynchronizerInterface $synchronizer, FieldDefinitionInterface $field_definition): bool {
    return \in_array('uuid', $synchronizer->getFieldSynchronizedProperties($field_definition), TRUE);
  }

  public static function isInputsSynced(FieldTranslationSynchronizerInterface $synchronizer, FieldDefinitionInterface $field_definition): bool {
    return \in_array('inputs', $synchronizer->getFieldSynchronizedProperties($field_definition), TRUE);
  }

  /**
   * Returns TRUE if the field is in symmetrical translation mode.
   *
   * Symmetrical mode: tree structure synced, inputs translatable.
   *
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer::isAsymmetricallyTranslated()
   */
  public static function isSymmetricallyTranslated(FieldTranslationSynchronizerInterface $synchronizer, FieldDefinitionInterface $field_definition): bool {
    return self::isTreeSynced($synchronizer, $field_definition) && !self::isInputsSynced($synchronizer, $field_definition);
  }

  /**
   * Returns TRUE if the field is in asymmetrical translation mode.
   *
   * Asymmetrical mode: both tree structure and inputs are translatable
   * (nothing synced).
   *
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer::isSymmetricallyTranslated()
   */
  public static function isAsymmetricallyTranslated(FieldTranslationSynchronizerInterface $synchronizer, FieldDefinitionInterface $field_definition): bool {
    return !self::isTreeSynced($synchronizer, $field_definition) && !self::isInputsSynced($synchronizer, $field_definition);
  }

  /**
   * Forces symmetrical translation of the Canvas Page `components` field.
   *
   * Loads the base field override storing the `translation_sync` third party
   * setting, or creates it, and sets the only supported combination: input
   * values of component instances are translatable, the component tree is
   * shared across languages.
   *
   * This cannot be shipped as `config/optional`: recipes import a module's
   * optional config unconditionally (no dependency gating), so a recipe
   * importing canvas config without content_translation installed would fail
   * validation on the `content_translation` module dependency.
   *
   * Must only be called when the content_translation module is installed.
   *
   * @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
   * @see canvas_post_update_0022_enforce_symmetrical_canvas_page_components_translation()
   * @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3571130
   */
  public static function ensureSymmetricalCanvasPageComponents(): void {
    $entity_field_manager = \Drupal::service(EntityFieldManagerInterface::class);
    \assert($entity_field_manager instanceof EntityFieldManagerInterface);
    $base_field_definitions = $entity_field_manager->getBaseFieldDefinitions(Page::ENTITY_TYPE_ID);
    \assert($base_field_definitions['components'] instanceof BaseFieldDefinition);
    $override = $base_field_definitions['components']->getConfig(Page::ENTITY_TYPE_ID);
    $override->setThirdPartySetting('content_translation', 'translation_sync', [
      'inputs' => 'inputs',
      'tree' => '0',
    ]);
    $override->save();
  }

}
