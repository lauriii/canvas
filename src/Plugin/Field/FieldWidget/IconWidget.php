<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldWidget;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Icon\IconPropShape;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\PropShape\PropShape;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\StringTextfieldWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Widget for `icon` component props: a visual icon picker.
 *
 * Renders as a plain textfield holding the full icon id (`pack_id:icon_id`).
 * In the Canvas editor, the semi-coupled theme engine maps this widget's
 * markup to the React icon picker, which reads the allowed icon packs from
 * the `data-canvas-icon-packs` attribute set here. Outside the Canvas editor
 * it degrades gracefully to a text input.
 *
 * @see themes/canvas_stark/templates/form/input--textfield--inwidget-canvas-icon.html.twig
 * @see ui/src/components/form/components/drupal/DrupalIconPicker.tsx
 *
 * @internal
 */
#[FieldWidget(
  id: 'canvas_icon',
  label: new TranslatableMarkup('Icon picker'),
  field_types: ['canvas_icon'],
)]
final class IconWidget extends StringTextfieldWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $allowed_packs = $this->getAllowedPackIds();
    if ($allowed_packs !== NULL) {
      $element['value']['#attributes']['data-canvas-icon-packs'] = \implode(' ', $allowed_packs);
    }
    return $element;
  }

  /**
   * Determines the icon packs this prop is scoped to.
   *
   * The widget is instantiated by StaticPropSource with display options that
   * identify the component and prop, so the prop's schema (and with it the
   * generated scope pattern) can be looked up.
   *
   * @see \Drupal\canvas\PropSource\StaticPropSource::getWidget()
   * @see canvas_load_allowed_values_for_component_prop()
   *
   * @return list<string>|null
   *   The allowed pack ids, or NULL when all installed packs are allowed.
   */
  private function getAllowedPackIds(): ?array {
    $canvas_form_display_options = $this->fieldDefinition->getDisplayOptions('form')['third_party_settings']['canvas'] ?? [];
    $component_id = $canvas_form_display_options['component_id'] ?? NULL;
    $prop_name = $canvas_form_display_options['explicit_input_prop_name'] ?? NULL;
    if (!\is_string($component_id) || !\is_string($prop_name)) {
      return NULL;
    }
    $component = Component::load($component_id);
    if ($component === NULL) {
      return NULL;
    }
    $component_version = $canvas_form_display_options['component_version'] ?? NULL;
    if (\is_string($component_version)) {
      $component->loadVersion($component_version);
    }
    $source = $component->getComponentSource();
    if (!$source instanceof JsonSchemaPropsComponentSourceBase) {
      return NULL;
    }
    $shapes = $source->getExplicitInputDefinitions()['shapes'];
    if (!\array_key_exists($prop_name, $shapes)) {
      return NULL;
    }
    return IconPropShape::getAllowedPackIds((new PropShape($shapes[$prop_name]))->resolvedSchema);
  }

}
