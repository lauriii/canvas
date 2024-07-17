<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\PropExpressions\StructuredData\Evaluator;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;

/**
 * @todo Finalize name. "Fixed" might be better. "Local" might be even better?
 */
final class StaticPropSource extends PropSourceBase {

  public function __construct(
    private readonly FieldItemInterface $fieldItem,
    private readonly StructuredDataPropExpressionInterface $expression,
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
    // @phpstan-ignore-next-line
    return json_encode([
      'sourceType' => $this->getSourceType(),
      'value' => $this->getValue(),
      'expression' => (string) $this->expression,
    ], JSON_UNESCAPED_UNICODE);
  }

  private static function conjureFieldItem(FieldTypePropExpression|FieldTypeObjectPropsExpression|ReferenceFieldTypePropExpression $expression): FieldItemInterface {
    $typed_data_manager = \Drupal::service(TypedDataManagerInterface::class);

    // First: conjure the expected FieldItem instance.
    $field_type = $expression instanceof ReferenceFieldTypePropExpression
      ? $expression->referencer->fieldType
      : $expression->fieldType;
    $data_type = "field_item:" . $field_type;
    $field_item_definition = $typed_data_manager->createDataDefinition($data_type);
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
  public static function generate(FieldTypePropExpression|FieldTypeObjectPropsExpression|ReferenceFieldTypePropExpression $expression): static {
    return new StaticPropSource(self::conjureFieldItem($expression), $expression);
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

    // Second: conjure the expected FieldItem instance.
    $field_item = self::conjureFieldItem($expression);
    // TRICKY: Setting `[]` is the equivalent of emptying a field. 🤷 (NULL
    // causes *some* field widgets (e.g. image) to fail.)
    // @see \Drupal\Core\Entity\ContentEntityBase::__unset()
    $field_item->setValue($sdc_prop_source['value'] ?? []);

    return new StaticPropSource($field_item, $expression);
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
    $field_item = self::conjureFieldItem($expression);
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
      1 => $field_item_value[$this->fieldItem::mainPropertyName()],
      default => $field_item_value,
    };
  }

  private function conjureFieldDefinition(string $sdc_prop_name): FieldDefinitionInterface {
    // @phpstan-ignore-next-line
    $typed_data_manager = \Drupal::service(TypedDataManagerInterface::class);
    assert($typed_data_manager instanceof TypedDataManagerInterface);

    // Field widgets require field item lists.
    $data_type = $this->fieldItem->getDataDefinition()->getDataType();
    $field_item_list_definition = $typed_data_manager->createListDataDefinition($data_type);
    // @todo This is not quite a BaseFieldDefinition. Create an alternative FieldDefinitionInterface?
    // @see review at https://git.drupalcode.org/project/experience_builder/-/merge_requests/20#note_317509
    assert($field_item_list_definition instanceof BaseFieldDefinition);
    $field_item_list_definition->setName($sdc_prop_name);

    return $field_item_list_definition;
  }

  public function getWidget(string $sdc_prop_name, ?string $field_widget_plugin_id = NULL): WidgetInterface {
    // @phpstan-ignore-next-line
    $field_widget_plugin_manager = \Drupal::service('plugin.manager.field.widget');
    assert($field_widget_plugin_manager instanceof WidgetPluginManager);
    $configuration = [];
    if ($field_widget_plugin_id) {
      $configuration['type'] = $field_widget_plugin_id;
    }
    $widget = $field_widget_plugin_manager->getInstance([
      'field_definition' => $this->conjureFieldDefinition($sdc_prop_name),
      'configuration' => $configuration,
      'prepare' => TRUE,
    ]);
    assert($widget !== FALSE);
    return $widget;
  }

  public function formTemporaryRemoveThisExclamationExclamationExclamation(string $component_instance_uuid, string $sdc_prop_name, ?FieldableEntityInterface $host_entity, array &$form, FormStateInterface $form_state): array {
    $field_definition = $this->conjureFieldDefinition($sdc_prop_name);
    $field = (new FieldItemList($field_definition, $sdc_prop_name, $host_entity === NULL ? NULL : EntityAdapter::createFromEntity($host_entity)))->set(0, $this->fieldItem);
    return $this->getWidget($sdc_prop_name)->form($field, $form, $form_state);
  }

  /**
   * @param array<int, array<string, mixed>> $values
   * @param array<mixed> $form
   *
   * @return mixed|array<string, mixed>
   */
  public function massageFormValuesTemporaryRemoveThisExclamationExclamationExclamation(string $sdc_prop_name, array $values, array &$form, FormStateInterface $form_state): mixed {
    // 1. Apply the field widget's transformation.
    $massaged_values = $this->getWidget($sdc_prop_name)
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
