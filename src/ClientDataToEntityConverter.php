<?php

namespace Drupal\experience_builder;

use Drupal\Core\Access\AccessException;
use Drupal\Core\Entity\EntityConstraintViolationList;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;

class ClientDataToEntityConverter {

  use ClientServerConversionTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {}

  public function convert(array $client_data, FieldableEntityInterface $entity): EntityConstraintViolationListInterface {
    // @todo Security hardening: any key besides `layout`, `model` and `entity_form_fields` should trigger an error response.
    ['layout' => $layout, 'model' => $model, 'entity_form_fields' => $entity_form_fields] = $client_data;

    [$tree, $props, $violations] = $this->convertClientToServer($layout, $model);
    if ($violations->count() > 0) {
      return new EntityConstraintViolationList($entity, iterator_to_array($violations));
    }

    $field_name = InternalXbFieldNameResolver::getXbFieldName($entity);
    $item = $entity->get($field_name)->first();
    assert($item instanceof ComponentTreeItem);
    $item->setValue([
      'tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
      'props' => json_encode($props, JSON_UNESCAPED_UNICODE),
    ]);
    $violations = $this->setEntityFields($entity, $entity_form_fields);
    if ($violations->count() > 0) {
      return $violations;
    }
    $original_entity_violations = $entity->validate();
    // Validation happens using the server-side representation, but the
    // error message should use the client-side representation received in
    // the request body.
    // @see ::clientLayoutToServerTree()
    // @see ::clientModelToServerProps()
    $transformed_violations = new EntityConstraintViolationList($entity, array_map(
      fn (ConstraintViolationInterface $v) => match (TRUE) {
        str_starts_with($v->getPropertyPath(), "$field_name.0.tree[" . ComponentTreeStructure::ROOT_UUID . "]") => self::violationWithPropertyPathReplacePrefix($v, "$field_name.0.tree[" . ComponentTreeStructure::ROOT_UUID . ']', 'layout.children'),
        // @todo Perform a more complex transformation to accurately point to non-root-level components, OR remove the need for that in https://www.drupal.org/project/experience_builder/issues/3467954
        str_starts_with($v->getPropertyPath(), "$field_name.0.tree") => self::violationWithPropertyPathReplacePrefix($v, "$field_name.0.tree", 'layout'),
        str_starts_with($v->getPropertyPath(), "$field_name.0.props") => self::violationWithPropertyPathReplacePrefix($v, "$field_name.0.props", 'model'),
        default => $v,
      },
      iterator_to_array($original_entity_violations),
    ));
    return $transformed_violations;
  }

  /**
   * Checks whether the given field should be PATCHed.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $original_field
   *   The original (stored) value for the field.
   * @param \Drupal\Core\Field\FieldItemListInterface $received_field
   *   The received value for the field.
   *
   * @return bool
   *   Whether the field should be PATCHed or not.
   *
   * @throws \Drupal\Core\Access\AccessException
   *   Thrown when the user sending the request is not allowed to update the
   *   field. Only thrown when the user could not abuse this information to
   *   determine the stored value.
   *
   * @see \Drupal\jsonapi\Controller\EntityResource::checkPatchFieldAccess
   */
  private function checkPatchFieldAccess(FieldItemListInterface $original_field, FieldItemListInterface $received_field): bool {
    // If the user is allowed to edit the field, it is always safe to set the
    // received value. We may be setting an unchanged value, but that is ok.
    $field_edit_access = $original_field->access('edit', NULL, TRUE);
    if ($field_edit_access->isAllowed()) {
      return TRUE;
    }

    // The user might not have access to edit the field, but still needs to
    // submit the current field value as part of the PATCH request. For
    // example, the entity keys required by denormalizers. Therefore, if the
    // received value equals the stored value, return FALSE without throwing an
    // exception. But only for fields that the user has access to view, because
    // the user has no legitimate way of knowing the current value of fields
    // that they are not allowed to view, and we must not make the presence or
    // absence of a 403 response a way to find that out.
    if ($original_field->access('view') && $original_field->equals($received_field)) {
      return FALSE;
    }

    // It's helpful and safe to let the user know when they are not allowed to
    // update a field.
    $field_name = $received_field->getName();
    throw new AccessException("The current user is not allowed to update the field '$field_name'.");
  }

  private function setEntityFields(FieldableEntityInterface $entity, array $entity_form_fields): EntityConstraintViolationListInterface {
    // Create a form state from the received entity fields.
    $form_state = new FormState();
    $form_state->set('entity', $entity);
    foreach ($entity_form_fields as $field_name => $field_value) {
      $form_state->setValue($field_name, $field_value);
    }
    $form_object = $this->entityTypeManager->getFormObject($entity->getEntityTypeId(), 'default');
    $form_object->setEntity($entity);
    $entity_form = $form_object->buildForm([], $form_state);

    // Copied from \Drupal\Core\Entity\ContentEntityForm::copyFormValuesToEntity().
    $form_display = $this->entityDisplayRepository->getFormDisplay($entity->getEntityTypeId(), $entity->bundle(), 'default');
    // Use the regular form display logic to set the field values on the entity.
    $extracted = $form_display->extractFormValues($entity, $entity_form, $form_state);
    // Then extract the values of fields that are not rendered through widgets,
    // by simply copying from top-level form values. This leaves the fields
    // that are not being edited within this form untouched.
    foreach ($form_state->getValues() as $name => $values) {
      if ($entity->hasField($name) && !isset($extracted[$name])) {
        $entity->set($name, $values);
      }
    }
    $original_entity = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    assert($original_entity instanceof FieldableEntityInterface);
    $violations_list = new EntityConstraintViolationList($entity);
    // Copied from \Drupal\jsonapi\Controller\EntityResource::updateEntityField().
    foreach ($entity_form_fields as $field_name => $field_value) {
      try {
        $original_field = $original_entity->get($field_name);
        // The field value on `$entity` will have been set in the call to
        // \Drupal\Core\Entity\Display\EntityFormDisplayInterface::extractFormValues()
        // above. `checkPatchFieldAccess()` will not
        // return a violation if the user does not have 'edit' access but the
        // user has 'view' access and  the received value equals the stored
        // value.
        if (!$this->checkPatchFieldAccess($original_field, $entity->get($field_name))) {
          $entity->set($field_name, $original_field->getValue());
        }
      }
      catch (\Exception $e) {
        $violations_list->add(new ConstraintViolation($e->getMessage(), $e->getMessage(), [], $field_value, "entity_form_fields.$field_name", $field_value));
      }
    }
    return $violations_list;
  }

}
