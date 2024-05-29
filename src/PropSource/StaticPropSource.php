<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

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
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;

/**
 * @todo Finalize name. "Fixed" might be better. "Local" might be even better?
 */
final class StaticPropSource extends PropSource {

  public function __construct(
    private readonly FieldItemInterface $fieldItem,
    private readonly StructuredDataPropExpressionInterface $expression,
  ) {}

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

    $typed_data_manager = \Drupal::service(TypedDataManagerInterface::class);

    // First: conjure the expected FieldItem instance.
    [, $data_type] = explode(':', $sdc_prop_source['sourceType'], 2);
    $field_item_definition = $typed_data_manager->createDataDefinition($data_type);
    assert($field_item_definition instanceof FieldItemDataDefinitionInterface);
    $field_item = $typed_data_manager->createInstance($data_type, [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    assert($field_item instanceof FieldItemInterface);
    $field_item->setValue($sdc_prop_source['value']);

    // @todo Remove this when this logic is moved into a field type and it actually gets saved and has test coverage.
    // @todo This won't work for fields whose props are objects (ComplexData)/lists (ListInterface), but core does not use that AFAIK, so fine for now.
    $expected_to_be_stored = $field_item->toArray();
    match (count($field_item_definition->getPropertyDefinitions())) {
      1 => (function () use ($expected_to_be_stored, $sdc_prop_source, $field_item) {
        if ($expected_to_be_stored[$field_item::mainPropertyName()] !== $sdc_prop_source['value']) {
          throw new \LogicException(sprintf('Unexpected static prop value: %s should be %s', json_encode($sdc_prop_source['value']), json_encode($expected_to_be_stored[$field_item::mainPropertyName()])));
        }
      })(),
      default => (function () use ($expected_to_be_stored, $sdc_prop_source) {
        if ($expected_to_be_stored !== $sdc_prop_source['value']) {
          throw new \LogicException(sprintf('Unexpected static prop value: %s should be %s', json_encode($sdc_prop_source['value']), json_encode($expected_to_be_stored)));
        }
      })(),
    };

    // Second: construct an expression object from the expression string.
    if (str_contains($sdc_prop_source['expression'], '{')) {
      $expression = FieldTypeObjectPropsExpression::fromString($sdc_prop_source['expression']);
    }
    else {
      $expression = FieldTypePropExpression::fromString($sdc_prop_source['expression']);
    }

    return new StaticPropSource($field_item, $expression);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(): mixed {
    return Evaluator::evaluate($this->fieldItem, $this->expression);
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

  public function getWidget(string $sdc_prop_name): WidgetInterface {
    // @phpstan-ignore-next-line
    $field_widget_plugin_manager = \Drupal::service('plugin.manager.field.widget');
    assert($field_widget_plugin_manager instanceof WidgetPluginManager);
    $widget = $field_widget_plugin_manager->getInstance([
      'field_definition' => $this->conjureFieldDefinition($sdc_prop_name),
      'configuration' => [],
      'prepare' => TRUE,
    ]);
    assert($widget !== FALSE);
    return $widget;
  }

  /**
   * @phpstan-ignore-next-line
   */
  public function formTemporaryRemoveThisExclamationExclamationExclamation(string $component_instance_uuid, string $sdc_prop_name, array &$form, FormStateInterface $form_state): array {
    $field_definition = $this->conjureFieldDefinition($sdc_prop_name);
    $field = (new FieldItemList($field_definition, $sdc_prop_name))->set(0, $this->fieldItem);
    return $this->getWidget($sdc_prop_name)->form($field, $form, $form_state);
  }

  /**
   * @param array<int, array<string, mixed>> $values
   * @param array<mixed> $form
   *
   * @return array<string, mixed>
   */
  public function massageFormValuesTemporaryRemoveThisExclamationExclamationExclamation(string $sdc_prop_name, array $values, array &$form, FormStateInterface $form_state): array {
    // 1. Apply the field widget's transformation.
    $massaged_values = $this->getWidget($sdc_prop_name)
      ->massageFormValues($values, $form, $form_state);

    // 2. Keep only the first value — only single cardinality is supported ATM.
    $massaged_values = $massaged_values[0];

    // Work on a clone of the stored field item to avoid side effects.
    $item = clone $this->fieldItem;

    // 2. Apply the field item's transformation.
    // @see \Drupal\link\Plugin\Field\FieldType\LinkItem::setValue()
    $item->setValue($massaged_values);
    $actual_values = $item->getValue();

    // 3. XB only needs to store non-computed values.
    return array_intersect_key($actual_values, $item->getProperties(FALSE));
  }

}
