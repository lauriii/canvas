<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldWidget;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Plugin\DataType\ComponentPropsValues;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropSource\PropSource;
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
      '#default_value' => (string) $tree,
      '#placeholder' => 'JSON blob for component tree. Structure: [{"<UUID>":{"type":"<provider:sdc_name>"}, …}] — @todo actually support trees!',
    ];
    $element['props'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Component props values'),
      '#default_value' => (string) $props,
      '#placeholder' => 'JSON blob for component props values. Structure: {"<UUID>":{"type":"<provider:sdc_name>"}, …}, with the UUID order irrelevant.',
      // This is only intended to visualize the currently stored data.
      '#disabled' => TRUE,
    ];

    $element['props_editor'] = [
      '#type' => 'details',
      '#title' => $this->t('Component props editor'),
      '#open' => TRUE,
    ];
    // The tree determines props editors order.
    foreach ($tree->getComponentInstanceUuids() as $uuid) {
      $static_props = array_filter(
        $props->getComponentPropsSources($uuid),
        fn (PropSource $s) => $s instanceof StaticPropSource,
      );

      // @todo For dynamic props, allow picking from a list of available expressions.
      if (empty($static_props)) {
        continue;
      }

      $element['props_editor'][$uuid] = [
        '#type' => 'details',
        '#title' => sprintf("%s <code>[%s]</code>", $tree->getComponentId($uuid), $uuid),
        '#open' => TRUE,
      ];
      foreach ($static_props as $prop_name => $source) {
        // Ensure a nested form values structure is generated.
        // @todo This is not the correct way; but this is a throwaway PoC! 🗑️
        $form['#parents'] = ['xb_props_editor', $uuid];

        // @phpstan-ignore-next-line
        $element['props_editor'][$uuid][$prop_name] = $source->formTemporaryRemoveThisExclamationExclamationExclamation($uuid, $prop_name, $form, $form_state);
        $element['props_editor'][$uuid][$prop_name]['widget'][0]['#title'] = sprintf("<code>%s</code>", $prop_name);
        $form_state->set("xb_source|$uuid|$prop_name", $source);
      }
    }
    // Restore the original #parents.
    $form['#parents'] = [];
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

    foreach (json_decode($values[0]['tree'], TRUE) as $component_instance) {
      $component_instance_uuid = $component_instance['uuid'];

      // Not every component instance has static prop sources to edit.
      if (!array_key_exists($component_instance_uuid, $edited_sdc_props)) {
        continue;
      }

      foreach ($edited_sdc_props[$component_instance_uuid] as $edited_sdc_prop_name => $edited_sdc_prop_values) {
        $source = $form_state->getStorage()["xb_source|$component_instance_uuid|$edited_sdc_prop_name"];
        assert($source instanceof StaticPropSource);
        $updated_values = $source->massageFormValuesTemporaryRemoveThisExclamationExclamationExclamation($edited_sdc_prop_name, $edited_sdc_prop_values, $form, $form_state);
        // Store updated field property values for the `static:field_item:…`.
        assert(str_starts_with($props[$component_instance_uuid][$edited_sdc_prop_name]['sourceType'], 'static:field_item:'));
        $props[$component_instance_uuid][$edited_sdc_prop_name]['value'] = $updated_values;
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
