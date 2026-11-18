<?php

declare(strict_types=1);

namespace Drupal\canvas\ContentTranslation;

use Drupal\canvas\Controller\ApiContentTemplateSlotFieldController;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\content_translation\FieldTranslationSynchronizerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Decorates field translation synchronizer to sync component instance inputs.
 *
 * After core's synchronizer handles tree-structure synchronization (the 'tree'
 * column group: uuid, parent_uuid, slot, component_id, component_version),
 * this decorator additionally synchronizes non-translatable input keys within
 * the 'inputs' JSON property across all translations. It also synchronizes an
 * empty tree, which core's synchronizer skips.
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
    $net_new = $this->getDefaultTranslationNewComponentInstanceUuids($entity);
    $this->decorated->synchronizeFields($entity, $sync_langcode, $original_langcode);
    $this->synchronizeEmptyComponentTrees($entity, $sync_langcode);
    $this->synchronizeComponentInstanceInputs($entity, $net_new);
  }

  /**
   * Empties a symmetric component tree in all translations if the source is.
   *
   * Core's synchronizer skips fields that are empty in the translation being
   * saved. For component tree fields, empty is a valid state: a page without
   * components, or an exposed slot field that inherits the template's default
   * content. The tree is shared across all translations, so it must be emptied
   * in every translation.
   *
   * @see \Drupal\content_translation\FieldTranslationSynchronizer::synchronizeFields()
   */
  private function synchronizeEmptyComponentTrees(ContentEntityInterface $entity, ?string $sync_langcode): void {
    $translations = $entity->getTranslationLanguages();
    if (empty($sync_langcode) || $entity->isNew() || \count($translations) < 2) {
      return;
    }
    // Same synchronization source as core.
    if ($entity->isNewRevision() && $entity->isDefaultTranslationAffectedOnly()) {
      if (!$entity->isDefaultRevision()) {
        return;
      }
      $sync_langcode = $entity->getUntranslated()->language()->getId();
    }

    $source = $entity->getTranslation($sync_langcode);
    foreach ($source->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition->getType() !== ComponentTreeItem::PLUGIN_ID) {
        continue;
      }
      if (!self::isSymmetricallyTranslated($this->decorated, $field_definition) || !$source->get($field_name)->isEmpty()) {
        continue;
      }
      foreach (\array_keys($translations) as $langcode) {
        $entity->getTranslation($langcode)->get($field_name)->setValue([]);
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
   *   by field name.
   */
  private function synchronizeComponentInstanceInputs(ContentEntityInterface $entity, array $net_new): void {
    foreach ($this->allComponentTreeSymmetricalTranslations($entity) as $target_tree) {
      $field_name = $target_tree->getName();
      $default_tree = $entity->getUntranslated()->get($field_name);
      \assert($default_tree instanceof ComponentTreeItemList);
      $field_net_new = $net_new[$field_name] ?? [];
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
   *   UUIDs keyed by field name.
   */
  private function getDefaultTranslationNewComponentInstanceUuids(ContentEntityInterface $entity): array {
    $net_new = [];
    foreach ($this->allComponentTreeSymmetricalTranslations($entity) as $target_tree) {
      $field_name = (string) $target_tree->getName();
      // All non-default translations must be in sync, so checking only the
      // first non-default translation of each field suffices.
      if (isset($net_new[$field_name])) {
        continue;
      }
      $default_tree = $entity->getUntranslated()->get($field_name);
      \assert($default_tree instanceof ComponentTreeItemList);
      $net_new[$field_name] = \array_values(\array_map(
        fn (ComponentTreeItem $i) => $i->getUuid(),
        iterator_to_array($default_tree->componentTreeItemsIterator(
          ComponentTreeItemList::doesNotExistInOtherComponentTree($target_tree)
        )),
      ));
    }
    return $net_new;
  }

  /**
   * Yields each non-default translation's symmetrically translated tree.
   *
   * Iterates every component tree field in symmetrical translation mode (tree
   * synced, inputs translatable) and yields each non-default translation's
   * field item list.
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
        $target_tree = $entity->getTranslation($langcode)->get($field_name);
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

  /**
   * Sets symmetrical translation on a slot field.
   *
   * The same combination as for the Canvas Page `components` field: input
   * values of component instances are translatable, the component tree is
   * shared across languages. Does not save the field.
   *
   * Must only be called when the content_translation module is installed.
   *
   * @see \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::create()
   * @see self::ensureSymmetricalSlotFields()
   */
  public static function setSymmetricalSlotFieldTranslation(FieldConfigInterface $field_config): void {
    $field_config->setThirdPartySetting('content_translation', 'translation_sync', [
      'inputs' => 'inputs',
      'tree' => '0',
    ]);
  }

  /**
   * Sets symmetrical translation on slot fields that have no setting yet.
   *
   * A slot field created before content_translation was installed has no
   * `translation_sync` setting, so it would be translated asymmetrically.
   * Other `component_tree` fields and explicit settings are not changed.
   *
   * Must only be called when the content_translation module is installed.
   *
   * @see \Drupal\canvas\Hook\ContentTranslationHooks::modulesInstalled()
   */
  public static function ensureSymmetricalSlotFields(): void {
    $entity_type_manager = \Drupal::service(EntityTypeManagerInterface::class);
    \assert($entity_type_manager instanceof EntityTypeManagerInterface);
    // Without the field module, there are no slot fields.
    if (!$entity_type_manager->hasDefinition('field_config')) {
      return;
    }
    $storage = $entity_type_manager->getStorage('field_config');
    $ids = $storage->getQuery()
      ->condition('field_type', ComponentTreeItem::PLUGIN_ID)
      ->condition('field_name', ApiContentTemplateSlotFieldController::FIELD_NAME_PREFIX, 'STARTS_WITH')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $field_config) {
      \assert($field_config instanceof FieldConfigInterface);
      if ($field_config->getThirdPartySetting('content_translation', 'translation_sync') !== NULL) {
        continue;
      }
      self::setSymmetricalSlotFieldTranslation($field_config);
      $field_config->save();
    }
  }

}
