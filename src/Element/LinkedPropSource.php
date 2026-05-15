<?php

declare(strict_types=1);

namespace Drupal\canvas\Element;

use Drupal\canvas\PropSource\LinkedPropSourceInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;

#[RenderElement('linked_prop_source')]
class LinkedPropSource extends RenderElementBase {

  /**
   * Proves a render element for a linked prop source in a form.
   *
   * In the UI, a prop source implementing LinkedPropSourceInterface that
   * populates a component input is considered "linked".
   *
   * @todo Resolve the naming confusion in https://www.drupal.org/i/3548297
   *
   * Properties:
   * - #sdc_prop_name: The name of the prop in the component.
   * - #sdc_prop_label: The label of the prop in the component.
   * - #linked_prop_source: A LinkedPropSourceInterface object.
   * - #entity_data_definition: The EntityDataDefinitionInterface for the host
   *   entity type and bundle (required for EntityFieldPropSource to generate
   *   hierarchical labels).
   * - #field_link_suggestions: An array of field name suggestions for linking.
   * - #is_required: Whether the prop is required.
   *
   * @see \Drupal\canvas\PropSource\LinkedPropSourceInterface
   */
  public function getInfo() {
    return [
      '#input' => FALSE,
      '#theme_wrappers' => ['container'],
      '#process' => [
        [static::class, 'processLinkedPropSource'],
      ],
      '#sdc_prop_name' => NULL,
      '#linked_prop_source' => NULL,
      '#entity_data_definition' => NULL,
      '#field_link_suggestions' => [],
      '#is_required' => FALSE,
      '#attributes' => [
        'class' => ['canvas-linked-prop-wrapper'],
      ],
    ];
  }

  /**
   * Processes a linked prop source form element.
   */
  public static function processLinkedPropSource(array &$element): array {
    $sdc_prop_name = $element['#sdc_prop_name'];
    \assert(\is_string($sdc_prop_name));
    $sdc_prop_label = $element['#sdc_prop_label'];
    \assert(\is_string($sdc_prop_label));
    $linked_prop_source = $element['#linked_prop_source'];
    \assert($linked_prop_source instanceof LinkedPropSourceInterface);
    $entity_data_definition = $element['#entity_data_definition'];
    $field_link_suggestions = $element['#field_link_suggestions'] ?? [];
    \assert(\is_array($field_link_suggestions));
    $is_required = $element['#is_required'] ?? FALSE;

    \assert($entity_data_definition instanceof EntityDataDefinitionInterface);
    $title = (string) $linked_prop_source->label($entity_data_definition);

    $element['label_wrap'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['canvas-linked-prop-label-wrapper'],
      ],
      'label' => [
        '#type' => 'label',
        '#title' => $sdc_prop_label,
        '#required' => $is_required,
      ],
      'post_label' => [
        // @see ui/src/components/form/components/drupal/PropLinker.tsx, the
        // template that renders `prop_linker`.
        '#theme' => 'prop_linker',
        '#linked' => TRUE,
        '#prop_name' => $sdc_prop_name,
        '#suggestions' => $field_link_suggestions,
      ],
    ];

    $element['badge'] = [
      // @see ui/src/components/form/components/drupal/LinkedFieldBox.tsx,
      // the template that renders `linked_field_box`.
      '#theme' => 'linked_field_box',
      '#title' => $title,
      '#prop_name' => $sdc_prop_name,
      '#description' => $element['#description'],
      '#description_display' => $element['#description_display'],
    ];

    return $element;
  }

}
