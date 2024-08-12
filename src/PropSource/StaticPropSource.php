<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\FieldStorageDefinition;
use Drupal\experience_builder\PropExpressions\StructuredData\Evaluator;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;

/**
 * @todo Finalize name. "Fixed", "Local" and "Stored" all seem better. (Note: "Stored" would match nicely with StorablePropShape.)
 */
final class StaticPropSource extends PropSourceBase {

  public function __construct(
    private readonly FieldItemInterface $fieldItem,
    private readonly StructuredDataPropExpressionInterface $expression,
    // - (optionally) which storage settings to specify for an instance of this
    //   field type — crucial for e.g. the `enum` use case. Note that in theory
    //   is the same as $this->fieldItem->getFieldDefinition()->getSettings(),
    //   but in practice that is unusable: it contains all default settings too.
    private readonly ?array $fieldStorageSettings = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSourceTypePrefix(): string {
    return 'static';
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    $array_representation = [
      'sourceType' => $this->getSourceType(),
      'value' => $this->getValue(),
      'expression' => (string) $this->expression,
    ];
    if ($this->fieldStorageSettings !== NULL) {
      $array_representation['sourceTypeSettings'] = $this->fieldStorageSettings;
    }
    // @phpstan-ignore-next-line
    return json_encode($array_representation, JSON_UNESCAPED_UNICODE);
  }

  private static function conjureFieldItem(FieldTypePropExpression|FieldTypeObjectPropsExpression|ReferenceFieldTypePropExpression $expression, ?array $field_storage_settings): FieldItemInterface {
    $typed_data_manager = \Drupal::service(TypedDataManagerInterface::class);

    // First: conjure the expected FieldItem instance.
    $field_type = $expression instanceof ReferenceFieldTypePropExpression
      ? $expression->referencer->fieldType
      : $expression->fieldType;
    $data_type = "field_item:" . $field_type;

    // TRICKY: this does not work due to it using BaseFieldDefinition, and BaseFieldDefinition::getOptionsProvider() assuming it to exist on the host entity. Hence the use of XB's own \Drupal\experience_builder\FieldStorageDefinition.
    // @see \Drupal\Core\Field\TypedData\FieldItemDataDefinition::createFromDataType()
    // @todo Refactor this after https://www.drupal.org/node/2280639 is fixed.
    // $field_item_definition = $typed_data_manager->createDataDefinition($data_type);
    $field_item_definition = FieldStorageDefinition::create($field_type)->getItemDefinition();
    assert($field_item_definition instanceof DataDefinition);
    if ($field_storage_settings) {
      $field_item_class = $field_item_definition->getClass();
      $field_item_definition->setSettings($field_item_class::storageSettingsFromConfigData($field_storage_settings) +
        $field_item_definition->getSettings());
    }
    assert($field_item_definition instanceof FieldItemDataDefinitionInterface);
    $field_item = $typed_data_manager->createInstance($data_type, [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    assert($field_item instanceof FieldItemInterface);
    return $field_item;
  }

  /**
   * Generates a new (empty) prop source.
   */
  public static function generate(FieldTypePropExpression|FieldTypeObjectPropsExpression|ReferenceFieldTypePropExpression $expression, ?array $field_storage_settings = NULL): static {
    return new StaticPropSource(self::conjureFieldItem($expression, $field_storage_settings), $expression, $field_storage_settings);
  }

  /**
   * @return \Drupal\experience_builder\PropSource\StaticPropSource
   *
   * @see \Drupal\Core\Field\FieldItemList::generateSampleItems()
   *
   * @internal
   *   This is currently only intended to be used by Experience Builder's tests.
   */
  public function randomizeValue(): static {
    $field_item = clone $this->fieldItem;
    $field_type_class = $field_item->getDataDefinition()->getClass();
    $field_item->setValue($field_type_class::generateSampleValue($field_item->getFieldDefinition()));
    if ($field_item instanceof EntityReferenceItem) {
      // TRICKY: the target_id MUST be set for this StaticPropSource
      // serialize and then restore. But Drupal core (sensibly!) does not save
      // sample entities. However, for this
      // @see \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem::onChange()
      // @see \Drupal\Core\Entity\Plugin\DataType\EntityReference::isTargetNew()
      if ($field_item->get('target_id')->getValue() === NULL && $field_item->get('entity')->getValue()->isNew()) {
        $target_id = $field_item->get('entity')->getValue()->save();
        $field_item->get('target_id')->setValue($target_id);
      }
    }
    return new StaticPropSource(
      $field_item,
      $this->expression,
      $this->fieldStorageSettings,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function parse(array $sdc_prop_source): static {
    // `sourceType = static` requires a value and an expression to be specified.
    $missing = array_diff(['value', 'expression'], array_keys($sdc_prop_source));
    if (!empty($missing)) {
      throw new \LogicException(sprintf('Missing the keys %s.', implode(',', $missing)));
    }
    assert(array_key_exists('value', $sdc_prop_source));

    // First: construct an expression object from the expression string.
    $expression = StructuredDataPropExpression::fromString($sdc_prop_source['expression']);
    assert($expression instanceof FieldTypePropExpression || $expression instanceof FieldTypeObjectPropsExpression || $expression instanceof ReferenceFieldTypePropExpression);

    // Second: retrieve the field storage settings, if any.
    $field_storage_settings = NULL;
    if (array_key_exists('sourceTypeSettings', $sdc_prop_source)) {
      $field_storage_settings = $sdc_prop_source['sourceTypeSettings'];
    }

    // Third: conjure the expected FieldItem instance.
    $field_item = self::conjureFieldItem($expression, $field_storage_settings);
    // TRICKY: Setting `[]` is the equivalent of emptying a field. 🤷 (NULL
    // causes *some* field widgets (e.g. image) to fail.)
    // @see \Drupal\Core\Entity\ContentEntityBase::__unset()
    $field_item->setValue($sdc_prop_source['value'] ?? []);

    return new StaticPropSource($field_item, $expression, $field_storage_settings);
  }

  /**
   * Checks that the given raw prop source is a minimal representation.
   *
   * To be used when storing a StaticPropSource.
   *
   * @param array{value: mixed, expression: string, sourceType: string} $sdc_prop_source
   *   A raw static prop source.
   *
   * @return void
   *
   * @throws \LogicException
   *
   * @see \Drupal\experience_builder\PropSource\StaticPropSource::denormalizeValue()
   */
  public static function isMinimalRepresentation(array $sdc_prop_source): void {
    $expression = StructuredDataPropExpression::fromString($sdc_prop_source['expression']);
    assert($expression instanceof FieldTypePropExpression || $expression instanceof FieldTypeObjectPropsExpression || $expression instanceof ReferenceFieldTypePropExpression);
    $field_storage_settings = NULL;
    if (array_key_exists('sourceTypeSettings', $sdc_prop_source)) {
      $field_storage_settings = $sdc_prop_source['sourceTypeSettings'];
    }
    $field_item = self::conjureFieldItem($expression, $field_storage_settings);
    $field_item->setValue($sdc_prop_source['value']);

    // @todo This won't work for fields whose props are objects (ComplexData)/lists (ListInterface), but core does not use that AFAIK, so fine for now.
    $expected_to_be_stored = $field_item->toArray();
    match (count($field_item->getDataDefinition()->getPropertyDefinitions())) {
      1 => (function () use ($expected_to_be_stored, $sdc_prop_source, $field_item) {
        if ($expected_to_be_stored[$field_item::mainPropertyName()] !== $sdc_prop_source['value']) {
          throw new \LogicException(sprintf('Unexpected static prop value: %s should be %s', json_encode($sdc_prop_source['value']), json_encode($expected_to_be_stored[$field_item::mainPropertyName()])));
        }
      })(),
      default => (function () use ($expected_to_be_stored, $sdc_prop_source, $field_item) {
        if ($expected_to_be_stored != $sdc_prop_source['value']) {
          $optional_field_properties = array_filter($field_item->getDataDefinition()->getPropertyDefinitions(), fn ($def) => !$def->isRequired());
          $missing_expected_properties = array_diff_key($expected_to_be_stored, $sdc_prop_source['value']);
          $missing_required_expected_properties = array_diff_key($missing_expected_properties, $optional_field_properties);
          if (!empty($missing_required_expected_properties)) {
            throw new \LogicException(sprintf('Unexpected static prop value: %s should be %s — %s properties are missing', json_encode($sdc_prop_source['value']), json_encode($expected_to_be_stored), implode(', ', $missing_required_expected_properties)));
          }
        }
      })(),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(?FieldableEntityInterface $host_entity): mixed {
    return Evaluator::evaluate($this->fieldItem, $this->expression);
  }

  public function asChoice(): string {
    return (string) $this->expression;
  }

  public function getSourceType(): string {
    return self::getSourceTypePrefix() . self::SOURCE_TYPE_PREFIX_SEPARATOR . $this->fieldItem->getDataDefinition()->getDataType();
  }

  public function getValue(): mixed {
    return $this->denormalizeValue($this->fieldItem->getValue());
  }

  /**
   * Omits the wrapping main property name for single-property field types.
   *
   * This reduces the verbosity of the data stored in `component_tree` fields,
   * which improves both space requirements and the developer experience.
   *
   * @param array<string, mixed> $field_item_value
   *   The value for this static prop source's field item, with field property
   *   names as keys.
   *
   * @return mixed|array<string, mixed>
   *   The denormalized (simplified) value.
   *
   * @see \Drupal\Core\Field\FieldItemBase::setValue()
   *  @see \Drupal\Core\Field\FieldInputValueNormalizerTrait::normalizeValue()
   */
  private function denormalizeValue(array $field_item_value): mixed {
    return match (count($this->fieldItem->getDataDefinition()->getPropertyDefinitions())) {
      1 => $field_item_value[$this->fieldItem::mainPropertyName()] ?? NULL,
      default => $field_item_value,
    };
  }

  public function getWidget(string $sdc_prop_name, string $sdc_prop_label, ?string $field_widget_plugin_id): WidgetInterface {
    // @phpstan-ignore-next-line
    $field_widget_plugin_manager = \Drupal::service('plugin.manager.field.widget');
    assert($field_widget_plugin_manager instanceof WidgetPluginManager);
    $configuration = [];
    if ($field_widget_plugin_id) {
      $configuration['type'] = $field_widget_plugin_id;
    }
    $field_storage_definition = $this->fieldItem->getFieldDefinition();
    assert($field_storage_definition instanceof FieldStorageDefinition);
    $widget = $field_widget_plugin_manager->getInstance([
      'field_definition' => $field_storage_definition
        ->setName($sdc_prop_name)
        ->setLabel($sdc_prop_label),
      'configuration' => $configuration,
      'prepare' => TRUE,
    ]);
    assert($widget !== FALSE);
    return $widget;
  }

  public function formTemporaryRemoveThisExclamationExclamationExclamation(?string $field_widget_plugin_id, string $component_instance_uuid, string $sdc_prop_name, string $sdc_prop_label, ?FieldableEntityInterface $host_entity, array &$form, FormStateInterface $form_state): array {
    // TRICKY: create the field item list without a parent. Otherwise, the Typed
    // Data manager tries to be clever but in doing so fails: it generates a new
    // field item object using the full property path (which then includes the
    // host entity type + bundle), fails to find a field definition, and falls
    // back to a pseudo-random default.
    // @see \Drupal\Core\Field\FieldTypePluginManager::createFieldItem()
    // @see \Drupal\Core\TypedData\TypedDataManagerInterface::getPropertyInstance()
    $list_class = $this->fieldItem->getFieldDefinition()->getClass();
    $field = (new $list_class($this->fieldItem->getFieldDefinition(), $sdc_prop_name, NULL));
    assert($field instanceof FieldItemListInterface);
    $field->set(0, $this->fieldItem);
    // Only *after* the field item list has had its conjured field item set as
    // the sole value, it becomes safe to specify the host entity. Most widgets
    // do not need an entity context, but some do:
    // @see \Drupal\file\Plugin\Field\FieldWidget\FileWidget
    // @see \Drupal\image\Plugin\Field\FieldWidget\ImageWidget
    if ($host_entity) {
      $field->setContext(NULL, EntityAdapter::createFromEntity($host_entity));
    }
    return $this->getWidget($sdc_prop_name, $sdc_prop_label, $field_widget_plugin_id)->form($field, $form, $form_state);
  }

  /**
   * @param array<int, array<string, mixed>> $values
   * @param array<mixed> $form
   *
   * @return mixed|array<string, mixed>
   */
  public function massageFormValuesTemporaryRemoveThisExclamationExclamationExclamation(?string $field_widget_plugin_id, string $sdc_prop_name, string $sdc_prop_label, array $values, array &$form, FormStateInterface $form_state): mixed {
    // 1. Apply the field widget's transformation.
    $massaged_values = $this->getWidget($sdc_prop_name, $sdc_prop_label, $field_widget_plugin_id)
      ->massageFormValues($values, $form, $form_state);

    // 2. Keep only the first value — only single cardinality is supported ATM.
    $massaged_values = $massaged_values[0] ?? [];

    // Work on a clone of the stored field item to avoid side effects.
    $item = clone $this->fieldItem;

    // 2. Apply the field item's transformation.
    // @see \Drupal\link\Plugin\Field\FieldType\LinkItem::setValue()
    $item->setValue($massaged_values);
    $actual_values = $item->getValue();

    // 3. XB only needs to store non-computed values.
    $stored_values = array_intersect_key($actual_values, $item->getProperties(FALSE));

    return $stored_values;
  }

  /**
   * @param array<string, mixed> $field_item_value
   *
   * @return mixed|array<string, mixed>
   */
  public function minimizeValue(array $field_item_value): mixed {
    if (count($this->fieldItem->getDataDefinition()->getPropertyDefinitions()) === 1) {
      return reset($field_item_value);
    }
    return $field_item_value;
  }

}
