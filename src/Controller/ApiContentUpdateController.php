<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItemInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationList;

// phpcs:disable
// @todo Remove this — this was added to keep the logic here simple until it is evolved to support all ComponentSource plugins' inputs.
final class InvalidRequestBodyValue extends \Exception {
  public function __construct(
    string $message,
    public readonly ?string $propertyPath = NULL,
  ) {
    $this->message = $message;
  }
}
// phpcs:enable

/**
 * Controller exposing HTTP API for updating Content entities using an XB field.
 *
 * (So: "content" as in "content entity type", not as in the human-readable
 * label for the `Node` content entity type.)
 *
 * @internal This HTTP API is intended only for the XB UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiContentUpdateController extends ApiControllerBase {

  use ClientServerConversionTrait;

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  public function __invoke(Request $request, FieldableEntityInterface $entity): JsonResponse {
    assert($entity->hasField('field_xb_demo'));
    // @todo Security hardening: any key besides `layout` and `model` should trigger an error response.
    // @todo Allow more keys when allowing data other than the XB component tree to be edited through the XB UI! See the `2.1. Content editing of meta fields` requirement, due for the 0.2 milestone: https://www.drupal.org/project/experience_builder/issues/3455753
    ['layout' => $layout, 'model' => $model] = json_decode($request->getContent(), TRUE);

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
    if ($validation_errors_response = self::createJsonResponseFromViolations($transformed_violations)) {
      return $validation_errors_response;
    }

    // Denormalize the `model` the client sent into a value that the server-side
    // ComponentPropsValues expects, and abort early if it is invalid.
    // (This is the value for the `props` field prop on the XB field type.)
    // @see \Drupal\experience_builder\Plugin\DataType\ComponentPropsValues
    // ⚠️ TRICKY: in order to denormalize `model`, `layout` must already been
    // been denormalized to `tree`, because only those values in `model` that
    // are for actually existing XB components can be denormalized.
    [$props, $violations] = $this->clientModelToServerProps($tree, $model);
    if ($validation_errors_response = self::createJsonResponseFromViolations($violations)) {
      return $validation_errors_response;
    }

    // Update the entity, validate and save.
    // Note: constructing ComponentTreeStructure from `layout` and
    // ComponentPropsValues from `model` also included validation. But that
    // included only structural validation, not semantical validation.
    // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ComponentTreeMeetsRequirementsConstraintValidator
    $item = $entity->get('field_xb_demo')->first();
    assert($item instanceof ComponentTreeItem);
    // @todo Make this double `foreach` unnecessary by making StaticPropSource implementing __serialize()?
    $props_prepared_for_saving = [];
    foreach ($props as $component_instance_uuid => $component_instance_props) {
      foreach ($component_instance_props as $prop_name => $prop_source) {
        $props_prepared_for_saving[$component_instance_uuid][$prop_name] = json_decode((string) $prop_source, TRUE);
      }
    }
    $item->setValue([
      'tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
      'props' => json_encode($props_prepared_for_saving, JSON_UNESCAPED_UNICODE),
    ]);
    $original_entity_violations = $entity->validate();
    // Validation happens using the server-side representation, but the
    // error message should use the client-side representation received in
    // the request body.
    // @see ::clientLayoutToServerTree()
    // @see ::clientModelToServerProps()
    $transformed_violations = new ConstraintViolationList(array_map(
      fn (ConstraintViolationInterface $v) => match (TRUE) {
        str_starts_with($v->getPropertyPath(), 'field_xb_demo.0.tree[' . ComponentTreeStructure::ROOT_UUID . ']') => self::violationWithPropertyPathReplacePrefix($v, 'field_xb_demo.0.tree[' . ComponentTreeStructure::ROOT_UUID . ']', 'layout.children'),
        // @todo Perform a more complex transformation to accurately point to non-root-level components, OR remove the need for that in https://www.drupal.org/project/experience_builder/issues/3467954
        str_starts_with($v->getPropertyPath(), 'field_xb_demo.0.tree') => self::violationWithPropertyPathReplacePrefix($v, "field_xb_demo.0.tree", 'layout'),
        str_starts_with($v->getPropertyPath(), 'field_xb_demo.0.props') => self::violationWithPropertyPathReplacePrefix($v, "field_xb_demo.0.props", 'model'),
        default => $v,
      },
      iterator_to_array($original_entity_violations),
    ));
    if ($validation_errors_response = self::createJsonResponseFromViolations($transformed_violations)) {
      return $validation_errors_response;
    }

    return self::save($entity);
  }

  /**
   * @return array{0: array<string, array<string, \Drupal\experience_builder\PropSource\StaticPropSource>>, 1: \Symfony\Component\Validator\ConstraintViolationListInterface}
   */
  private function clientModelToServerProps(array $tree, array $model): array {
    $definition = DataDefinition::create('component_tree_structure');
    $component_tree_structure = new ComponentTreeStructure($definition, 'component_tree_structure');
    $component_tree_structure->setValue(json_encode($tree, JSON_UNESCAPED_UNICODE));

    $props = [];
    $violation_list = new ConstraintViolationList();
    foreach ($model as $uuid => $client_props) {
      $component = $component_tree_structure->getComponentId($uuid);
      [$props[$uuid], $violations_for_component_instance] = $this->createPropsForComponent($uuid, $component, $client_props);
      $violation_list->addAll($violations_for_component_instance);
    }
    return [$props, $violation_list];
  }

  /**
   * @return array{0: array<string, \Drupal\experience_builder\PropSource\StaticPropSource>, 1: \Symfony\Component\Validator\ConstraintViolationListInterface}
   *
   * @todo Refactor in https://www.drupal.org/project/experience_builder/issues/3484666, because that issue introduces storing inputs for Block-sourced Components.
   * @todo Refactor to use the Symfony denormalizer infrastructure?
   */
  private function createPropsForComponent(string $component_instance_uuid, string $component, array $client_props): array {
    $violation_list = new ConstraintViolationList();
    $props = [];
    $component_entity = Component::load($component);
    assert($component_entity instanceof Component);
    try {
      // @todo Allow 'name' prop to be used in https://drupal.org/i/3467954 or https://www.drupal.org/i/3487773.
      $component_entity->getDefaultStaticPropSource('name');
      $violation_list->add(new ConstraintViolation("Component '$component' cannot be used. 'name' prop is not supported.", "Component '$component' cannot be used. 'name' prop is not supported.", [], $client_props, '', $client_props));
      return [[], $violation_list];
    }
    catch (\OutOfRangeException) {
      unset($client_props['name']);
    }
    foreach ($client_props as $prop => $prop_value) {
      $static_source = $component_entity->getDefaultStaticPropSource($prop);
      $updated_static_source = $static_source->withValue($prop_value);
      if ($static_source->fieldItem instanceof EntityReferenceItemInterface) {
        $target_type = $static_source->fieldItem->getFieldDefinition()->getSetting('target_type');
        try {
          $target_id = $this->findTargetForProps($prop_value, $target_type);
        }
        catch (InvalidRequestBodyValue $invalid) {
          $violation_list->add(new ConstraintViolation(
            $invalid->getMessage(),
            NULL,
            [],
            $client_props,
            $invalid->propertyPath
              ? "model.$component_instance_uuid.$prop.{$invalid->propertyPath}"
              : "model.$component_instance_uuid.$prop",
            $prop_value,
          ));
          continue;
        }
        $updated_static_source = $updated_static_source->withValue(
          array_diff_key($updated_static_source->getValue(), ['src' => NULL, 'target_id' => NULL])
          + ['target_id' => $target_id]
        );
      }
      $props[$prop] = $updated_static_source;
    }
    return [$props, $violation_list];
  }

  /**
   * @todo Remove this function in favor of the client sending the target id in
   *   https://drupal.org/i/3473336.
   */
  private function findTargetForProps(array $prop_value, string $target_type): int {
    if ($target_type !== 'media' && $target_type !== 'file') {
      // Once the 'target_id' is saved the target type won't be needed.
      throw new InvalidRequestBodyValue("Unsupported target type '$target_type'.");
    }
    $src = $prop_value['src'];

    // Only consider public files until we save 'target_id' in the client model.
    $base_path = '/' . PublicStream::basePath() . '/';
    $relative_path = substr($src, strlen($base_path));
    $drupal_uri = 'public://' . $relative_path;

    // Load the file entity using the 'uri'. 'filename' will not always work
    // because the file name can be changed in the uri.
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $drupal_uri]);
    $file = reset($files);
    if (!$file) {
      throw new InvalidRequestBodyValue("File '$src' not found.", 'src');
    }
    $file_id = $file->id();
    if ($target_type === 'file') {
      return (int) $file_id;
    }

    // TRICKY: this is tightly coupled to `media_library_storage_prop_shape_alter()`!
    $query = $this->entityTypeManager->getStorage('media')->getQuery()->condition('field_media_image.target_id', $file_id)->accessCheck();
    $media_ids = $query->execute();
    assert(is_array($media_ids));
    if (empty($media_ids)) {
      throw new InvalidRequestBodyValue("No media entity found that uses file '$src'.", 'src');
    }
    return (int) array_pop($media_ids);
  }

  /**
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected static function save(FieldableEntityInterface $entity): JsonResponse {
    if ($entity instanceof RevisionableInterface) {
      $entity->setNewRevision();
    }
    $entity->save();
    return new JsonResponse(data: ['message' => 'Saved successfully.'], status: 200);
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
