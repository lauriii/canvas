<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DataType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;

/**
 * A component instance's style selection: style ID to option ID.
 *
 * Stored as a JSON string, like `inputs`, because the column is `json`.
 * Accepts an array or a JSON string; an empty selection is stored as NULL.
 *
 * @see \Drupal\canvas\Style\ComponentStyles
 * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem
 */
#[DataType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Component styles'),
  description: new TranslatableMarkup('A map of core Style API style ID to option ID'),
  constraints: ['ValidComponentStyles' => []],
)]
final class ComponentStylesData extends TypedData {

  public const string PLUGIN_ID = 'component_styles';

  protected ?string $value = NULL;

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    if (\is_array($value)) {
      if (!array_is_list($value)) {
        ksort($value);
      }
      $value = $value === [] ? NULL : Json::encode($value);
    }
    elseif (\in_array($value, ['', '[]', '{}'], TRUE)) {
      $value = NULL;
    }
    $this->value = $value;
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(): ?string {
    return $this->value;
  }

  /**
   * The decoded value, which may have any shape until validated.
   */
  public function getDecoded(): mixed {
    return $this->value === NULL ? [] : Json::decode($this->value);
  }

  /**
   * The selection, or an empty array if it is not a map.
   *
   * @return array<string, mixed>
   */
  public function getStyles(): array {
    $decoded = $this->getDecoded();
    return \is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
  }

}
