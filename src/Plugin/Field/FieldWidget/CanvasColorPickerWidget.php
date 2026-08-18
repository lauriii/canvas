<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the 'canvas_color_picker' field widget.
 *
 * Renders a hidden input that the React front-end transforms into a color
 * picker UI. The mode (kit-only or kit-and-free) is passed via data attribute
 * from the SDC schema annotation.
 */
#[FieldWidget(
  id: 'canvas_color_picker',
  label: new TranslatableMarkup('Canvas color picker'),
  field_types: ['string'],
  multiple_values: FALSE,
)]
final class CanvasColorPickerWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    // Get the mode from widget settings (populated from SDC schema annotation).
    $mode = $this->getSetting('mode') ?? 'kit-only';

    // Get the allowed folder IDs from widget settings.
    $folders = $this->getSetting('folders') ?? [];

    // Get the stored value and convert to a valid hex6 for the color input.
    $item = $items->get($delta);
    $stored_value = $item?->getValue()['value'] ?? '';
    $color_value = self::fallbackToHex6($stored_value);

    $attributes = [
      'data-canvas-color-picker' => $mode,
      'data-canvas-color-value' => $stored_value,
      'data-canvas-color-label' => $this->fieldDefinition->getLabel(),
    ];

    if (!empty($folders)) {
      $attributes['data-canvas-color-folders'] = \json_encode(array_values($folders));
    }

    // Render a color input that the React front-end will detect and transform
    // into the enhanced Canvas color picker UI.
    $element['value'] = [
      '#type' => 'color',
      '#title' => $this->fieldDefinition->getLabel(),
      '#default_value' => $color_value,
      '#attributes' => $attributes,
    ];

    return $element;
  }

  /**
   * Fallback to a valid hex6 color for the type="color" input.
   *
   * - 8-digit hex (#rrggbbaa) → strips alpha, returns #rrggbb
   * - 6-digit hex (#rrggbb) → returns as-is
   * - 3-digit shorthand (#rgb) → expands to #rrggbb
   * - HSL/HSLA strings (hsl(...), hsla(...)) → returns #000000
   * - Brand Kit references (canvas-color:uuid) → returns #000000
   * - Other values → returns #000000
   *
   * @param string $value
   *   The stored value (may be canvas-color:uuid, hex, or HSL).
   *
   * @return string
   *   A hex6 color string.
   */
  private static function fallbackToHex6(string $value): string {
    // Empty value → default to black.
    if ($value === '') {
      return '#000000';
    }

    // Brand Kit reference (canvas-color:uuid) → we can't load it in the form,
    // just show black and let the React UI handle it.
    if (\str_starts_with($value, 'canvas-color:')) {
      return '#000000';
    }

    // It's already a hex color.
    if (\str_starts_with($value, '#')) {
      $clean = \ltrim($value, '#');
      $len = \strlen($clean);

      // 8-digit hex (#rrggbbaa) → return first 6.
      if ($len === 8) {
        return '#' . \substr($clean, 0, 6);
      }

      // 6-digit hex (#rrggbb) → return as-is.
      if ($len === 6) {
        return $value;
      }

      // 3-digit shorthand (#rgb) → expand.
      if ($len === 3) {
        return '#' . \str_repeat($clean[0], 2) . \str_repeat($clean[1], 2) . \str_repeat($clean[2], 2);
      }
    }

    // Fallback to black for unrecognized formats.
    return '#000000';
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'mode' => 'kit-only',
      'folders' => [],
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $element = parent::settingsForm($form, $form_state);

    $element['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Color Source'),
      '#description' => $this->t('Determines which color sources are available.'),
      '#options' => [
        'kit-only' => $this->t('Brand Kit'),
        'kit-and-free' => $this->t('Freeform'),
      ],
      '#default_value' => $this->getSetting('mode'),
      '#required' => TRUE,
    ];

    return $element;
  }

}
