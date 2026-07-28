<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\language\Config\LanguageConfigOverride;
use Drupal\language\ConfigurableLanguageManagerInterface;

/**
 * @internal
 *
 * @see \Drupal\canvas\EventSubscriber\ComponentTreeConfigEntityTransformer
 *
 * @phpstan-import-type ComponentTreeItemArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type ComponentTreeItemListArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-type ComponentTreeItemKeyedSequence array<string, ComponentTreeItemArray>
 * @phpstan-type LanguageCode string
 */
abstract class ComponentTreeConfigEntityBase extends ConfigEntityBase implements ComponentTreeEntityInterface {

  use ComponentTreeItemListInstantiatorTrait;
  use ConfigUpdaterAwareEntityTrait;

  /**
   * The component tree.
   *
   * @var ?array<string, ComponentTreeItemArray>
   */
  protected ?array $component_tree;

  /**
   * An array of entity translation metadata.
   *
   * An associative array keyed by translation language code. Every value is a
   * translation object (StagedLanguageConfigOverride). Populated lazily.
   *
   * Repeated calls for the same langcode return the same instance, so in-memory
   * mutations live as long as this object lives.
   *
   * @var array<LanguageCode, \Drupal\canvas\Entity\StagedLanguageConfigOverride>
   *
   * @see ::getTranslation()
   * @see \Drupal\Core\Entity\ContentEntityBase::$translations
   */
  private array $stagedOverrides = [];

  /**
   * Transforms a component tree sequence to have no JSON strings as inputs.
   *
   * Canvas reuses ComponentTreeItem(List) for config-defined component trees at
   * run time: for rendering, validating, et cetera.
   *
   * This means that ComponentTreeItem's `inputs` field property may be used in
   * some ::set() or ::setComponentTree() calls to this class, which represents
   * the explicit inputs as a JSON string. Config-defined component
   * Config-defined component trees must be translatable though, and only a
   * subset of each component instance's explicit inputs may be translatable.
   *
   * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::propertyDefinitions()
   * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::$value
   * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::setValue()
   *
   * Hence config schema is generated on-the-fly for each config-defined
   * component instance: based on the referenced Component config entity (and
   * its version). This enables Drupal's config_translation module to
   * automatically handle translated explicit inputs (think: block labels, and
   * string/HTML SDC props).
   *
   * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
   *
   * This in turn means that when config entities with component trees are:
   * - validated, `inputs` will be validated by by Drupal's ValidKeysConstraint
   * - saved, `inputs` will be validated by Drupal's ConfigSchemaChecker
   * In both cases, `inputs` being a JSON string would trigger an error.
   *
   * Note that the inverse direction (converting `inputs`' key-value pairs to
   * blobs) is not necessary because `ComponentInputs::setValue()` transparently
   * handles that already for DX reasons.
   *
   * @internal
   */
  public static function componentTreeInstancesInputsMustBeArrays(array $component_tree_sequence): array {
    return \array_map(
      function (array $component_instance): array {
        \assert(\array_key_exists('inputs', $component_instance));
        if (\is_string($component_instance['inputs'])) {
          $component_instance['inputs'] = Json::decode($component_instance['inputs']);
        }
        return $component_instance;
      },
      $component_tree_sequence,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    if (\array_key_exists('component_tree', $values)) {
      $values['component_tree'] = self::componentTreeInstancesInputsMustBeArrays($values['component_tree']);
    }
    parent::preCreate($storage, $values);
  }

  /**
   * {@inheritdoc}
   */
  public function set($property_name, $value) {
    // Reset statically cached config translations: when this config entity is
    // modified, its translations may have been modified in tandem.
    $this->stagedOverrides = [];
    if ($property_name === 'component_tree') {
      $value = self::componentTreeInstancesInputsMustBeArrays($value);
    }
    return parent::set($property_name, $value);
  }

  /**
   * Transforms a component tree sequence to have translation-targetable keys.
   *
   * @param ComponentTreeItemKeyedSequence|ComponentTreeItemListArray $component_tree_sequence
   *   The raw component tree sequence, which may have string keys (typically if
   *   importing an exported config entity or loading a saved config entity), or
   *   integer keys (if constructing a config-defined component tree initially).
   *
   * @return ComponentTreeItemKeyedSequence
   *   The same sequence (the same values in the same order), but now
   *   deterministic keys that uniquely identify the component instance using
   *   its instance UUID (to allow symmetrical config translations to target a
   *   given component instance even when that instance is moved).
   */
  private static function asDeterministicallyAndTranslatableKeyedComponentTreeSequence(array $component_tree_sequence): array {
    return \array_combine(
      \array_column($component_tree_sequence, 'uuid'),
      \array_values($component_tree_sequence),
    );
  }

  public function setComponentTree(array $values): static {
    // TRICKY: a config entity with a translated component tree has sequence
    // keys: those are essential for config translation. Omitting them would
    // cause validation to fail.
    if (\array_is_list($values) && !$this->isNew() && count($this->getTranslationLanguages(include_default: FALSE)) > 0) {
      // @phpcs:ignore Drupal.Semantics.FunctionTriggerError.TriggerErrorTextLayoutRelaxed
      @trigger_error(\sprintf("Changing a config entity with an already-translated component tree requires sequence keys, not delta integers. Auto-fixing using the component instance UUIDs."), E_USER_DEPRECATED);
      $values = self::asDeterministicallyAndTranslatableKeyedComponentTreeSequence($values);
    }
    $this->set('component_tree', $values);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Works around Typed Data static caching bugs in core's EntityBase, remove after https://www.drupal.org/project/drupal/issues/3571532 is fixed.
   */
  public function createDuplicate() {
    $duplicate = parent::createDuplicate();
    unset($duplicate->typedData);
    return $duplicate;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    self::getConfigUpdater()->updateConfigEntityWithComponentTreeInputs($this);
    self::getConfigUpdater()->updateConfigEntityWithComponentTreeInputsAsArrays($this);
    self::getConfigUpdater()->updateConfigEntityBlockLabelDisplay($this);
    // TRICKY: do not use ::setComponentTree() here because it expects integer
    // keys ("deltas") for component instances. Config-defined component trees
    // do not have deltas but sequence keys. Manipulate the config entity
    // property directly.
    // @see \Drupal\canvas\CanvasConfigUpdater::needsConfigEntityWithComponentTreeSequenceKeysUpdate()
    $component_tree = $this->get('component_tree') ?? [];
    // Config install and update paths bypass the sync import transformer, so
    // recover the intended order from the position-encoded keys here, before
    // they are re-keyed by UUID.
    // @see \Drupal\canvas\EventSubscriber\ComponentTreeConfigEntityTransformer::import()
    if (self::componentTreeSequenceIsPositionEncoded($component_tree)) {
      // Natural-order sort so whole-number segments compare numerically (`10`
      // sorts after `9`, not before `2`) and a parent's bare-delta key (`0`)
      // precedes its children (`0:the_body:0`).
      \uksort($component_tree, \strnatcmp(...));
    }
    $this->set('component_tree', self::asDeterministicallyAndTranslatableKeyedComponentTreeSequence(\array_values($component_tree)));
  }

  /**
   * Whether a component tree sequence still uses non-UUID (position) keys.
   *
   * @param array<int|string, array{uuid?: string}> $component_tree_sequence
   *   The raw component tree sequence.
   *
   * @return bool
   *   TRUE if any key is not its item's UUID — i.e. the sequence is keyed by
   *   position deltas or position-encoded sequence keys, not yet by UUID.
   */
  private static function componentTreeSequenceIsPositionEncoded(array $component_tree_sequence): bool {
    foreach ($component_tree_sequence as $key => $item) {
      if ($key !== ($item['uuid'] ?? NULL)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Works around Typed Data static caching bugs in core's EntityBase, remove after https://www.drupal.org/project/drupal/issues/3571532 is fixed.
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    unset($this->typedData);
    parent::postSave($storage, $update);
  }

  /**
   * Builds the full translated component tree for the given language.
   *
   * Merges the base config's component_tree with the sparse
   * LanguageConfigOverride for $langcode and returns a dangling
   * ComponentTreeItemList containing the merged result. The entity's own
   * component_tree property is still the V1 (pre-update) base at call time, so
   * the returned tree is the V1 translated tree ready for the updater to run.
   *
   * @return \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
   *   A dangling ComponentTreeItemList containing the merged (base + override)
   *   component tree, not yet updated.
   *
   * @internal
   */
  public function getTranslatedComponentTree(string $langcode): ComponentTreeItemList {
    $base = $this->toArray();
    // A LanguageConfigOverride always targets component instances by their UUID
    // sequence key. The base component_tree, however, may still be delta-keyed:
    // e.g. an auto-save draft created before any translation existed, since
    // ::setComponentTree() only converts to sequence keys once a translation is
    // present. Re-key the base by UUID so the two align on merge; otherwise
    // NestedArray::mergeDeepArray() would treat them as disjoint and emit
    // phantom, instance-less entries (an override's sparse inputs with no
    // component_id or UUID).
    $base['component_tree'] = self::asDeterministicallyAndTranslatableKeyedComponentTreeSequence(
      \array_values($base['component_tree'] ?? []),
    );
    $override = $this->getTranslation($langcode)->getData();
    $merged = NestedArray::mergeDeepArray([$base, $override], TRUE);
    $tree = $this->createDanglingComponentTreeItemList($this);
    $tree->setValue(\array_values($merged['component_tree'] ?? []));
    return $tree;
  }

  /**
   * Returns languages for which a non-empty LanguageConfigOverride exists.
   *
   * Analogous to TranslatableInterface::getTranslationLanguages(), but for
   * config entities. Only returns languages that have an existing (non-new)
   * override record, since config entity "translations" are sparse: a missing
   * override means the base config applies unchanged for that language.
   *
   * @param bool $include_default
   *   Whether to include the default language. Defaults to TRUE, but callers
   *   that want to iterate only non-default translations should pass FALSE.
   *
   * @return \Drupal\Core\Language\LanguageInterface[]
   *   Language objects keyed by langcode.
   *
   * @todo Remove when https://www.drupal.org/project/drupal/issues/3203918 is done.
   * @todo Move to interface + trait, to allow any config entity type to be able to benefit from auto-saved-config-translation-changes in Canvas. For now, Canvas only allows translating config entities with component trees, so not yet relevant.
   *
   * @internal
   */
  public function getTranslationLanguages(bool $include_default = TRUE): array {
    $language_manager = \Drupal::languageManager();
    if (!$language_manager instanceof ConfigurableLanguageManagerInterface) {
      return [];
    }
    $default_langcode = $language_manager->getDefaultLanguage()->getId();
    $config_name = $this->getConfigDependencyName();
    $languages = [];
    foreach ($language_manager->getLanguages() as $langcode => $language) {
      if (!$include_default && $langcode === $default_langcode) {
        continue;
      }
      if ($langcode === $default_langcode) {
        $languages[$langcode] = $language;
        continue;
      }
      $override = $language_manager->getLanguageConfigOverride($langcode, $config_name);
      if (!$override->isNew()) {
        $languages[$langcode] = $language;
      }
    }
    return $languages;
  }

  /**
   * Returns a StagedLanguageConfigOverride for the given langcode.
   *
   * Analogous to TranslatableInterface::getTranslation(), but returns a
   * StagedLanguageConfigOverride entity rather than a translated entity object.
   * The same instance is returned on repeated calls for the same langcode,
   * so in-memory mutations (e.g. from component instance updating) survive
   * across calls.
   *
   * Throws if called with the default language's langcode, since the default
   * translation is the base config — not an override.
   *
   * @return \Drupal\canvas\Entity\StagedLanguageConfigOverride
   *   Either
   *   - a stored StagedLanguageConfigOverride if one exists (note this object
   *     can be modified by reference), ::isNew() will return FALSE
   *   - a new one, constructed from LanguageConfigOverride (which itself could
   *     be empty/new), ::isNew() will return TRUE
   *
   * @todo Remove when https://www.drupal.org/project/drupal/issues/3203918 is done.
   * @todo Move to interface + trait, to allow any config entity type to be able to benefit from auto-saved-config-translation-changes in Canvas. For now, Canvas only allows translating config entities with component trees, so not yet relevant.
   *
   * @internal
   */
  public function getTranslation(string $langcode): StagedLanguageConfigOverride {
    if ($langcode === $this->langcode) {
      throw new \InvalidArgumentException(\sprintf('getTranslation() must not be called with the langcode of the default translation ("%s").', $this->langcode));
    }

    $language_manager = self::languageManager();
    if (!$language_manager instanceof ConfigurableLanguageManagerInterface) {
      throw new \InvalidArgumentException(\sprintf('getTranslation() must not be called on monolingual sites.'));
    }

    if (isset($this->stagedOverrides[$langcode])) {
      return $this->stagedOverrides[$langcode];
    }

    $override = $language_manager->getLanguageConfigOverride($langcode, $this->getConfigDependencyName());
    \assert($override instanceof LanguageConfigOverride);
    // fromLanguageConfigOverride() returns the pending auto-save draft when one
    // exists (via StagedLanguageConfigOverride::load()), so reconciliation,
    // validation and publishing operate on what will actually be published, not
    // on the last-published live override.
    $this->stagedOverrides[$langcode] = StagedLanguageConfigOverride::fromLanguageConfigOverride($override);
    return $this->stagedOverrides[$langcode];
  }

}
