<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldWidget;

use Drupal\canvas\Plugin\Field\FieldType\CanvasObjectItem;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the 'canvas_object' field widget: one form element per property.
 *
 * Multiple-cardinality fields reuse the multiple value form (add, remove,
 * reorder), which makes this a "repeater" for `type: array` component props.
 *
 * The `properties` setting may contain the raw (not normalized) JSON schema of
 * each property, to label form elements with `title`, `description` and
 * `meta:enum`. It is populated at form-build time and never stored.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildComponentInstanceForm()
 *
 * @internal
 */
#[FieldWidget(
  id: CanvasObjectItem::PLUGIN_ID,
  label: new TranslatableMarkup('Object properties'),
  field_types: [CanvasObjectItem::PLUGIN_ID],
)]
final class CanvasObjectWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return ['properties' => []] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $metadata = $this->getSetting('properties');
    $item = $items->get($delta);
    foreach ($this->getFieldSetting('properties') as $name => $schema) {
      $element[$name] = [
        '#title' => $metadata[$name]['title'] ?? Unicode::ucfirst($name),
        '#description' => $metadata[$name]['description'] ?? NULL,
        '#default_value' => $item?->get($name)->getValue(),
      ] + self::elementForSchema($schema, $metadata[$name]['meta:enum'] ?? []);
    }
    // Group a single object's properties under the field label. (The multiple
    // value form already does that for lists.)
    if (!$this->fieldDefinition->getFieldStorageDefinition()->isMultiple()) {
      $element['#type'] = 'fieldset';
    }
    return $element;
  }

  /**
   * Maps a scalar property's JSON schema to a form element.
   *
   * @param array<string, mixed> $schema
   *   A property schema accepted by
   *   JsonSchemaType::isObjectWithScalarProperties().
   * @param array<string|int, string> $enum_labels
   *   The `meta:enum` labels, if any.
   *
   * @return array<string, mixed>
   *   A form element without title or value.
   */
  private static function elementForSchema(array $schema, array $enum_labels): array {
    if (\array_key_exists('enum', $schema)) {
      return [
        '#type' => 'select',
        '#options' => array_combine($schema['enum'], \array_map(
          static fn (string|int $value) => $enum_labels[$value] ?? (string) $value,
          $schema['enum'],
        )),
        '#empty_value' => '',
      ];
    }
    return match ($schema['type']) {
      'boolean' => ['#type' => 'checkbox'],
      'integer', 'number' => [
        '#type' => 'number',
        '#step' => $schema['type'] === 'integer' ? 1 : 'any',
        '#min' => $schema['minimum'] ?? NULL,
        '#max' => $schema['maximum'] ?? NULL,
      ],
      'string' => match ($schema['format'] ?? NULL) {
        'email', 'idn-email' => ['#type' => 'email'],
        'uri', 'iri' => ['#type' => 'url'],
        'date' => ['#type' => 'date'],
        default => ['#type' => 'textfield', '#maxlength' => $schema['maxLength'] ?? NULL],
      },
      default => throw new \LogicException('Unsupported property type.'),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $properties = $this->getFieldSetting('properties');
    foreach ($values as &$value) {
      foreach ($properties as $name => $schema) {
        $raw = $value[$name] ?? NULL;
        $value[$name] = match (TRUE) {
          $raw === NULL, $raw === '' => NULL,
          $schema['type'] === 'boolean' => (bool) $raw,
          $schema['type'] === 'integer' => (int) $raw,
          $schema['type'] === 'number' => (float) $raw,
          default => $raw,
        };
      }
    }
    return $values;
  }

}
