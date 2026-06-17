<?php

declare(strict_types=1);

namespace Drupal\canvas\Tmgmt;

use Drupal\canvas\Config\Schema\ComponentInputsMapping;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
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
        // Write only non-NULL (preserve FALSE, 0, '', []).
        $item->setInput(\array_filter($inputs_in_schema_order, static fn($v) => $v !== NULL));
      }
    }
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
   * TRUE), returns the #translation['#text'] value; at intermediate nodes,
   * merges translated children back into the existing input array, preserving
   * non-translated sibling keys.
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
   */
  private static function writeNestedTranslationsToInputs(array $tmgmt_data, mixed $existing, bool &$found): mixed {
    if (\array_key_exists('#translate', $tmgmt_data) && $tmgmt_data['#translate'] === TRUE) {
      \assert(\array_key_exists('#translation', $tmgmt_data));
      \assert(\array_key_exists('#text', $tmgmt_data['#translation']));
      $found = TRUE;
      return $tmgmt_data['#translation']['#text'];
    }
    $result = \is_array($existing) ? $existing : [];
    foreach (Element::children($tmgmt_data) as $key) {
      $result[$key] = self::writeNestedTranslationsToInputs($tmgmt_data[$key], $result[$key] ?? NULL, $found);
    }
    return $result;
  }

}
