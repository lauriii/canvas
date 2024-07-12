<?php

declare(strict_types=1);

// ⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️
// ⚠️ 🔨🧹  This file will be thrown away. Do not review in detail, ever.   🧹🔨 ⚠️
// ⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️

namespace Drupal\experience_builder\Plugin\Field\FieldWidget;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Plugin\Adapter\AdapterInterface;
use Drupal\experience_builder\Plugin\DataType\ComponentPropsValues;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\PropExpressionInterface;
use Drupal\experience_builder\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;
use Drupal\experience_builder\PropSource\AdaptedPropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;

/**
 * @todo Convert to modal dialogs using https://git.drupalcode.org/project/quickedit/-/blob/1.0.x/src/Form/QuickEditFieldForm.php and https://git.drupalcode.org/project/quickedit/-/blob/1.0.x/src/QuickEditController.php as inspiration.
 */
#[FieldWidget(
  id: 'experience_builder_two_terrible_text_areas',
  label: new TranslatableMarkup('Two terrible text areas'),
  field_types: ['component_tree'],
)]
class TwoTerribleTextAreasWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    assert($items->count() === 1);
    assert($delta === 0);
    assert($items[$delta] instanceof ComponentTreeItem);

    $tree = $items[$delta]->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $props = $items[$delta]->get('props');
    assert($props instanceof ComponentPropsValues);

    $element['#theme_wrappers'][] = 'fieldset';
    $element['tree'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Component tree'),
      '#default_value' => json_encode(json_decode((string) $tree), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
      '#placeholder' => 'JSON blob for component tree. Structure: [{"<UUID>":{"type":"<provider:sdc_name>"}, …}] — @todo actually support trees!',
    ];
    $element['props'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Component props values'),
      '#default_value' => json_encode(json_decode((string) $props), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
      '#placeholder' => 'JSON blob for component props values. Structure: {"<UUID>":{"type":"<provider:sdc_name>"}, …}, with the UUID order irrelevant.',
      // This is only intended to visualize the currently stored data.
      '#disabled' => TRUE,
      '#rows' => 20,
    ];

    $element['props_editor'] = [
      '#type' => 'details',
      '#title' => $this->t('Component props editor'),
      '#open' => TRUE,
    ];
    // The tree determines props editors order.
    $prop_source_choices = $items[$delta]->getAvailablePropSourceChoices();
    foreach ($tree->getComponentInstanceUuids() as $uuid) {
      $component_plugin_id = $tree->getComponentId($uuid);

      $stored_props_sources = $props->getComponentPropsSources($uuid);

      $element['props_editor'][$uuid] = [
        '#type' => 'details',
        '#title' => sprintf("%s <code>[%s]</code>", $component_plugin_id, $uuid),
      ];
      foreach ($prop_source_choices[$component_plugin_id] as $cpe => [
        'required' => $required,
        'types' => $static_choices,
        'instances' => $dynamic_choices,
        'adapters' => $adapter_choices,
      ]) {
        $prop_name = ComponentPropExpression::fromString($cpe)->propName;
        // @phpstan-ignore-next-line
        $element['props_editor'][$uuid][$prop_name] = [
          '#type' => 'details',
          '#title' => $prop_name,
          '#open' => TRUE,
        ];
        $element['props_editor'][$uuid][$prop_name]['source'] = [
          '#type' => 'select',
          '#title' => $this->t('Source'),
          '#required' => $required,
          '#options' => [
            // @phpstan-ignore-next-line
            (string) $this->t('Existing data ("dynamic")') => array_combine($dynamic_choices, array_keys($dynamic_choices)),
            // @phpstan-ignore-next-line
            (string) $this->t('Manually specified data ("static")') => array_combine($static_choices, array_keys($static_choices)),
            (string) $this->t('Adapt data ("adapter")') => array_combine(
              array_map(fn (AdapterInterface $a) : string => $a->getPluginId(), $adapter_choices),
              array_keys($adapter_choices)
            ),
          ],
        ];
        if (array_key_exists($prop_name, $stored_props_sources)) {
          $element['props_editor'][$uuid][$prop_name]['source']['#default_value'] = (string) $stored_props_sources[$prop_name]->asChoice();
        }
        if (!$required) {
          $element['props_editor'][$uuid][$prop_name]['source']['#empty_value'] = 'IGNORE';
          $element['props_editor'][$uuid][$prop_name]['source']['#empty_option'] = $this->t('Ignore');
          $element['props_editor'][$uuid][$prop_name]['source']['#description'] = $this->t('The component works <em>without</em> this value.');
        }
        if (empty($dynamic_choices + $static_choices)) {
          $element['props_editor'][$uuid][$prop_name]['source']['#description'] = $this->t('⚠️ This component prop has a shape that has no equivalent in Drupal fields — <em>yet</em>.');
        }
        foreach ($dynamic_choices + $static_choices + $adapter_choices as $choice) {
          $stringified_choice = $choice instanceof AdapterInterface ? $choice->getPluginId() : (string) $choice;
          $element['props_editor'][$uuid][$prop_name]['visualize_choice'][$stringified_choice] = [
            '#type' => 'item',
            '#markup' => $choice instanceof AdapterInterface
              ? sprintf("↬ adapt using ✨ <code>%s</code> ✨", $stringified_choice)
              : sprintf("↪ <code>%s</code>", $stringified_choice),
            '#states' => [
              'visible' => [
                sprintf(':input[name="field_xb_demo[0][props_editor][%s][%s][source]"]', $uuid, $prop_name) => ['value' => $stringified_choice],
              ],
            ],
          ];
        }

        $valid_choices = array_map(fn (PropExpressionInterface $source) => (string) $source, [...$static_choices, ...$dynamic_choices]);
        $valid_choices = [...$valid_choices, ...array_map(fn (AdapterInterface $a) => $a->getPluginId(), $adapter_choices)];
        if (array_key_exists($prop_name, $stored_props_sources) && array_search((string) $stored_props_sources[$prop_name]->asChoice(), $valid_choices) === FALSE) {
          throw new \LogicException('A stored prop was detected that is not one of the offered choices!');
        }

        // Generate a widget for every possible choice in the `source` dropdown.
        // All except the currently selected choice will be hidden. Only the
        // widget for the currently selected choice will have its value saved.
        foreach ($static_choices as $choice) {
          $stored_source = $stored_props_sources[$prop_name];
          $source = ($stored_source instanceof StaticPropSource && (string) $choice == (string) $stored_source->asChoice())
            ? $stored_source
            : StaticPropSource::generate($choice);

          // Ensure a nested form values structure is generated.
          // @todo This is not the correct way; but this is a throwaway PoC! 🗑️
          $form['#parents'] = ['xb_props_editor', $uuid, $prop_name, (string) $choice];

          $element['props_editor'][$uuid][$prop_name][(string) $choice]['value'] = $source->formTemporaryRemoveThisExclamationExclamationExclamation($uuid, $prop_name, $items->getEntity(), $form, $form_state);
          $element['props_editor'][$uuid][$prop_name][(string) $choice]['value']['widget'][0]['#title'] = sprintf("<code>%s</code>", $prop_name);
          $element['props_editor'][$uuid][$prop_name][(string) $choice]['value']['#states'] = [
            'visible' => [
              sprintf(':input[name="field_xb_demo[0][props_editor][%s][%s][source]"]', $uuid, $prop_name) => ['value' => (string) $choice],
            ],
          ];
          $form_state->set("xb_source|$uuid|$prop_name|$choice", $source);

          // Restore the original #parents.
          $form['#parents'] = [];
        }

        // Generate a read-only view of the stored adapter. *Maybe*, if the UX
        // or design folks need to see this clunky version to understand the
        // problem space, this should be expanded to allow picking an adapter
        // input.
        foreach ($adapter_choices as $choice) {
          assert($choice instanceof AdapterInterface);
          $stored_source = $stored_props_sources[$prop_name];
          $source = ($stored_source instanceof AdaptedPropSource && $choice->getPluginId() == (string) $stored_source->asChoice())
            ? $stored_source
            : NULL;

          $element['props_editor'][$uuid][$prop_name][$choice->getPluginId()]['inputs'] = [
            '#type' => 'details',
            '#title' => $this->t('Adapter inputs'),
            '#open' => TRUE,
            '#states' => [
              'visible' => [
                sprintf(':input[name="field_xb_demo[0][props_editor][%s][%s][source]"]', $uuid, $prop_name) => ['value' => $choice->getPluginId()],
              ],
            ],
          ];
          foreach ($choice->getInputs() as $input_name => $input_schema) {
            $input_source = $source?->getInputPropSource($input_name);
            $element['props_editor'][$uuid][$prop_name][$choice->getPluginId()]['inputs'][$input_name] = [
              '#type' => 'item',
              '#title' => $this->t('Adapter input %adapter-input-label', ['%adapter-input-label' => $input_name]),
              '#markup' => $input_source
                ? $input_source->asChoice() . ' ➡ value: <code>' . (
                    $items->getEntity()->isNew()
                    ? 'new entity, save first!'
                    : print_r($input_source->evaluate($items->getEntity()), TRUE)
              ) . '</code>'
                : '👷 NOT YET SUPPORTED 👷',
            ];
          }
        }
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<int, array<string, mixed>> $values
   * @param array<mixed> $form
   *
   * @return array<int, array<string, mixed>>
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $edited_sdc_props = $form_state->getValues()['xb_props_editor'];

    // This is the data structure this method will update.
    // TRICKY: This manual JSON handling is necessary due to https://www.drupal.org/project/drupal/issues/2232427 not having landed yet.
    $props = json_decode($values[0]['props'], TRUE);

    $tree_structure = ComponentTreeStructure::createInstance(DataDefinition::create('component_tree_structure'));
    $tree_structure->setValue($values[0]['tree']);
    foreach ($tree_structure->getComponentInstanceUuids() as $component_instance_uuid) {

      // Not every component instance has static prop sources to edit.
      if (!array_key_exists($component_instance_uuid, $edited_sdc_props)) {
        continue;
      }

      foreach ($edited_sdc_props[$component_instance_uuid] as $edited_sdc_prop_name => $edited_sdc_prop_values) {
        $choice = $values[0]['props_editor'][$component_instance_uuid][$edited_sdc_prop_name]['source'];
        if (!array_key_exists($choice, $edited_sdc_prop_values)) {
          if (!str_starts_with($choice, StructuredDataPropExpressionInterface::PREFIX)) {
            // An adapted source was chosen: modifying this in this terrible
            // widget is currently not supported.
            continue;
          }
          // A dynamic source was chosen: no complex form processing needed: the
          // choice *is* the expression.
          $props[$component_instance_uuid][$edited_sdc_prop_name] = [
            'sourceType' => 'dynamic',
            'expression' => $choice,
          ];
          continue;
        }
        $chosen_source_prop_values = $edited_sdc_prop_values[$choice][$edited_sdc_prop_name];
        $source = $form_state->getStorage()["xb_source|$component_instance_uuid|$edited_sdc_prop_name|$choice"];
        assert($source instanceof StaticPropSource);
        $updated_values = $source->minimizeValue($source->massageFormValuesTemporaryRemoveThisExclamationExclamationExclamation($edited_sdc_prop_name, $chosen_source_prop_values, $form, $form_state));
        // Store the selected source choice: update the sourceType + expression.
        $props[$component_instance_uuid][$edited_sdc_prop_name]['sourceType'] = $source->getSourceType();
        $props[$component_instance_uuid][$edited_sdc_prop_name]['expression'] = (string) $source->asChoice();
        // Store updated field property values for the `static:field_item:…`.
        if (str_starts_with($props[$component_instance_uuid][$edited_sdc_prop_name]['sourceType'], 'static:field_item:')) {
          $props[$component_instance_uuid][$edited_sdc_prop_name]['value'] = $updated_values;
        }
      }
    }

    return [
      0 => [
        // Always single-cardinality.
        '_original_delta' => 0,
        // TRICKY: this does NOT support modifying the tree currently!
        'tree' => $values[0]['tree'],
        // This is now updated!
        'props' => Json::encode($props),
      ],
    ];
  }

}
