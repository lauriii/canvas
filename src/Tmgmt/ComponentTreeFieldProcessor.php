<?php

declare(strict_types=1);

namespace Drupal\canvas\Tmgmt;

use Drupal\canvas\Config\Schema\ComponentInputsMapping;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Render\Element;
use Drupal\tmgmt_content\DefaultFieldProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * TMGMT field processor for component_tree fields.
 *
 * Extracts each translatable prop of each component instance's inputs as a
 * translatable string in TMGMT by converting to a ComponentInputsMapping typed
 * config object and then making use of ComponentInputsTranslatablesExtractor.
 *
 * @see \Drupal\canvas\Tmgmt\ComponentInputsTranslatablesExtractor
 * @see \Drupal\canvas\Config\Schema\ComponentInputsMapping
 */
final class ComponentTreeFieldProcessor extends DefaultFieldProcessor implements ContainerInjectionInterface {

  public function __construct(
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly ComponentInputsTranslatablesExtractor $componentInputsTranslatablesExtractor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(TypedConfigManagerInterface::class),
      $container->get(ComponentInputsTranslatablesExtractor::class),
    );
  }

  /**
   * Gets a ComponentTreeItem's `inputs` property as equivalent Typed Config.
   *
   * Note: This is the inverse of what happens during validation: then Typed
   * Config is converted to ComponentTreeItem (content entity field) object
   * representations. It happens to be that configuration schema has more
   * translatability metadata, so for translation purposes, it's the other way
   * around.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem $item
   *   A component instance.
   *
   * @return \Drupal\canvas\Config\Schema\ComponentInputsMapping
   *   The Typed Config representation of the component instance's `inputs`.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\ConfigComponentTreeTrait::conjureFieldItemObject()
   */
  private function asComponentInputsMapping(ComponentTreeItem $item): ComponentInputsMapping {
    $values = $item->getValue();
    // Field items store inputs as JSON; decode to the format expected by config
    // schema.
    $values['inputs'] = \json_decode($values['inputs'], TRUE, flags: \JSON_THROW_ON_ERROR);
    $parent_definition = [
      'type' => 'canvas.component_tree_node',
    ];
    $name = (string) $item->getName();
    $field_value = $this->typedConfigManager->create(
      $this->typedConfigManager->buildDataDefinition($parent_definition, $values, $name),
      $values,
      $name,
    );
    $definition = [
      'type' => 'mapping',
      'class' => ComponentInputsMapping::class,
      'label' => 'Input values for each component instance in the component tree',
    ];
    $inputs = $item->getInputs();
    /** @var \Drupal\canvas\Config\Schema\ComponentInputsMapping */
    return $this->typedConfigManager->create(
      $this->typedConfigManager->buildDataDefinition($definition, $inputs, 'inputs', $field_value),
      $inputs,
      'inputs',
      $field_value,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function extractTranslatableData(FieldItemListInterface $field): array {
    $items = [];

    foreach ($field as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);
      $mapping = $this->asComponentInputsMapping($item);
      $items[$delta] = $this->componentInputsTranslatablesExtractor->extractTranslatables($mapping, $item->getInputs() ?? []);
    }

    return ['#label' => $field->getFieldDefinition()->getLabel()] + $items;
  }

  /**
   * {@inheritdoc}
   */
  public function setTranslations($field_data, FieldItemListInterface $field): void {
    foreach (Element::children($field_data) as $delta) {
      $item_data = $field_data[$delta];
      if (!$field->offsetExists($delta)) {
        continue;
      }

      $item = $field->offsetGet($delta);
      \assert($item instanceof ComponentTreeItem);

      $inputs = $item->getInputs() ?? [];

      $changed = FALSE;
      foreach (Element::children($item_data) as $prop_key) {
        $found = FALSE;
        $inputs[$prop_key] = self::writeNestedTranslationsToInputs($item_data[$prop_key], $inputs[$prop_key] ?? NULL, $found);
        if ($found) {
          $changed = TRUE;
        }
      }

      if ($changed) {
        // Respect `inputs` order dictated by the ComponentSource plugin's
        // `inputs_config_schema_generator` handler.
        // @see \Drupal\canvas\Attribute\ComponentSource::__construct(inputs_config_schema_generator)
        // @see \Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface
        $config_schema_order = $this->asComponentInputsMapping($item)->getValidKeys();
        $inputs_in_schema_order = \array_replace(
          // Schema-ordered, all NULL.
          \array_fill_keys($config_schema_order, NULL),
          // $inputs filtered to schema keys only.
          \array_intersect_key($inputs, \array_flip($config_schema_order)),
        );
        // Values resolved to NULL (a discarded ∅ sentinel "translation") are
        // refilled from the default translation, because the entity cannot be
        // saved with a required prop absent: FieldItemList::preSave()
        // enforces required props. This must recurse: a composite input (e.g.
        // a sequence of mappings) can carry a discarded leaf next to a really
        // translated sibling, leaving a NULL at arbitrary depth. Values that
        // stay NULL (absent in the default translation too) are dropped.
        $default_inputs = self::defaultTranslationInputs($field, $delta);
        foreach ($inputs_in_schema_order as $key => $value) {
          $inputs_in_schema_order[$key] = self::refillDiscardedFromDefault($value, $default_inputs[$key] ?? NULL);
        }
        // Write only non-NULL (preserve FALSE, 0, '', []).
        $item->setInput(\array_filter($inputs_in_schema_order, static fn($v) => $v !== NULL));
      }
    }
  }

  /**
   * Replaces discarded (NULL) values with the default translation's values.
   *
   * @param mixed $value
   *   An input value that may be, or contain at any depth, a NULL left behind
   *   by a discarded ∅ sentinel "translation".
   * @param mixed $default
   *   The default translation's value at the same position, or NULL if it has
   *   none.
   *
   * @return mixed
   *   The value with every NULL replaced by the default translation's value
   *   at the same position; nested values that stay NULL are removed. Returns
   *   NULL only if the value itself is NULL and there is no default.
   */
  private static function refillDiscardedFromDefault(mixed $value, mixed $default): mixed {
    if ($value === NULL) {
      return $default;
    }
    if (!\is_array($value)) {
      return $value;
    }
    foreach ($value as $key => $child) {
      $refilled = self::refillDiscardedFromDefault($child, \is_array($default) ? ($default[$key] ?? NULL) : NULL);
      if ($refilled === NULL) {
        unset($value[$key]);
      }
      else {
        $value[$key] = $refilled;
      }
    }
    return $value;
  }

  /**
   * Gets the default translation's inputs for the item at a given delta.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The (translated) component tree field being written to.
   * @param int $delta
   *   The item delta.
   *
   * @return array
   *   The default translation's inputs for that delta; empty if none.
   */
  private static function defaultTranslationInputs(FieldItemListInterface $field, int $delta): array {
    $entity = $field->getEntity();
    if (!$entity instanceof ContentEntityInterface) {
      return [];
    }
    $default_field = $entity->getUntranslated()->get($field->getName());
    if (!$default_field->offsetExists($delta)) {
      return [];
    }
    $default_item = $default_field->offsetGet($delta);
    \assert($default_item instanceof ComponentTreeItem);
    return $default_item->getInputs() ?? [];
  }

  /**
   * Writes TMGMT translations back into a nested component input value.
   *
   * Unlike the SDC/JS path (where each prop maps 1:1 to a field item and
   * parent::setTranslations() can be used directly), component sources not
   * using PropSources can have translatables at arbitrary nesting depth within
   * a single input key — e.g. `deeply_nested_translatable[0][bar]`. There is
   * no field item to delegate to, so translations must be written back into the
   * raw $inputs array at the exact nested position they were extracted from.
   *
   * Recursively walks the TMGMT data tree: at translatable leaves (#translate
   * TRUE), returns NULL if the "translation" is the ∅ sentinel (not a real
   * translation — it is discarded and the key is refilled from the default
   * translation), the translated string otherwise — including the empty
   * string, which is a valid, explicitly empty translation; at intermediate
   * nodes, merges translated children back into the existing input array,
   * preserving non-translated sibling keys.
   *
   * @param array $tmgmt_data
   *   TMGMT data node, e.g. ['#text' => ..., '#translation' => [...], ...].
   * @param mixed $existing
   *   Existing input value to merge translated children into.
   * @param bool $found
   *   Set to TRUE if at least one #translation was applied.
   *
   * @return mixed
   *   The updated value with translations written in at the correct depth.
   *
   * @see \Drupal\canvas\Hook\TmgmtHooks::maskEmptyComponentTreeTranslations()
   * @see \Drupal\canvas\Hook\TmgmtHooks::tmgmtDataItemTextOutputAlter()
   */
  private static function writeNestedTranslationsToInputs(array $tmgmt_data, mixed $existing, bool &$found): mixed {
    if (\array_key_exists('#translate', $tmgmt_data) && $tmgmt_data['#translate'] === TRUE) {
      \assert(\array_key_exists('#translation', $tmgmt_data));
      \assert(\array_key_exists('#text', $tmgmt_data['#translation']));
      $found = TRUE;
      $text = $tmgmt_data['#translation']['#text'];
      // The sentinel is not a translation (a translator plugin echoed the
      // empty-source placeholder back): returning NULL discards it, and
      // setTranslations() refills the key from the default translation. An
      // empty string is a valid, explicitly empty translation, stored as-is.
      // Two consequences of this exact match: a translation that merely
      // CONTAINS the sentinel (e.g. a machine translator returning "fr: ∅")
      // is stored verbatim, and a translator can never store a literal ∅ as
      // an intentional translation.
      if ($text === ComponentInputsTranslatablesExtractor::EMPTY_SENTINEL) {
        return NULL;
      }
      return $text;
    }
    $result = \is_array($existing) ? $existing : [];
    $any_translated = FALSE;
    $all_translated_null = TRUE;
    foreach (Element::children($tmgmt_data) as $key) {
      $child_found = FALSE;
      $result[$key] = self::writeNestedTranslationsToInputs($tmgmt_data[$key], $result[$key] ?? NULL, $child_found);
      if ($child_found) {
        $found = TRUE;
        $any_translated = TRUE;
        if ($result[$key] !== NULL) {
          $all_translated_null = FALSE;
        }
      }
    }
    // If every translatable leaf in this subtree resolved to NULL (the ∅
    // sentinel), return NULL for the whole composite input so it is refilled
    // from the default translation as one consistent value. This handles
    // compound inputs such as text_format (value + format).
    if ($any_translated && $all_translated_null) {
      return NULL;
    }
    return $result;
  }

}
