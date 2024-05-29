<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\Core\Render\Component\Exception\InvalidComponentException;
use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Plugin\DataType\ComponentPropsValues;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\PropSource\DynamicPropSource;
use Drupal\experience_builder\PropSource\PropSource;

/**
 * Plugin implementation of the 'component_tree' field type.
 *
 * @todo Implement PreconfiguredFieldUiOptionsInterface?
 * @todo How to achieve https://www.previousnext.com.au/blog/pitchburgh-diaries-decoupled-layout-builder-sprint-1-2?
 * @see https://git.drupalcode.org/project/metatag/-/blob/2.0.x/src/Plugin/Field/FieldType/MetatagFieldItem.php
 */
#[FieldType(
  id: "component_tree",
  label: new TranslatableMarkup("Experience Builder"),
  description: new TranslatableMarkup("Field to use Experience Builder for presenting these entities"),
  default_widget: "experience_builder_two_terrible_text_areas",
  default_formatter: "experience_builder_naive_render_sdc_tree",
  // list_class: ComponentItemList::class,
  constraints: [],
  // @todo Add support for both symmetric and asymmetric translations.
  // @see https://www.drupal.org/project/drupal/issues/3440578
  // @see content_translation_field_info_alter()
  column_groups: [],
  cardinality: 1,
)]
class ComponentTreeItem extends FieldItemBase implements RenderableInterface {

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      // @todo This should be configurable per bundle for max flexibility? Or should it be per entity type?
      'translation' => 'symmetric|asymmetric',
      // @todo Other things such as restricting what level of change is allowed? TBD.
    ] + parent::defaultFieldSettings();
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
    $tree = $this->get('tree')->getValue();
    return $tree === NULL || $tree === '' || $tree === Json::encode([]);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $props = $this->get('props');
    assert($props instanceof ComponentPropsValues);

    // This *internal-only* validation does not need to happen using validation
    // constraints because it does not validate user input: it only helps ensure
    // that the logic of this field type is correct.
    $component_instance_uuids = $tree->getComponentInstanceUuids();
    if ($component_instance_uuids != $props->getComponentInstanceUuids()) {
      throw new \LogicException(sprintf('The component UUIDs in the tree and props values do not match! Put a breakpoint here and figure out why.'));
    }

    // Validate that each prop source resolves into a value that is considered
    // valid by the destination SDC prop.
    // @todo Move to validation constraint.
    foreach ($component_instance_uuids as $component_instance_uuid) {
      $component_id = $tree->getComponentId($component_instance_uuid);
      $props_values = $this->resolveComponentProps($component_instance_uuid);
      try {
        $component = $this->getComponentPluginManager()->find($component_id);
        $this->getComponentValidator()->validateProps($props_values, $component);
      }
      catch (ComponentNotFoundException $e) {
        throw new \LogicException(sprintf('The component instance with UUID %s uses component %s but does not exist! Put a breakpoint here and figure out why.', $component_instance_uuid, $component_id));
      }
      catch (InvalidComponentException $e) {
        throw new \LogicException(sprintf('The component instance with UUID %s uses component %s and receives some invalid props! Put a breakpoint here and figure out why.', $component_instance_uuid, $component_id));
      }
    }

    // @todo Omit defaults that are stored at the content type template level, e.g. in core.entity_view_display.node.article.default.yml
    // $template_tree = '@todo';
    // $template_props = '@todo';
  }

  /**
   * @todo Move to validation constraint.
   */
  private function getComponentPluginManager(): ComponentPluginManager {
    return \Drupal::service(ComponentPluginManager::class);
  }

  /**
   * @todo Move to validation constraint.
   */
  private function getComponentValidator(): ComponentValidator {
    return \Drupal::service(ComponentValidator::class);
  }

  /**
   * @param string $component_instance_uuid
   *
   * @return array<string, mixed>
   */
  public function resolveComponentProps(string $component_instance_uuid): array {
    $props = $this->get('props');
    assert($props instanceof ComponentPropsValues);

    $entity = $this->getEntity();

    return array_map(
      fn (PropSource $s): mixed => $s instanceof DynamicPropSource
        ? $s->withHostEntity($entity)->evaluate()
        : $s->evaluate(),
      $props->getComponentPropsSources($component_instance_uuid)
    );
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
