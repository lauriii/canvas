<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer;
use Drupal\canvas\Plugin\Validation\Constraint\ComponentTreeSymmetricalTranslationConstraint;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
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
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer::isForkedTranslation()
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
      ComponentTreeFieldSymmetricalTranslationSynchronizer::FORK_FIELD_NAME => self::forkBaseFieldDefinition(),
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
    $field_name = ComponentTreeFieldSymmetricalTranslationSynchronizer::FORK_FIELD_NAME;
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
    // types whose schema already exists. Not skipped during config sync: the
    // field is content (entity schema), not config.
    if (\array_intersect(['canvas', 'content_translation', 'canvas_dev_translation'], $modules) !== []
      && self::translationForksEnabled()) {
      self::installForkFieldStorageDefinitions();
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
