<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldType;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Entity\ComponentTreeEntityInterface;
use Drupal\experience_builder\Plugin\DataType\ComponentInputs;
use Drupal\experience_builder\PropSource\ContentAwareDependentInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Plugin implementation of the 'component_tree' field type.
 *
 * @todo Implement PreconfiguredFieldUiOptionsInterface?
 * @todo How to achieve https://www.previousnext.com.au/blog/pitchburgh-diaries-decoupled-layout-builder-sprint-1-2?
 * @see https://git.drupalcode.org/project/metatag/-/blob/2.0.x/src/Plugin/Field/FieldType/MetatagFieldItem.php
 *
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 * @phpstan-type ComponentTreeItemPropName 'tree'|'inputs'|'hydrated'
 *
 * @property \Drupal\experience_builder\HydratedTree $hydrated
 */
#[FieldType(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Experience Builder"),
  description: new TranslatableMarkup("Field to use Experience Builder for presenting these entities"),
  default_formatter: "experience_builder_naive_render_sdc_tree",
  // list_class: ComponentItemList::class,
  constraints: [
    'ValidComponentTree' => [],
    'ComponentTreeMeetRequirements' => [
      // Only StaticPropSources may be used, because using DynamicPropSources is
      // a decision that should be made at the Content Type Template level by a
      // Site Builder, not by each Content Creator.
      // @see https://www.drupal.org/project/experience_builder/issues/3455629
      'inputs' => [
        'absence' => [
          'dynamic',
          'adapter',
        ],
        'presence' => NULL,
      ],
      'tree' => [
        'absence' => [
          // Components implementing either of these 2 interfaces are only
          // allowed to live at the PageRegion level.
          // @see \Drupal\experience_builder\Entity\PageRegion
          // @see `type: experience_builder.page_region.*`
          TitleBlockPluginInterface::class,
          MessagesBlockPluginInterface::class,
        ],
        'presence' => NULL,
      ],
    ],
  ],
  // @see docs/data-model.md
  // @see content_translation_field_info_alter()
  column_groups: [
    'inputs' => [
      'label' => new TranslatableMarkup('Component input values'),
      'translatable' => TRUE,
    ],
    'tree' => [
      'label' => new TranslatableMarkup('Component tree'),
      'translatable' => TRUE,
    ],
  ],
  cardinality: 1,
  // @todo Revisit this prior to 1.0.
  // @see https://www.drupal.org/project/experience_builder/issues/3497926
  no_ui: TRUE,
)]
class ComponentTreeItem extends FieldItemBase implements RenderableInterface {

  public const string PLUGIN_ID = 'component_tree';

  use ComponentTreeItemInstantiatorTrait;

  // phpcs:disable Drupal.Commenting.DataTypeNamespace.DataTypeNamespace
  /**
   * {@inheritdoc}
   *
   * @param ComponentTreeItemPropName $name
   *
   * @return ($name is 'tree' ? \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure : ($name is 'inputs' ? \Drupal\experience_builder\Plugin\DataType\ComponentInputs : \Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated))
   */
  // phpcs:enable Drupal.Commenting.DataTypeNamespace.DataTypeNamespace
  public function get($name) {
    // @phpstan-ignore-next-line
    return parent::get($name);
  }

  /**
   * Calculates all dependencies of the field item (all field props).
   *
   * @return array ($with_plugin_dependencies ? array{'config': string[], 'content': string[], 'module': string[], 'theme': string[], 'plugin': string[]} : array{'config': string[], 'content': string[], 'module': string[], 'theme': string[]})
   *
   * @see \Drupal\Component\Plugin\DependentPluginInterface
   * @see \Drupal\experience_builder\Audit\ComponentTreeDependencyRepository
   */
  public function calculateFieldItemValueDependencies(bool $with_plugin_dependencies, ?FieldableEntityInterface $host_entity = NULL): array {
    // Every field property that has dependencies on config or extensions must
    // implement DependentPluginInterface to ensure accurate dependency (i.e.
    // usage) tracking.
    $dependencies = [];
    foreach ($this->getProperties() as $property) {
      if ($property instanceof DependentPluginInterface) {
        $dependencies = NestedArray::mergeDeep($dependencies, $property->calculateDependencies());
      }
      elseif ($property instanceof ContentAwareDependentInterface) {
        $dependencies = NestedArray::mergeDeep($dependencies, $property->calculateDependencies($host_entity));
      }
    }

    $dependency_types = ['config', 'content', 'module', 'theme'];
    // Config entities do not support plugin dependencies. (Plugin dependency
    // information is only necessary for component trees in content entities.
    // Because:
    // - Component config entities always exist in a single state
    // - content entity revisions contain component instances created based on
    //   the state at the time of creating the revision
    // This means that the Component config entity would prevent the
    // uninstallation of a module that provides a field type that is CURRENTLY
    // used for creating component instances, but that doesn't mean it
    // sufficiently protects HISTORICALLY created component instances!
    // @todo Consider removing this in https://www.drupal.org/i/3477428.
    if ($with_plugin_dependencies) {
      $dependency_types[] = 'plugin';
    }
    else {
      unset($dependencies['plugin']);
    }

    // Normalize.
    ksort($dependencies);
    $normalized_dependencies = [];
    foreach ($dependency_types as $type) {
      $deps_for_type = array_unique($dependencies[$type] ?? []);
      if ($type === 'module') {
        $deps_for_type = array_diff($deps_for_type, [
          // `core` is always present.
          'core',
          // This very field type is provided by Experience Builder, so
          // obviously this module is also always present.
          'experience_builder',
        ]);
      }
      sort($deps_for_type);
      $normalized_dependencies[$type] = $deps_for_type;
    }
    return $normalized_dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public static function calculateDependencies(FieldDefinitionInterface $field_definition) {
    $dependencies = parent::calculateDependencies($field_definition);

    if (empty($field_definition->getDefaultValueLiteral())) {
      return $dependencies;
    }

    $default_value = $field_definition->getDefaultValueLiteral()[0];
    // The default should always have a "tree" key but this runs before validation.
    if (!isset($default_value['tree'])) {
      return [];
    }
    $component_tree_field_item = self::staticallyCreateDanglingComponentTree(\Drupal::typedDataManager());
    $component_tree_field_item->setValue($default_value);
    return NestedArray::mergeDeep(
      $dependencies,
      // `type: config_dependencies_base` config schema doesn't allow `plugin`.
      $component_tree_field_item->calculateFieldItemValueDependencies(FALSE, NULL),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'tree' => [
          'description' => 'The component tree structure.',
          'type' => 'json',
          'pgsql_type' => 'jsonb',
          'mysql_type' => 'json',
          // @todo Change back to 'json' once https://www.drupal.org/i/3487533 is resolved.
          'sqlite_type' => 'text',
          'not null' => FALSE,
        ],
        'inputs' => [
          'description' => 'The inputs for each component in the component tree.',
          'type' => 'json',
          'pgsql_type' => 'jsonb',
          'mysql_type' => 'json',
          // @todo Change back to 'json' once https://www.drupal.org/i/3487533 is resolved.
          'sqlite_type' => 'text',
          'not null' => FALSE,
        ],
      ],
      'indexes' => [],
      'foreign keys' => [
        // @todo Add the "hash" part the proposal at https://www.drupal.org/project/drupal/issues/3440578
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['tree'] = DataDefinition::create('component_tree_structure')
      ->setLabel(new TranslatableMarkup('A component tree without input values.'))
      ->setRequired(TRUE);

    $properties['inputs'] = DataDefinition::create('component_inputs')
      ->setLabel(new TranslatableMarkup('Input values for each component in the component tree'))
      ->setRequired(TRUE);

    $properties['hydrated'] = DataDefinition::create('component_tree_hydrated')
      ->setLabel(new TranslatableMarkup('The hydrated component tree: structure + inputs combined — provides render tree for client side or render array for server side.'))
      ->setComputed(TRUE)
      ->setInternal(FALSE)
      ->setReadOnly(TRUE)
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    // If either `tree` or `inputs` is not set, consider this not empty, because
    // it is not empty in a *valid* way. If considered empty, the
    // NotNullConstraintValidator would apply some magic that prevents detailed
    // validation.
    // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\NotNullConstraintValidator::validate()
    if ($this->get('tree')->getValue() === NULL || $this->get('inputs')->getValue() === NULL) {
      return FALSE;
    }

    $tree = $this->get('tree')->getValue();
    return $tree === '' || $tree === Json::encode([]);
  }

  /**
   * {@inheritdoc}
   */
  public function toArray() {
    // Return the raw property values, avoid the magic of parent Map::toArray().
    // This is necessary to allow validating a component tree in detail.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator::validate()
    return $this->values;
  }

  /**
   * {@inheritdoc}
   */
  public function onChange(mixed $property_name, $notify = TRUE): void {
    if ($property_name === 'tree' || $property_name === 'inputs') {
      $this->values[$property_name] = $this->get($property_name)->getValue();
    }
    parent::onChange($property_name, $notify);
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE): void {
    // This field type does not want either:
    // - the parent FieldItemBase::setValue()'s behavior, which assigns $values
    //   to the first property if $values is not an array.
    // - the grandparent Map::setValue() removes key-value pairs from
    //   $this->values that are assigned to a n on-computed property.
    // Both of those behaviors prevent strict validation. Instead, perform *no*
    // magic transformations, just respect the `tree` and `inputs` key-value
    // pairs, if they are provided.
    if (is_array($values)) {
      // Store the exact values passed in to be assigned to the contained
      // properties.
      $this->values = $values;
      // Assign the values to the contained properties.
      if (array_key_exists('tree', $values)) {
        $this->set('tree', $values['tree'], FALSE);
      }
      if (array_key_exists('inputs', $values)) {
        $this->set('inputs', $values['inputs'], FALSE);
      }
    }

    // If they are missing, fall back to the default value of the non-computed
    // properties `tree` and `inputs`. This avoids a *repeated* validation error:
    // if there already is a validation error for a missing key, another
    // validation error for an invalid value is not helpful.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    foreach ($this->getProperties(FALSE) as $property_name => $property) {
      if (!is_array($values) || !array_key_exists($property_name, $values)) {
        $property->applyDefaultValue(FALSE);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(): void {
    $tree = $this->get('tree');
    $inputs = $this->get('inputs');
    assert($inputs instanceof ComponentInputs);

    $component_instance_uuids = $tree->getComponentInstanceUuids();
    $entity = $this->getRoot() === $this ? NULL : $this->getEntity();
    $violations = new ConstraintViolationList();
    foreach ($component_instance_uuids as $component_instance_uuid) {
      $component_id = $tree->getComponentId($component_instance_uuid);
      $source = $tree->getComponentSource($component_instance_uuid);
      if ($source === NULL) {
        $violations->add(new ConstraintViolation(
          \sprintf('Unable to load component plugin with ID "%s".', $component_id),
          NULL,
          [],
          NULL,
          "inputs.$component_instance_uuid",
          NULL,
        ));
        continue;
      }
      // Ensure that only ever valid inputs for component instances in an XB
      // field are saved. When a field is saved that somehow was not validated,
      // this will catch that.
      // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
      $component_violations = $source->validateComponentInput($inputs->getValues($component_instance_uuid), $component_instance_uuid, $entity);
      if ($component_violations->count() > 0) {
        // @todo Remove the foreach and use ::addAll once
        // https://www.drupal.org/project/drupal/issues/3490588 has been resolved.
        foreach ($component_violations as $violation) {
          $violations->add($violation);
        }
      }
    }
    if ($violations->count() > 0) {
      throw new \LogicException(
        \implode("\n", \array_map(
            static fn(ConstraintViolationInterface $violation) => \sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()),
            \iterator_to_array($violations)
          )
        )
      );
    }

    // This *internal-only* validation does not need to happen using validation
    // constraints because it does not validate user input: it only helps ensure
    // that the logic of this field type is correct.
    $input_required_uuids = array_filter($tree->getComponentInstanceUuids(), static fn(string $uuid) => $tree->getComponentSource($uuid)?->requiresExplicitInput() === TRUE);
    if (array_intersect($input_required_uuids, $inputs->getComponentInstanceUuids()) !== $input_required_uuids) {
      throw new \LogicException(sprintf('The component UUIDs in the tree and inputs values do not match! Put a breakpoint here and figure out why.'));
    }

    // @todo Omit defaults that are stored at the content type template level, e.g. in core.entity_view_display.node.article.default.yml
    // $template_tree = '@todo';
    // $template_inputs = '@todo';
  }

  /**
   * {@inheritdoc}
   */
  public function postSave($update) {
    // @todo Remove this method once Drupal allows validating some constraints after some other constraints (i.e. ValidComponentTreeConstraintValidator must run after all other fields on an entity have been validated).

    // Re-run the validation logic now that fields that are required on this
    // entity are guaranteed to exist (i.e. the entity is no longer new, because
    // it already was saved).
    assert($this->getEntity()->isNew() === FALSE);
    // Because the entity is now guaranteed to not be new, a slightly stricter
    // validation is performed — if it fails, then an exception is thrown and
    // the entity saving database transaction is rolled back, and an error
    // message is displayed.
    // This should NEVER occur, but until Experience Builder is stable and/or
    // https://www.drupal.org/project/drupal/issues/2820364 is unresolved, this
    // ensures Experience Builder developers are informed early.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator::validate()
    $this->validate();
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable(ComponentTreeEntityInterface|FieldableEntityInterface|null $entity = NULL, bool $isPreview = FALSE): array {
    // We have to allow NULL for the entity argument here for co-variance with
    // the parent interface, but we don't support it.
    \assert(!\is_null($entity));
    return $this->get('hydrated')->toRenderable($entity, $isPreview);
  }

  public function updatePropSourcesOnDependencyRemoval(string $dependency_type, string $dependency_name, ?FieldableEntityInterface $host_entity = NULL): bool {
    $prop_sources_to_update = $this->get('inputs')
      ->getPropSourcesWithDependency($dependency_type, $dependency_name, $host_entity);

    $changed = FALSE;
    $inputs = Json::decode($this->get('inputs')->getValue());

    foreach ($prop_sources_to_update as $key => $prop_source) {
      [$instance_uuid, $name] = explode(':', $key, 2);
      // Remove this prop source; it depends on the removed config.
      unset($inputs[$instance_uuid][$name]);

      $component_source = $this->get('tree')->getComponentSource($instance_uuid);

      // If the component source requires explicit input, replace the removed
      // prop source with a static prop source. If we don't have a component
      // source for this component instance, there's nothing else we can do;
      // end users will probably see an error message, but that's not our fault.
      $default_inputs = $component_source?->getDefaultExplicitInput() ?? [];
      if ($component_source?->requiresExplicitInput() && isset($default_inputs[$name])) {
        $inputs[$instance_uuid][$name] = $default_inputs[$name];
      }
      $changed = TRUE;
    }
    $this->get('inputs')->setValue($inputs);
    return $changed;
  }

}
