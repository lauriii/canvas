<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @internal
 * @phpstan-import-type ComponentTreeStructureArray from \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
 */
trait ClientServerConversionTrait {

  /**
   * @todo Refactor/remove in https://www.drupal.org/project/experience_builder/issues/3467954.
   *
   * @return array{0: ComponentTreeStructureArray, 1: \Symfony\Component\Validator\ConstraintViolationListInterface}
   */
  private static function clientLayoutToServerTree(array $layout) : array {
    // Transform client-side representation to server-side representation.
    // The entire component tree is nested under the reserved root UUID.
    $tree = self::doClientSlotToServerTree($layout, [], ComponentTreeStructure::ROOT_UUID);

    // Validate it.
    $definition = DataDefinition::create('component_tree_structure');
    $component_tree_structure = new ComponentTreeStructure($definition, 'component_tree_structure');
    $component_tree_structure->setValue(json_encode($tree, JSON_UNESCAPED_UNICODE));
    $violations = $component_tree_structure->validate();

    return [$tree, $violations];
  }

  /**
   * @phpstan-return ComponentTreeStructureArray
   */
  private static function doClientSlotToServerTree(array $layout, array $tree, string $parent_uuid): array {
    assert(isset($layout['nodeType']));

    // Regions have no name.
    $name = $layout['nodeType'] === 'slot' ? $layout['name'] : NULL;

    if (!\array_key_exists($parent_uuid, $tree)) {
      // Ensure the root level parent is set even if there are no components.
      $tree[$parent_uuid] = [];
    }
    foreach ($layout['components'] as $component) {
      $tree = self::doClientComponentToServerTree($component, $tree, $parent_uuid, $name);
    }

    return $tree;
  }

  /**
   * @phpstan-return ComponentTreeStructureArray
   */
  private static function doClientComponentToServerTree(array $layout, array $tree, string $parent_uuid, ?string $parent_slot): array {
    assert(isset($layout['nodeType']));
    assert($layout['nodeType'] === 'component');

    $component = \array_filter([
      'uuid' => $layout['uuid'] ?? NULL,
      'component' => $layout['type'] ?? NULL,
    ]);

    // Root level.
    if (!isset($parent_slot)) {
      $tree[$parent_uuid][] = $component;
    }
    // All other levels.
    else {
      $tree[$parent_uuid][$parent_slot][] = $component;
    }

    foreach ($layout['slots'] as $slot) {
      $tree = self::doClientSlotToServerTree($slot, $tree, $layout['uuid']);
    }

    return $tree;
  }

  /**
   * @return array{0: array<string, array<string, \Drupal\experience_builder\PropSource\StaticPropSource>>, 1: \Symfony\Component\Validator\ConstraintViolationList}
   */
  private function clientModelToServerProps(array $tree, array $model): array {
    $definition = DataDefinition::create('component_tree_structure');
    $component_tree_structure = new ComponentTreeStructure($definition, 'component_tree_structure');
    $component_tree_structure->setValue(json_encode($tree, JSON_UNESCAPED_UNICODE));

    // Remove irrelevant model data (e.g. from page template).
    $model = \array_intersect_key($model, \array_flip($component_tree_structure->getComponentInstanceUuids()));
    $props = [];
    $violation_list = new ConstraintViolationList();
    foreach ($model as $uuid => $client_props) {
      $component = Component::load($component_tree_structure->getComponentId($uuid));
      assert($component instanceof Component);
      [$props[$uuid], $violations_for_component_instance] = $component->getComponentSource()->createPropsForComponent($uuid, $component, $client_props);
      foreach ($violations_for_component_instance as $violation) {
        // We use ::add here rather than ::addAll because ::addAll doesn't reset
        // the internal groupings in EntityConstraintViolationList whereas ::add
        // does.
        // @see https://drupal.org/i/3490588
        $violation_list->add($violation);
      }
    }
    return [$props, $violation_list];
  }

  /**
   * @return array{0: ?array, 1: ?array, 2: \Symfony\Component\Validator\ConstraintViolationList}
   */
  protected function convertClientToServer(array $layout, array $model): array {
    // Denormalize the `layout` the client sent into a value that the server-
    // side ComponentTreeStructure expects, abort early if it is invalid.
    // (This is the value for the `tree` field prop on the XB field type.)
    // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
    [$tree, $violations] = self::clientLayoutToServerTree($layout);
    $transformed_violations = new ConstraintViolationList(array_map(
      fn (ConstraintViolationInterface $v) => self::violationWithPropertyPathReplacePrefix($v, '[' . ComponentTreeStructure::ROOT_UUID . ']', "layout.children"),
      iterator_to_array($violations),
    ));
    if ($transformed_violations->count() > 0) {
      return [NULL, NULL, $transformed_violations];
    }

    // Denormalize the `model` the client sent into a value that the server-side
    // ComponentPropsValues expects, and abort early if it is invalid.
    // (This is the value for the `props` field prop on the XB field type.)
    // @see \Drupal\experience_builder\Plugin\DataType\ComponentPropsValues
    // ⚠️ TRICKY: in order to denormalize `model`, `layout` must already been
    // been denormalized to `tree`, because only those values in `model` that
    // are for actually existing XB components can be denormalized.
    [$props, $violations] = $this->clientModelToServerProps($tree, $model);
    if ($violations->count() > 0) {
      return [NULL, NULL, $violations];
    }

    // Update the entity, validate and save.
    // Note: constructing ComponentTreeStructure from `layout` and
    // ComponentPropsValues from `model` also included validation. But that
    // included only structural validation, not semantical validation.
    // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeMeetsRequirementsConstraintValidator
    // @todo Make this double `foreach` unnecessary by making StaticPropSource implementing __serialize()?
    $props_prepared_for_saving = [];
    foreach ($props as $component_instance_uuid => $component_instance_props) {
      foreach ($component_instance_props as $prop_name => $prop_source) {
        $props_prepared_for_saving[$component_instance_uuid][$prop_name] = json_decode((string) $prop_source, TRUE);
      }
    }
    return [$tree, $props_prepared_for_saving, new ConstraintViolationList()];
  }

  private static function violationWithPropertyPathReplacePrefix(ConstraintViolationInterface $v, string $prefix_original, string $prefix_new): ConstraintViolationInterface {
    return new ConstraintViolation(
      $v->getMessage(),
      $v->getMessageTemplate(),
      $v->getParameters(),
      $v->getRoot(),
      preg_replace('/^' . preg_quote($prefix_original, '/') . '/', $prefix_new, $v->getPropertyPath()),
      $v->getInvalidValue(),
      $v->getPlural(),
      $v->getCode(),
      $v->getConstraint(),
      $v->getCause(),
    );
  }

}
