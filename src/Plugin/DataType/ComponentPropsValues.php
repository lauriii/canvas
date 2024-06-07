<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\DataType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;
use Drupal\experience_builder\PropSource\AdaptedPropSource;
use Drupal\experience_builder\PropSource\DynamicPropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;

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
   * @var array<string, array<string, array{'sourceType': string, 'value'?: array<mixed>, 'expression': string}>>
   */
  protected array $propsValues = [];

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    // @todo Uncomment next line and delete last line after https://www.drupal.org/project/drupal/issues/2232427
    // return $this->propsValues;
    return $this->value;
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
  public function setValue($value, $notify = TRUE) {
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
   * @param string $component_instance_uuid
   *   The UUID of a placed component instance.
   *
   * @return array<string, \Drupal\experience_builder\PropSource\PropSource>
   */
  public function getComponentPropsSources(string $component_instance_uuid): array {
    if (!array_key_exists($component_instance_uuid, $this->propsValues)) {
      throw new \OutOfRangeException(sprintf('No props sources stored for %s. Caused by either incorrect logic or `props` being out of sync with `tree`.', $component_instance_uuid));
    }

    return array_map(
      fn (array $sdc_prop_source) => match (TRUE) {
        $sdc_prop_source['sourceType'] === 'dynamic' => DynamicPropSource::parse($sdc_prop_source),
        str_starts_with($sdc_prop_source['sourceType'], 'static:') => StaticPropSource::parse($sdc_prop_source),
        str_starts_with($sdc_prop_source['sourceType'], 'adapter:') => AdaptedPropSource::parse($sdc_prop_source),
        default => throw new \OutOfRangeException(),
      },
      $this->propsValues[$component_instance_uuid]
    );
  }

}
