<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\DataType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;
use Drupal\experience_builder\MissingComponentPropsException;

/**
 * @todo Implement ListInterface because it conceptually fits, but … what does it get us?
 */
#[DataType(
  id: "component_props_values",
  label: new TranslatableMarkup("Component prop values"),
  description: new TranslatableMarkup("The prop values for the components in a component tree: without structure"),
)]
class ComponentPropsValues extends TypedData implements \Stringable {

  /**
   * The data value.
   *
   * @var string
   *
   * @todo Delete this property after https://www.drupal.org/project/drupal/issues/2232427
   */
  protected string $value;

  /**
   * The parsed data value.
   *
   * @var array<string, array<string, array{'sourceType': string, 'value': array<mixed>, 'expression': string}>>
   */
  protected array $propsValues = [];

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    // @todo Uncomment next line and delete last line after https://www.drupal.org/project/drupal/issues/2232427
    // return $this->propsValues;
    // Fall back to NULL if not yet initialized, to allow validation.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    return $this->value ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    // Default to the empty JSON object.
    $this->setValue('{}', $notify);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    assert(str_starts_with($value, '{'));
    // @todo Delete next line; update this code to ONLY do the JSON-to-PHP-object parsing after https://www.drupal.org/project/drupal/issues/2232427 lands — that will allow specifying the "json" serialization strategy rather than only PHP's serialize().
    $this->value = $value;
    $this->propsValues = Json::decode($value);

    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return Json::encode($this->propsValues);
  }

  /**
   * @return string[]
   *   Component instance UUIDs.
   */
  public function getComponentInstanceUuids(): array {
    return array_keys($this->propsValues);
  }

  /**
   * Retrieves the list of unique types of prop sources used.
   *
   * Sibling method on ComponentTreeStructure:
   *
   * @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure::getComponentIdList()
   *
   * @return string[]
   *   A list of all unique PropSourceBase::getSourceTypePrefix() return values
   *   stored in this list of component prop values, for this component tree.
   */
  public function getPropSourceTypePrefixList(): array {
    $source_type_prefixes = [];
    foreach ($this->propsValues as $raw_prop_sources) {
      foreach ($raw_prop_sources as $raw_prop_source) {
        if (!\is_array($raw_prop_source) || !\array_key_exists('sourceType', $raw_prop_source)) {
          // This isn't an SDC component.
          // @todo Move this logic into SDC component source.
          // @see https://www.drupal.org/project/experience_builder/issues/3467954
          continue;
        }
        $source_type_prefixes[] = explode(':', $raw_prop_source['sourceType'])[0];
      }
    }
    return array_unique($source_type_prefixes);
  }

  /**
   * Gets the values for a given component instance.
   *
   * @param string $component_instance_uuid
   *   Component instance UUID.
   *
   * @return array<string, array<string, array|string>>
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\experience_builder\MissingComponentPropsException
   */
  public function getValues(string $component_instance_uuid): array {
    if (!array_key_exists($component_instance_uuid, $this->propsValues)) {
      throw new MissingComponentPropsException($component_instance_uuid);
    }

    return $this->propsValues[$component_instance_uuid];
  }

}
