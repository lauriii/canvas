<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\experience_builder\Entity\EntityConstraintViolationList;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Validation\ConstraintPropertyPathTranslatorTrait;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @internal
 * @phpstan-import-type ComponentTreeStructureArray from \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
 */
trait ClientServerConversionTrait {

  use ConstraintPropertyPathTranslatorTrait;

  /**
   * @todo Refactor/remove in https://www.drupal.org/project/experience_builder/issues/3467954.
   *
   * @phpstan-return ComponentTreeStructureArray
   * @throws \Drupal\experience_builder\Exception\ConstraintViolationException
   *
   * @todo remove the validate flag in https://www.drupal.org/i/3505018.
   */
  private static function clientLayoutToServerTree(array $layout, bool $validate = TRUE): array {
    // Transform client-side representation to server-side representation.
    // The entire component tree is nested under the reserved root UUID.
    // Top level of elements in component tree are regions, except for Patterns/Sections.
    if (isset($layout['nodeType']) && $layout['nodeType'] === 'region') {
      $tree = self::doClientSlotToServerTree($layout, [], ComponentTreeStructure::ROOT_UUID);
    }
    // For Patterns and PageRegions, the top level is array of components. A
    // Pattern is guaranteed to be non-empty, but a PageRegion is not.
    else {
      $tree = [
        ComponentTreeStructure::ROOT_UUID => [],
      ];
      foreach ($layout as $component) {
        assert($component['nodeType'] === 'component');
        $tree = self::doClientComponentToServerTree($component, $tree, ComponentTreeStructure::ROOT_UUID, NULL);
      }
    }

    if (!$validate) {
      return $tree;
    }

    // Validate it.
    $definition = DataDefinition::create('component_tree_structure');
    $component_tree_structure = new ComponentTreeStructure($definition, 'component_tree_structure');
    $component_tree_structure->setValue(json_encode($tree, JSON_UNESCAPED_UNICODE));
    $violations = $component_tree_structure->validate();
    if ($violations->count()) {
      throw new ConstraintViolationException($violations);
    }

    return $tree;
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
   * @return array<string, array<string, \Drupal\experience_builder\PropSource\PropSourceBase>>
   * @throws \Drupal\experience_builder\Exception\ConstraintViolationException
   */
  private static function clientModelToInput(array $tree, array $full_model, ?FieldableEntityInterface $entity = NULL): array {
    $definition = DataDefinition::create('component_tree_structure');
    $component_tree_structure = new ComponentTreeStructure($definition, 'component_tree_structure');
    $component_tree_structure->setValue(json_encode($tree, JSON_UNESCAPED_UNICODE));

    // Remove irrelevant model data (e.g. from page regions).
    $model = \array_intersect_key($full_model, \array_flip($component_tree_structure->getComponentInstanceUuids()));
    $inputs = [];
    $violation_list = $entity ? new EntityConstraintViolationList($entity) : new ConstraintViolationList();
    foreach ($model as $uuid => $client_model) {
      $component = Component::load($component_tree_structure->getComponentId($uuid));
      assert($component instanceof Component);
      $source = $component->getComponentSource();
      // First we transform the incoming client model into input values using
      // the source plugin.
      $inputs[$uuid] = $source->clientModelToInput($uuid, $component, $client_model, $violation_list);
      // Then we ensure the input values are valid using the source plugin.
      $component_violations = self::translateConstraintPropertyPathsAndRoot(
        ['inputs.' => 'model.'],
        $source->validateComponentInput($inputs[$uuid], $uuid, $entity)
      );
      if ($component_violations->count() > 0) {
        // @todo Remove the foreach and use ::addAll once
        // https://www.drupal.org/project/drupal/issues/3490588 has been resolved.
        foreach ($component_violations as $violation) {
          $violation_list->add($violation);
        }
      }
    }
    if ($violation_list->count()) {
      throw new ConstraintViolationException($violation_list);
    }
    return $inputs;
  }

  /**
   * @return array{tree: string, inputs: string}
   * @throws \Drupal\experience_builder\Exception\ConstraintViolationException
   *
   * @todo remove the validate flag in https://www.drupal.org/i/3505018.
   */
  protected static function convertClientToServer(array $layout, array $model, ?FieldableEntityInterface $entity = NULL, bool $validate = TRUE): array {
    // Denormalize the `layout` the client sent into a value that the server-
    // side ComponentTreeStructure expects, abort early if it is invalid.
    // (This is the value for the `tree` field prop on the XB field type.)
    // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraintValidator
    try {
      $tree = self::clientLayoutToServerTree($layout, $validate);
    }
    catch (ConstraintViolationException $e) {
      throw $e->renamePropertyPaths(["[" . ComponentTreeStructure::ROOT_UUID . "]" => 'layout.children']);
    }

    // Denormalize the `model` the client sent into a value that the server-side
    // ComponentInputs expects, and abort early if it is invalid.
    // (This is the value for the `inputs` field prop on the XB field type.)
    // @see \Drupal\experience_builder\Plugin\DataType\ComponentInputs
    // ⚠️ TRICKY: in order to denormalize `model`, `layout` must already been
    // been denormalized to `tree`, because only those values in `model` that
    // are for actually existing XB components can be denormalized.
    $inputs = self::clientModelToInput($tree, $model, $entity);

    // Update the entity, validate and save.
    // Note: constructing ComponentTreeStructure from `layout` and
    // ComponentInputs from `model` also included validation. But that
    // included only structural validation, not semantical validation.
    // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeMeetsRequirementsConstraintValidator
    return [
      'tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR),
      'inputs' => json_encode($inputs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
    ];
  }

}
