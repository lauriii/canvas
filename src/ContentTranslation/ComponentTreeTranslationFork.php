<?php

declare(strict_types=1);

namespace Drupal\canvas\ContentTranslation;

use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Per-translation component tree fork state and operations.
 *
 * A forked translation owns an independent component tree: it is excluded
 * from symmetric translation synchronization in both directions.
 *
 * Deliberately free of any content_translation class dependency: callers such
 * as the layout controller and the component source manager run on sites
 * without content_translation, where merely autoloading a class implementing
 * one of its interfaces would fatal.
 *
 * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer
 */
final class ComponentTreeTranslationFork {

  /**
   * Base field marking a translation's component trees as forked.
   *
   * The field is added to all translatable content entity types when
   * content_translation is installed.
   *
   * @see \Drupal\canvas\Hook\ContentTranslationHooks::entityBaseFieldInfo()
   */
  public const string FIELD_NAME = 'canvas_component_tree_fork';

  /**
   * Returns TRUE if this translation's component trees are forked.
   *
   * The default translation is never forked (its tree is the reference the
   * symmetric siblings synchronize with), and entities without the fork base
   * field (fork support not enabled) are never forked.
   */
  public static function isForkedTranslation(ContentEntityInterface $translation): bool {
    if ($translation->isDefaultTranslation()) {
      return FALSE;
    }
    if (!$translation->hasField(self::FIELD_NAME)) {
      return FALSE;
    }
    return (bool) $translation->get(self::FIELD_NAME)->value;
  }

  /**
   * Initializes a translation that does not exist in storage yet.
   *
   * Translatable values start as copies of the default translation's, like on
   * content_translation's add translation form, except the URL alias: a copied
   * alias would collide with the default translation's. The translation is
   * added to a clone, so the given entity is never modified.
   *
   * Used for translations forked before they are first published: such a
   * translation exists only in its auto-save draft until then.
   *
   * @see \Drupal\content_translation\Controller\ContentTranslationController::prepareTranslation()
   */
  public static function initializeTranslation(ContentEntityInterface $entity, string $langcode): ContentEntityInterface {
    $default_translation = clone $entity->getUntranslated();
    $values = $default_translation->toArray();
    unset($values['path']);
    $translation = $default_translation->addTranslation($langcode, $values);
    if ($translation->hasField('content_translation_source')) {
      $translation->set('content_translation_source', $default_translation->language()->getId());
    }
    return $translation;
  }

  /**
   * Re-synchronizes a translation's component trees from the default one.
   *
   * Used when unforking: the translation's tree structure and
   * non-translatable inputs are replaced by the default translation's current
   * values, while the translation's own translatable input values are
   * re-applied for component instances that still exist in the default tree.
   * Component instances that exist only in the translation are discarded.
   */
  public static function resyncFromDefaultTranslation(ContentEntityInterface $translation): void {
    $default_translation = $translation->getUntranslated();
    if ($translation === $default_translation) {
      return;
    }
    foreach ($translation->getFieldDefinitions() as $field_name => $field_definition) {
      if ($field_definition->getType() !== ComponentTreeItem::PLUGIN_ID) {
        continue;
      }
      $target_tree = $translation->get($field_name);
      \assert($target_tree instanceof ComponentTreeItemList);
      // Capture the translation's current input values by component instance
      // UUID before overwriting the tree with the default translation's.
      $translated_inputs = [];
      foreach ($target_tree as $item) {
        \assert($item instanceof ComponentTreeItem);
        $translated_inputs[$item->getUuid()] = self::getInputsSafely($item);
      }

      $default_tree = $default_translation->get($field_name);
      \assert($default_tree instanceof ComponentTreeItemList);
      $target_tree->setValue($default_tree->getValue());

      // Re-apply the translation's translatable input values for surviving
      // component instances; non-translatable keys come from the default.
      foreach ($target_tree as $item) {
        \assert($item instanceof ComponentTreeItem);
        $uuid = $item->getUuid();
        if (!\array_key_exists($uuid, $translated_inputs)) {
          continue;
        }
        $inputs_typed_data = $item->get('inputs');
        \assert($inputs_typed_data instanceof ComponentInputs);
        $translatable_keys = \array_flip($inputs_typed_data->getTranslatableInputKeys());
        $item->setInput(\array_merge(
          self::getInputsSafely($item),
          \array_intersect_key($translated_inputs[$uuid], $translatable_keys),
        ));
      }
    }
  }

  /**
   * Returns a component instance's inputs, treating broken state as empty.
   *
   * ComponentTreeItem::getInputs() throws for anticipated bad states (deleted
   * Component config entity, unpopulated default value); a fork being
   * unforked must survive those the same way getTranslatableInputKeys() and
   * optimizeInputs() do, rather than aborting with a 500 and leaving the
   * translation stuck forked.
   *
   * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::getValues()
   */
  private static function getInputsSafely(ComponentTreeItem $item): array {
    try {
      return $item->getInputs() ?? [];
    }
    catch (\Exception) {
      return [];
    }
  }

}
