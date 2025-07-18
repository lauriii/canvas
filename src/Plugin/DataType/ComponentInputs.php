<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\DataType;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;
use Drupal\experience_builder\ComponentSource\ComponentSourceInterface;
use Drupal\experience_builder\MissingComponentInputsException;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropSource\ContentAwareDependentInterface;
use Drupal\experience_builder\PropSource\PropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;

/**
 * @phpstan-import-type PropSourceArray from \Drupal\experience_builder\PropSource\PropSourceBase
 * @phpstan-import-type AdaptedPropSourceArray from \Drupal\experience_builder\PropSource\PropSourceBase
 * @phpstan-import-type DefaultRelativeUrlPropSourceArray from \Drupal\experience_builder\PropSource\PropSourceBase
 * @phpstan-type SingleComponentInputArray array<string, PropSourceArray|AdaptedPropSourceArray|DefaultRelativeUrlPropSourceArray>
 */
#[DataType(
  id: "component_inputs",
  label: new TranslatableMarkup("Component inputs"),
  description: new TranslatableMarkup("The input values for the components in a component tree: without structure"),
)]
final class ComponentInputs extends TypedData implements ContentAwareDependentInterface {

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
   * @var SingleComponentInputArray
   */
  protected array $inputs = [];

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $host_entity = NULL): array {
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
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeItemConstraintValidator
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
    if (is_string($value)) {
      // If there are no inputs, an empty array is a valid value.
      assert(str_starts_with($value, '{') || $value === '[]');
      // @todo Delete next line; update this code to ONLY do the JSON-to-PHP-object parsing after https://www.drupal.org/project/drupal/issues/2232427 lands — that will allow specifying the "json" serialization strategy rather than only PHP's serialize().
      $this->value = $value;
      $this->inputs = Json::decode($value);
    }
    else {
      assert(is_array($value));
      $this->inputs = $value;
      $this->value = Json::encode($value);
    }

    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * Retrieves the list of unique types of prop sources used.
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
   * Gets all prop sources that have a particular dependency.
   *
   * @param string $type
   *   The dependency type (e.g., `config`, `module`, `theme`, or `content`).
   * @param string $name
   *   The dependency name (for example, the full name of a config entity).
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $host_entity
   *   (optional) The host entity of this set of inputs, if applicable.
   *
   * @return iterable<string, \Drupal\experience_builder\PropSource\PropSourceBase>
   *   An array of prop sources that have a dependency of the given type and
   *   name. The keys will be strings in the form of "INSTANCE_UUID:PROP_NAME".
   */
  public function getPropSourcesWithDependency(string $type, string $name, ?FieldableEntityInterface $host_entity = NULL): iterable {
    foreach ($this->getPropSources() as $key => $prop_source) {
      $dependencies = $prop_source->calculateDependencies($host_entity);
      if (in_array($name, $dependencies[$type] ?? [], TRUE)) {
        yield $key => $prop_source;
      }
    }
  }

  /**
   * @return \Generator<string, \Drupal\experience_builder\PropSource\PropSourceBase>
   */
  private function getPropSources(): \Generator {
    $item = $this->getParent();
    \assert($item instanceof ComponentTreeItem);
    $source = $item->getComponent()?->getComponentSource();
    $default_prop_sources = $source !== NULL
      ? $source->getDefaultExplicitInput()
      // This component instance is invalid; validation will catch that.
      : [];

    foreach ($this->inputs as $name => $raw_prop_source) {
      if (!\is_array($raw_prop_source) || !\array_key_exists('sourceType', $raw_prop_source)) {
        // This is likely a *collapsed* StaticPropSource.
        // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::collapse()
        // @see \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::uncollapse()
        try {
          $parsed_default_prop_source = array_key_exists($name, $default_prop_sources) && is_array($default_prop_sources[$name])
            ? PropSource::parse($default_prop_sources[$name])
            : NULL;
          // If it indeed was a collapsed StaticPropSource, un-collapse it.
          if ($parsed_default_prop_source instanceof StaticPropSource) {
            // When looking at stored data, we must treat user data that we once
            // accepted (or are in the process of validating) with the utmost
            // respect.
            // @see https://en.wikipedia.org/wiki/Robustness_principle
            yield "name" => $parsed_default_prop_source->withValue($raw_prop_source, allow_empty: TRUE);
          }
        }
        catch (\LogicException) {
        }

        // This isn't a component source using \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase.
        // @todo Move this logic into \Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase.
        // @see https://www.drupal.org/project/experience_builder/issues/3467954
        continue;
      }
      try {
        yield "$name" => PropSource::parse($raw_prop_source);
      }
      catch (\LogicException) {
        // @see https://en.wikipedia.org/wiki/Robustness_principle
        continue;
      }
    }
  }

  /**
   * Gets the values for a given component instance.
   *
   * @return array<string, array<string, array|string>>
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\experience_builder\MissingComponentInputsException
   */
  public function getValues(): array {
    $item = $this->getParent();
    assert($item instanceof ComponentTreeItem);
    $component_instance_uuid = $item->getUuid();
    $source = $item->getComponent()?->getComponentSource();
    \assert($source instanceof ComponentSourceInterface);
    // Still in default value state.
    // @see ::applyDefaultValue()
    if ($this->value === '{}') {
      if ($source->requiresExplicitInput()) {
        throw new MissingComponentInputsException($component_instance_uuid);
      }
      return [];
    }

    return $this->inputs;
  }

}
