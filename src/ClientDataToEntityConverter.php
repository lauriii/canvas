<?php

namespace Drupal\experience_builder;

use Drupal\Core\Access\AccessException;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\experience_builder\Entity\EntityConstraintViolationList;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\Plugin\DataType\Timestamp;
use Drupal\experience_builder\Controller\ClientServerConversionTrait;
use Drupal\experience_builder\Exception\ConstraintViolationException;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\file\Plugin\Field\FieldType\FileItem;
use Symfony\Component\Validator\ConstraintViolation;

class ClientDataToEntityConverter {

  use ClientServerConversionTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
  ) {}

  /**
   * @todo remove the validate flag in https://www.drupal.org/i/3505018.
   */
  public function convert(array $client_data, FieldableEntityInterface $entity, bool $validate = TRUE): void {
    $expected_keys = ['layout', 'model', 'entity_form_fields'];
    if (!empty(array_diff_key($client_data, array_flip($expected_keys)))) {
      throw new \LogicException();
    }
    ['layout' => $layout, 'model' => $model, 'entity_form_fields' => $entity_form_fields] = $client_data;

    $field_name = InternalXbFieldNameResolver::getXbFieldName($entity);
    $item = $entity->get($field_name)->first();
    assert($item instanceof ComponentTreeItem);

    try {
      $item->setValue($this->convertClientToServer($layout, $model, $entity, $validate));
    }
    catch (ConstraintViolationException $e) {
      // @todo Remove iterator_to_array() after https://www.drupal.org/project/drupal/issues/3497677
      throw new ConstraintViolationException(new EntityConstraintViolationList($entity, iterator_to_array($e->getConstraintViolationList())));
    }

    $this->setEntityFields($entity, $entity_form_fields);
    $original_entity_violations = $entity->validate();
    // Validation happens using the server-side representation, but the
    // error message should use the client-side representation received in
    // the request body.
    // @see ::convertClientToServer()
    if ($original_entity_violations->count() && $validate) {
      // @todo Remove iterator_to_array() after https://www.drupal.org/project/drupal/issues/3497677
      throw (new ConstraintViolationException(new EntityConstraintViolationList($entity, iterator_to_array($original_entity_violations))))->renamePropertyPaths([
        "$field_name.0.tree[" . ComponentTreeStructure::ROOT_UUID . "]" => 'layout.children',
        "$field_name.0.tree" => 'layout',
        "$field_name.0.inputs" => 'model',
      ]);
    }
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

  private function setEntityFields(FieldableEntityInterface $entity, array $entity_form_fields): void {
    // Create a form state from the received entity fields.
    $form_state = new FormState();
    $form_state->set('entity', $entity);
    // Expand form values from their respective element name, e.g.
    // ['title[0][value]' => 'Node title'] becomes
    // ['title' => ['value' => 'Node title']].
    // @see \Drupal\experience_builder\Controller\ApiLayoutController::getEntityData
    \parse_str(\http_build_query($entity_form_fields), $entity_form_fields);
    // Filter out form fields that are not entity fields.
    $entity_form_fields = array_filter($entity_form_fields, static fn (string|int $key): bool => is_string($key) && $entity->hasField($key), ARRAY_FILTER_USE_KEY);
    // Checkboxes are unique in that the browser doesn't submit a value when the
    // field is unchecked. We need to remove these from the field values when
    // that is the case.
    $boolean_fields = \array_keys(\array_filter(
      $entity->getFields(),
      static fn (FieldItemListInterface $fieldItemList): bool => $fieldItemList->getFieldDefinition()->getType() === 'boolean'
    ));

    // Handle quirks of managed file elements.
    // @todo Remove this when https://www.drupal.org/project/drupal/issues/3498054 is fixed.
    $file_fields = \array_keys(\array_filter(
      $entity->getFields(),
      static fn (FieldItemListInterface $fieldItemList): bool => \is_a($fieldItemList->getItemDefinition()->getClass(), FileItem::class, TRUE)
    ));
    foreach (\array_intersect_key($entity_form_fields, \array_flip($file_fields)) as $field_name => $values) {
      if (!\is_array($values)) {
        continue;
      }
      foreach ($values as $delta => $value) {
        // @see \Drupal\file\Element\ManagedFile::valueCallback
        if (\array_key_exists('fids', $value) && \is_array($value['fids'])) {
          $entity_form_fields[$field_name][$delta]['fids'] = \implode(' ', $value['fids']);
        }
      }
    }
    $entity_form_fields = \array_filter($entity_form_fields, static fn (array|string $value, string|int $key): bool => !\in_array($key, $boolean_fields, TRUE) || $value !== ['value' => '0'], ARRAY_FILTER_USE_BOTH);
    $form_object = $this->entityTypeManager->getFormObject($entity->getEntityTypeId(), 'default');
    $form_object->setEntity($entity);
    // Flag this as a programmatic build of the entity form - but do not flag
    // the form as submitted, as we don't want to execute submit handlers such
    // as ::save that would save the entity.
    $form_state
      // Set form object
      ->setFormObject($form_object)
      // Flag that we want to process input.
      ->setProcessInput()
      // But that the build is programmed (which bypasses caches etc).
      ->setProgrammed()
      // But access checks should still be accounted for.
      ->setProgrammedBypassAccessCheck(FALSE)
      // With the values provided from the front-end.
      ->setUserInput($entity_form_fields);
    $form = $this->formBuilder->buildForm($form_object, $form_state);
    // Now trigger the form level submit handler.
    $form_object->submitForm($form, $form_state);
    // And retrieve the updated entity.
    $updated_entity = $form_object->getEntity();
    \assert($updated_entity instanceof FieldableEntityInterface);
    $form_updated_changed_field = FALSE;
    foreach (\array_intersect_key($updated_entity->getFields(), $entity_form_fields) as $name => $items) {
      // Only update values for fields the user submitted.
      if (!\is_string($name) || !$entity->hasField($name)) {
        continue;
      }
      $entity->set($name, $items->getValue());
      // TRICKY: We call `$form_object->submitForm($form, $form_state);` which will most likely call
      // . \Drupal\Core\Entity\ContentEntityForm::submitForm() which will call
      // \Drupal\Core\Entity\EntityChangedInterface::setChangedTime().
      // \Drupal\Core\Field\ChangedFieldItemList::hasAffectingChanges accounts for this
      // by always returning FALSE. But we can't use `hasAffectingChanges` for checking equality
      // when considering field access because other modules might allow other changes.
      // We need to remember if the 'changed' field was updated in the form. If it was, we
      // can skip checking access to the field because we know it was updated by the form
      // not the client input, and we want to keep the change the form made. This also
      // allows us to check access in the edge case where the entity form has overridden
      // \Drupal\Core\Form\FormInterface::submitForm() to not call
      // \Drupal\Core\Entity\EntityChangedInterface::setChangedTime().
      // @see \Drupal\Core\Entity\ContentEntityForm::updateChangedTime()
      // @see \Drupal\Core\Field\ChangedFieldItemList::hasAffectingChanges()
      if ($entity instanceof EntityChangedInterface && $name === 'changed') {
        $changed_timestamp = $items->first()?->get('value');
        assert($changed_timestamp instanceof Timestamp);
        $changed_timestamp_int = $changed_timestamp->getCastedValue();
        assert(is_int($changed_timestamp_int));
        $form_updated_changed_field = $changed_timestamp_int !== ((int) $entity_form_fields['changed']);
      }
    }

    $original_entity = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    assert($original_entity instanceof FieldableEntityInterface);
    $violations_list = new EntityConstraintViolationList($entity);
    // Copied from \Drupal\jsonapi\Controller\EntityResource::updateEntityField()
    // but with the additional special-casing for `changed`.
    foreach ($entity_form_fields as $field_name => $field_value) {
      \assert(\is_string($field_name));
      if ($field_name === 'changed' && $form_updated_changed_field) {
        continue;
      }
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
    if ($violations_list->count()) {
      throw new ConstraintViolationException($violations_list);
    }
  }

}
