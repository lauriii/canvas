<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Block\MainContentBlockPluginInterface;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Block\TitleBlockPluginInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\ComponentPluginManager;
use Drupal\experience_builder\Plugin\DataType\ComponentPropsValues;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\PropSource\PropSourceBase;
use Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester;

/**
 * Plugin implementation of the 'component_tree' field type.
 *
 * @todo Implement PreconfiguredFieldUiOptionsInterface?
 * @todo How to achieve https://www.previousnext.com.au/blog/pitchburgh-diaries-decoupled-layout-builder-sprint-1-2?
 * @see https://git.drupalcode.org/project/metatag/-/blob/2.0.x/src/Plugin/Field/FieldType/MetatagFieldItem.php
 *
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 */
#[FieldType(
  id: "component_tree",
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
      'props' => [
        'absence' => [
          'dynamic',
          'adapter',
        ],
        'presence' => NULL,
      ],
      'tree' => [
        'absence' => [
          // Components implementing either of these 3 interfaces are only
          // allowed to live at the PageTemplate level.
          // @see \Drupal\experience_builder\Entity\PageTemplate
          // @see `type: experience_builder.page_template.*`
          MainContentBlockPluginInterface::class,
          TitleBlockPluginInterface::class,
          MessagesBlockPluginInterface::class,
        ],
        'presence' => NULL,
      ],
    ],
  ],
  // @see docs/data-model.md
  // @see content_translation_field_info_alter()
  // @see experience_builder_entity_prepare_view()
  column_groups: [
    'props' => [
      'label' => new TranslatableMarkup('Component property values'),
      'translatable' => TRUE,
    ],
    'tree' => [
      'label' => new TranslatableMarkup('Component tree'),
      'translatable' => TRUE,
    ],
  ],
  cardinality: 1,
)]
class ComponentTreeItem extends FieldItemBase implements RenderableInterface {

  /**
   * {@inheritdoc}
   */
  public static function calculateDependencies(FieldDefinitionInterface $field_definition) {
    $dependencies = parent::calculateDependencies($field_definition);

    if (empty($field_definition->getDefaultValueLiteral())) {
      return $dependencies;
    }

    $default_value = $field_definition->getDefaultValueLiteral()[0];
    $tree = ComponentTreeStructure::createInstance(DataDefinition::create('component_tree_structure'));
    // The default should always have a "tree" key but this runs before validation.
    if (!isset($default_value['tree'])) {
      return [];
    }
    $tree->setValue($default_value['tree']);

    foreach (Component::loadMultiple($tree->getComponentIdList()) as $component_entity) {
      assert($component_entity instanceof Component);
      $dependencies[$component_entity->getConfigDependencyKey()][] = $component_entity->getConfigDependencyName();
    }

    return $dependencies;
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
          'sqlite_type' => 'json',
          'not null' => FALSE,
        ],
        'props' => [
          'description' => 'The prop values for each component in the component tree.',
          'type' => 'json',
          'pgsql_type' => 'jsonb',
          'mysql_type' => 'json',
          'sqlite_type' => 'json',
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
      ->setLabel(new TranslatableMarkup('A component tree without props values.'))
      ->setRequired(TRUE);

    $properties['props'] = DataDefinition::create('component_props_values')
      ->setLabel(new TranslatableMarkup('Prop values for each component in the component tree'))
      ->setRequired(TRUE);

    $properties['hydrated'] = DataDefinition::create('component_tree_hydrated')
      ->setLabel(new TranslatableMarkup('The hydrated component tree: structure + props values combined — provides render tree for client side or render array for server side.'))
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
    // If either `tree` or `props` is not set, consider this not empty, because
    // it is not empty in a *valid* way. If considered empty, the
    // NotNullConstraintValidator would apply some magic that prevents detailed
    // validation.
    // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\NotNullConstraintValidator::validate()
    if ($this->get('tree')->getValue() === NULL || $this->get('props')->getValue() === NULL) {
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
  public function setValue($values, $notify = TRUE): void {
    // This field type does not want either:
    // - the parent FieldItemBase::setValue()'s behavior, which assigns $values
    //   to the first property if $values is not an array.
    // - the grandparent Map::setValue() removes key-value pairs from
    //   $this->values that are assigned to a n on-computed property.
    // Both of those behaviors prevent strict validation. Instead, perform *no*
    // magic transformations, just respect the `tree` and `props` key-value
    // pairs, if they are provided.
    if (is_array($values)) {
      // Store the exact values passed in to be assigned to the contained
      // properties.
      $this->values = $values;
      // Assign the values to the contained properties.
      if (array_key_exists('tree', $values)) {
        $this->set('tree', $values['tree'], FALSE);
      }
      if (array_key_exists('props', $values)) {
        $this->set('props', $values['props'], FALSE);
      }
    }

    // If they are missing, fall back to the default value of the non-computed
    // properties `tree` and `props`. This avoids a *repeated* validation error:
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
    assert($tree instanceof ComponentTreeStructure);
    $props = $this->get('props');
    assert($props instanceof ComponentPropsValues);

    $props->ensureMinimalPropSourceRepresentations();

    // This *internal-only* validation does not need to happen using validation
    // constraints because it does not validate user input: it only helps ensure
    // that the logic of this field type is correct.
    $component_instance_uuids = $tree->getComponentInstanceUuids();
    if (array_intersect($component_instance_uuids, $props->getComponentInstanceUuids()) !== $component_instance_uuids) {
      throw new \LogicException(sprintf('The component UUIDs in the tree and props values do not match! Put a breakpoint here and figure out why.'));
    }

    // @todo Omit defaults that are stored at the content type template level, e.g. in core.entity_view_display.node.article.default.yml
    // $template_tree = '@todo';
    // $template_props = '@todo';
  }

  /**
   * @param string $component_instance_uuid
   *
   * @return array<string, mixed>
   */
  public function resolveComponentProps(string $component_instance_uuid): array {
    $props = $this->get('props');
    assert($props instanceof ComponentPropsValues);
    $entity = $this->getRoot() === $this ? NULL : $this->getEntity();
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    if (!self::componentHasProps($tree->getComponentId($component_instance_uuid))) {
      return [];
    }

    return array_map(
      fn (PropSourceBase $s): mixed => $s->evaluate($entity),
      $props->getComponentPropsSources($component_instance_uuid)
    );
  }

  /**
   * phpcs:ignore Drupal.Commenting.DataTypeNamespace.DataTypeNamespace
   * @param ComponentConfigEntityId $component_id
   *   A Component config entity ID.
   *
   * @return bool
   */
  protected static function componentHasProps(string $component_id): bool {
    $component_manager = \Drupal::service(ComponentPluginManager::class);
    $component = $component_manager->find(Component::convertIdToMachineName($component_id));
    return !empty($component->metadata->schema['properties']);
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
   * @return array<string, array<string, array{instances: array<string, \Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression|\Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression|\Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression>}>>
   */
  public function getAvailablePropSourceChoices(): mixed {
    $prop_source_suggester = \Drupal::service(FieldForComponentSuggester::class);
    assert($prop_source_suggester instanceof FieldForComponentSuggester);

    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $host_entity_type = $this->getEntity()->getTypedData()->getDataDefinition();
    assert($host_entity_type instanceof EntityDataDefinitionInterface);

    $choices = [];
    foreach ($tree->getComponentInstanceUuids() as $uuid) {
      $component_plugin_id = $tree->getComponentId($uuid);
      if (array_key_exists($component_plugin_id, $choices)) {
        // The same component plugin may be instantiated multiple times — no
        // need to find prop source suggestions for each instance.
        continue;
      }
      $choices[$component_plugin_id] = $prop_source_suggester->suggest($component_plugin_id, $host_entity_type);
    }
    return $choices;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable(): array {
    $hydrated = $this->get('hydrated');
    assert($hydrated instanceof ComponentTreeHydrated);
    return $hydrated->toRenderable();
  }

}
