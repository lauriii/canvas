<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\DataType;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;
use Drupal\experience_builder\MissingComponentInputsException;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropSource\PropSource;

/**
 * @todo Implement ListInterface because it conceptually fits, but … what does it get us?
 */
#[DataType(
  id: "component_inputs",
  label: new TranslatableMarkup("Component inputs"),
  description: new TranslatableMarkup("The input values for the components in a component tree: without structure"),
)]
class ComponentInputs extends TypedData implements \Stringable, DependentPluginInterface {

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
  protected array $inputs = [];

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(?FieldableEntityInterface $host_entity = NULL) : array {
    $dependencies = [];
    foreach ($this->getPropSources() as $prop_source) {
      $dependencies = NestedArray::mergeDeep($dependencies, $prop_source->calculateDependencies($host_entity));
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue() {
    // @todo Uncomment next line and delete last line after https://www.drupal.org/project/drupal/issues/2232427
    // return $this->inputs;
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
    $this->inputs = Json::decode($value);

    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return Json::encode($this->inputs);
  }

  /**
   * @return string[]
   *   Component instance UUIDs.
   */
  public function getComponentInstanceUuids(): array {
    return array_keys($this->inputs);
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
   *   stored in this list of component input values, for this component tree.
   */
  public function getPropSourceTypePrefixList(): array {
    $source_type_prefixes = [];
    foreach ($this->getPropSources() as $propSource) {
      $source_type_prefixes[] = explode(':', $propSource->getSourceType())[0];
    }
    return array_unique($source_type_prefixes);
  }

  /**
   * @return \Generator<\Drupal\experience_builder\PropSource\PropSourceBase>
   */
  private function getPropSources(): \Generator {
    foreach ($this->inputs as $raw_prop_sources) {
      foreach ($raw_prop_sources as $raw_prop_source) {
        if (!\is_array($raw_prop_source) || !\array_key_exists('sourceType', $raw_prop_source)) {
          // This isn't a component source using \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase.
          // @todo Move this logic into \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase.
          // @see https://www.drupal.org/project/experience_builder/issues/3467954
          continue;
        }
        try {
          yield PropSource::parse($raw_prop_source);
        }
        catch (\LogicException) {
          // @see https://en.wikipedia.org/wiki/Robustness_principle
          continue;
        }
      }
    }
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
   * @throws \Drupal\experience_builder\MissingComponentInputsException
   */
  public function getValues(string $component_instance_uuid): array {
    $item = $this->parent;
    assert($item instanceof ComponentTreeItem);
    $tree = $item->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $source = $tree->getComponentSource($component_instance_uuid);
    \assert($source instanceof ComponentSourceInterface);
    if (!array_key_exists($component_instance_uuid, $this->inputs)) {
      if ($source->requiresExplicitInput()) {
        throw new MissingComponentInputsException($component_instance_uuid);
      }
      return [];
    }

    return $this->inputs[$component_instance_uuid];
  }

}
