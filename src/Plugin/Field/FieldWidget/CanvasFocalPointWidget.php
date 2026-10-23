<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the 'canvas_focal_point' field widget.
 *
 * Renders a fieldset with an `x` and a `y` number input. The Canvas Stark
 * theme turns it into a picker that shows the component's image with a
 * draggable marker, and keeps the number inputs for keyboard use.
 *
 * @see themes/canvas_stark/templates/fieldset--widget-canvas-focal-point.html.twig
 * @see ui/src/components/form/components/drupal/DrupalFocalPoint.tsx
 */
#[FieldWidget(
  id: 'canvas_focal_point',
  label: new TranslatableMarkup('Canvas focal point'),
  field_types: ['canvas_focal_point'],
  multiple_values: FALSE,
)]
final class CanvasFocalPointWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      // The name of the component prop whose image the picker shows. Passed by
      // the component source rather than configured.
      // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildComponentInstanceForm()
      'image_prop' => NULL,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $item = $items->get($delta);
    $element['#type'] = 'fieldset';
    $element['#attributes']['data-canvas-focal-point-image'] = $this->getSetting('image_prop') ?? '';
    foreach (['x' => $this->t('X (%)'), 'y' => $this->t('Y (%)')] as $axis => $title) {
      $element[$axis] = [
        '#type' => 'number',
        '#title' => $title,
        '#default_value' => $item?->get($axis)->getValue(),
        '#min' => 0,
        '#max' => 100,
        '#step' => 'any',
        '#required' => $element['#required'] ?? FALSE,
      ];
    }
    return $element;
  }

}
