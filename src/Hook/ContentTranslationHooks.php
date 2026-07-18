<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\ContentTranslation\ComponentTreeTranslationFork;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeSymmetricalTranslationConstraint;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Hook implementations for content_translation integration.
 */
final readonly class ContentTranslationHooks {

  public function __construct(
    private ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Whether per-translation component tree forks are enabled on this site.
   *
   * All fork behavior ships gated behind the canvas_dev_translation
   * experimental module (in addition to content_translation), following the
   * existing translation gating pattern. It moves into canvas proper when
   * that flag module is removed.
   *
   * @see https://git.drupalcode.org/project/canvas/-/work_items/3571130
   */
  public static function translationForksEnabled(): bool {
    $module_handler = \Drupal::moduleHandler();
    return $module_handler->moduleExists('content_translation')
      && $module_handler->moduleExists('canvas_dev_translation');
  }

  /**
   * The definition of the per-translation component tree fork base field.
   *
   * Translatable so each translation carries its own fork state, revisionable
   * so fork state follows the entity's revision history, and without form or
   * view displays: it is toggled only through the fork/unfork HTTP API.
   */
  public static function forkBaseFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Component tree translation is forked'))
      ->setDescription(new TranslatableMarkup('Whether this translation owns an independent Canvas component tree, excluded from symmetric translation synchronization.'))
      ->setTranslatable(TRUE)
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE);
  }

  /**
   * Implements hook_entity_base_field_info().
   *
   * Adds the `canvas_component_tree_fork` base field to every translatable
   * content entity type, so any entity with a component tree field can fork
   * individual translations. One flag covers all component tree fields on the
   * entity. The flag on the default translation is ignored.
   *
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeTranslationFork::isForkedTranslation()
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if (!$this->moduleHandler->moduleExists('content_translation')
      || !$this->moduleHandler->moduleExists('canvas_dev_translation')) {
      return [];
    }
    if (!$entity_type instanceof ContentEntityTypeInterface || !$entity_type->isTranslatable()) {
      return [];
    }
    return [
      ComponentTreeTranslationFork::FIELD_NAME => self::forkBaseFieldDefinition(),
    ];
  }

  /**
   * Installs the fork field's storage definitions on all eligible types.
   *
   * Needed when content_translation or canvas_dev_translation get installed
   * after canvas (entity schema for already-installed entity types does not
   * pick up new base fields automatically), and by the update hook enabling
   * fork support on existing sites.
   *
   * Must only be called when ::translationForksEnabled() is TRUE.
   *
   * @see canvas_update_11201()
   */
  public static function installForkFieldStorageDefinitions(): void {
    $definition_update_manager = \Drupal::entityDefinitionUpdateManager();
    $field_name = ComponentTreeTranslationFork::FIELD_NAME;
    foreach (\Drupal::entityTypeManager()->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type instanceof ContentEntityTypeInterface || !$entity_type->isTranslatable()) {
        continue;
      }
      if ($definition_update_manager->getFieldStorageDefinition($field_name, $entity_type_id) !== NULL) {
        continue;
      }
      $definition_update_manager->installFieldStorageDefinition(
        $field_name,
        $entity_type_id,
        'canvas',
        self::forkBaseFieldDefinition(),
      );
    }
  }

  /**
   * Uninstalls the fork field's storage definitions from all entity types.
   *
   * The counterpart of ::installForkFieldStorageDefinitions(): when one of the
   * gating modules is uninstalled, hook_entity_base_field_info() stops
   * providing the field, so its storage definitions must be uninstalled or
   * every translatable entity type would report mismatched entity and field
   * definitions.
   */
  public static function uninstallForkFieldStorageDefinitions(): void {
    $definition_update_manager = \Drupal::entityDefinitionUpdateManager();
    $field_name = ComponentTreeTranslationFork::FIELD_NAME;
    foreach (\array_keys(\Drupal::entityTypeManager()->getDefinitions()) as $entity_type_id) {
      $definition = $definition_update_manager->getFieldStorageDefinition($field_name, $entity_type_id);
      if ($definition !== NULL && $definition->getProvider() === 'canvas') {
        $definition_update_manager->uninstallFieldStorageDefinition($definition);
      }
    }
  }

  /**
   * Implements hook_modules_uninstalled().
   */
  #[Hook('modules_uninstalled')]
  public function modulesUninstalled(array $modules, bool $is_syncing): void {
    if (\array_intersect(['content_translation', 'canvas_dev_translation'], $modules) === []) {
      return;
    }
    if ($this->moduleHandler->moduleExists('content_translation')
      && $this->moduleHandler->moduleExists('canvas_dev_translation')) {
      return;
    }
    self::uninstallForkFieldStorageDefinitions();
  }

  /**
   * Marks translations whose component trees already diverged as forked.
   *
   * Before symmetric synchronization was guaranteed, sites that translated
   * Canvas fields without `translation_sync` settings could accumulate
   * divergent trees; marking those translations forked protects them from
   * being overwritten by the now-enforced synchronization. Idempotent:
   * already-forked translations are skipped and only actual divergence is
   * marked.
   *
   * Must only be called when ::translationForksEnabled() is TRUE.
   *
   * @see canvas_post_update_0023_mark_divergent_component_tree_translations_forked()
   */
  public static function markDivergentComponentTreeTranslationsForked(): void {
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_field_manager = \Drupal::service('entity_field.manager');
    \assert($entity_field_manager instanceof EntityFieldManagerInterface);
    $fork_field_name = ComponentTreeTranslationFork::FIELD_NAME;

    // The raw values of the columns shared across symmetric translations.
    // @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::propertyDefinitions()
    $tree_columns = \array_flip(['uuid', 'parent_uuid', 'slot', 'component_id', 'component_version']);
    $extract_tree = static fn (FieldItemListInterface $items): array => \array_map(
      static fn (array $item): array => \array_intersect_key($item, $tree_columns),
      $items->getValue(),
    );

    // ponytail: unbatched full scan; sites carrying the experimental flag
    // module are dev-scale today. Batch per entity type if that changes.
    $field_map = $entity_field_manager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    foreach ($field_map as $entity_type_id => $fields) {
      $entity_type = $entity_type_manager->getDefinition($entity_type_id);
      if (!$entity_type instanceof ContentEntityTypeInterface || !$entity_type->isTranslatable()) {
        continue;
      }
      $storage = $entity_type_manager->getStorage($entity_type_id);
      $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
      foreach ($storage->loadMultiple($ids) as $entity) {
        if (!$entity instanceof ContentEntityInterface || !$entity->hasField($fork_field_name)) {
          continue;
        }
        $default_translation = $entity->getUntranslated();
        $changed = FALSE;
        foreach ($entity->getTranslationLanguages(FALSE) as $langcode => $language) {
          $translation = $entity->getTranslation($langcode);
          if (ComponentTreeTranslationFork::isForkedTranslation($translation)) {
            continue;
          }
          foreach (\array_keys($fields) as $field_name) {
            $field_name = (string) $field_name;
            if (!$translation->hasField($field_name)) {
              continue;
            }
            if ($extract_tree($translation->get($field_name)) !== $extract_tree($default_translation->get($field_name))) {
              $translation->set($fork_field_name, TRUE);
              $changed = TRUE;
              break;
            }
          }
        }
        if ($changed) {
          // The just-set fork flags shield the divergent trees from the
          // synchronization this save would otherwise apply.
          $entity->save();
        }
      }
    }
  }

  /**
   * Implements hook_entity_type_alter().
   *
   * Attaches the ComponentTreeSymmetricalTranslation constraint to all
   * translatable entity types, so that saves are rejected when non-translatable
   * component input keys differ from the default translation.
   *
   * Only active when `content_translation` is installed.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeSymmetricalTranslationConstraint
   * @see \Drupal\content_translation\Plugin\Validation\Constraint\ContentTranslationSynchronizedFieldsConstraint
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    // @todo Refactor to use `HookDependsOnModule` once Canvas depends on Drupal 11.5's https://www.drupal.org/node/3548805
    if (!$this->moduleHandler->moduleExists('content_translation')) {
      return;
    }

    // A sibling exists for config-defined component trees.
    // @see \Drupal\canvas\Hook\ConfigTranslationHooks::entityTypeAlter()
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasConfigEntityTranslationsAreValidConstraint
    foreach ($entity_types as $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface && $entity_type->isTranslatable()) {
        $entity_type->addConstraint(ComponentTreeSymmetricalTranslationConstraint::PLUGIN_ID);
      }
    }
  }

  /**
   * Implements hook_modules_installed().
   *
   * Ensures the Canvas Page `components` base field override exists with the
   * symmetrical `translation_sync` setting whenever canvas and
   * content_translation are both installed, in whichever order.
   *
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalCanvasPageComponents()
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    // Fork support activates when the last of the gating modules arrives; the
    // fork base field's storage definitions must then be installed for entity
    // types whose schema already exists, and translations that already
    // diverged (from the era before symmetric synchronization was guaranteed)
    // must be marked forked before the sync can overwrite them. Not skipped
    // during config sync: the field is content (entity schema), not config.
    if (\array_intersect(['canvas', 'content_translation', 'canvas_dev_translation'], $modules) !== []
      && self::translationForksEnabled()) {
      self::installForkFieldStorageDefinitions();
      self::markDivergentComponentTreeTranslationsForked();
    }

    // During config sync, the override comes from the synced config itself.
    if ($is_syncing) {
      return;
    }
    if (\array_intersect(['canvas', 'content_translation'], $modules) === []) {
      return;
    }
    if (!$this->moduleHandler->moduleExists('content_translation')) {
      return;
    }
    ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalCanvasPageComponents();
  }

}
