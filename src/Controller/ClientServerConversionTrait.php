<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Validation\BasicRecursiveValidatorFactory;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Entity\EntityConstraintViolationList;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraint;
use Drupal\experience_builder\Validation\ConstraintPropertyPathTranslatorTrait;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @internal
 * @phpstan-import-type ComponentTreeItemListArray from \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type ComponentClientStructureArray from \Drupal\experience_builder\Controller\ApiLayoutController
 * @phpstan-import-type RegionClientStructureArray from \Drupal\experience_builder\Controller\ApiLayoutController
 * @phpstan-import-type LayoutClientStructureArray from \Drupal\experience_builder\Controller\ApiLayoutController
 */
trait ClientServerConversionTrait {

  use ConstraintPropertyPathTranslatorTrait;
  use ComponentTreeItemListInstantiatorTrait;

  /**
   * @todo Refactor/remove in https://www.drupal.org/project/experience_builder/issues/3467954.
   * @param LayoutClientStructureArray $layout
   * @phpstan-return ComponentTreeItemListArray
   * @throws \Drupal\experience_builder\Exception\ConstraintViolationException
   *
   * @todo remove the validate flag in https://www.drupal.org/i/3505018.
   */
  protected static function clientToServerTree(array $layout, array $model, ?FieldableEntityInterface $entity, bool $validate = TRUE): array {
    // Transform client-side representation to server-side representation.
    $items = [];
    foreach ($layout as $component) {
      assert($component['nodeType'] === 'component');
      $items = \array_merge($items, self::doClientComponentToServerTree($component, $model, ComponentTreeItemList::ROOT_UUID, NULL));
    }
    if ($validate) {
      // Validate the items represent a valid tree.
      /** @var \Symfony\Component\Validator\Validator\RecursiveValidator $validator */
      $validator = \Drupal::service(BasicRecursiveValidatorFactory::class)->createValidator();
      $violations = $validator->validate($items, new ComponentTreeStructureConstraint(['basePropertyPath' => 'layout.children']));
      if ($violations->count() > 0) {
        throw new ConstraintViolationException($violations);
      }
    }
    return self::clientModelToInput($items, $entity, $validate);
  }

  /**
   * @param LayoutClientStructureArray $layout
   * @phpstan-return ComponentTreeItemListArray
   */
  private static function doClientSlotToServerTree(array $layout, array $model, string $parent_uuid): array {
    assert(isset($layout['nodeType']));

    // Regions have no name.
    $name = $layout['nodeType'] === 'slot' ? $layout['name'] : NULL;

    $items = [];
    foreach ($layout['components'] as $component) {
      $items = \array_merge($items, self::doClientComponentToServerTree($component, $model, $parent_uuid, $name));
    }

    return $items;
  }

  /**
   * @phpstan-param ComponentClientStructureArray $layout
   * @phpstan-return ComponentTreeItemListArray
   */
  private static function doClientComponentToServerTree(array $layout, array $model, string $parent_uuid, ?string $parent_slot): array {
    \assert(\array_key_exists('nodeType', $layout));
    \assert($layout['nodeType'] === 'component');

    $uuid = $layout['uuid'] ?? NULL;
    $component_id = $layout['type'] ?? NULL;
    $version = NULL;
    // `type` SHOULD be of the form `<Component config entity ID>@<version>`.
    // @see \Drupal\experience_builder\Entity\VersionedConfigEntityInterface::getVersions()
    if ($component_id !== NULL && str_contains($component_id, '@')) {
      [$component_id, $version] = explode('@', $component_id, 2);
    }
    $component = [
      'uuid' => $layout['uuid'] ?? NULL,
      'component_id' => $component_id,
      'component_version' => $version,
      'inputs' => [],
    ];
    $name = $layout['name'] ?? NULL;
    if ($name !== NULL) {
      $component['label'] = $name;
    }
    if ($uuid !== NULL) {
      $component['inputs'] = $model[$uuid] ?? [];
    }

    if ($parent_slot !== NULL) {
      $component['slot'] = $parent_slot;
      $component['parent_uuid'] = $parent_uuid;
    }
    $items = [$component];

    foreach ($layout['slots'] as $slot) {
      $items = \array_merge($items, self::doClientSlotToServerTree($slot, $model, $layout['uuid']));
    }

    return $items;
  }

  /**
   * @phpcs:ignore
   * @return ComponentTreeItemListArray
   * @throws \Drupal\experience_builder\Exception\ConstraintViolationException
   */
  private static function clientModelToInput(array $items, ?FieldableEntityInterface $entity = NULL, bool $validate = TRUE): array {
    $component_ids = \array_column($items, 'component_id');
    $components = Component::loadMultiple($component_ids);

    $violation_list = NULL;
    if ($validate) {
      $violation_list = $entity ? new EntityConstraintViolationList($entity) : new ConstraintViolationList();
    }
    foreach ($items as $delta => ['uuid' => $uuid, 'component_id' => $component_id, 'inputs' => $inputs]) {
      $component = $components[$component_id] ?? NULL;
      // If validation is requested, this has already been validated in
      // ::clientToServerTree
      // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeStructureConstraint
      if (!$validate && !$component) {
        continue;
      }
      assert($component instanceof ComponentInterface);
      $source = $component->getComponentSource();
      // First we transform the incoming client model into input values using
      // the source plugin.
      $items[$delta]['inputs'] = $source->clientModelToInput($uuid, $component, $inputs, $violation_list);
      if ($violation_list !== NULL) {
        // Then we ensure the input values are valid using the source plugin.
        $component_violations = self::translateConstraintPropertyPathsAndRoot(
          ['inputs.' => 'model.'],
          $source->validateComponentInput($items[$delta]['inputs'], $uuid, $entity)
        );
        if ($component_violations->count() > 0) {
          // @todo Remove the foreach and use ::addAll once
          // https://www.drupal.org/project/drupal/issues/3490588 has been resolved.
          foreach ($component_violations as $violation) {
            $violation_list->add($violation);
          }
        }
      }
    }
    if ($violation_list !== NULL && $violation_list->count()) {
      throw new ConstraintViolationException($violation_list);
    }
    return $items;
  }

  /**
   * @param LayoutClientStructureArray $layout
   * @phpstan-return ComponentTreeItemListArray
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
      return self::clientToServerTree($layout, $model, $entity, $validate);
    }
    catch (ConstraintViolationException $e) {
      throw $e->renamePropertyPaths(["[" . ComponentTreeItemList::ROOT_UUID . "]" => 'layout.children']);
    }
  }

}
