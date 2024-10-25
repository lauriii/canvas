<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityDisplayBase;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormState;

final class EntityFormController extends ControllerBase {

  public function form(string $entity_type, FieldableEntityInterface $entity, string $entity_form_mode): array {
    // The 'default' value sent to `\Drupal\Core\Entity\EntityTypeManagerInterface::getFormObject`
    // is for 'operation' not form mode.
    $form = $this->entityTypeManager()->getFormObject($entity_type, 'default');
    $form->setEntity($entity);
    assert($form instanceof ContentEntityForm);
    // `EntityFormDisplay::collectRenderDisplay()` assumes that if the form mode is 'default'
    // then $default_fallback should be TRUE. Otherwise, it will have a warning
    // for an undefined variable.
    // For all other form modes, the default fallback should be FALSE because the
    // client is requesting a specific form mode it expects to be available.
    $default_fallback = $entity_form_mode === 'default';
    $form_display = EntityFormDisplay::collectRenderDisplay($entity, $entity_form_mode, $default_fallback);
    // TRICKY: If a form display is returned with the EntityDisplayBase::CUSTOM_MODE then
    // `EntityFormDisplay::collectRenderDisplay()` was not able to find a form
    // display for the requested form mode and created runtime form display that
    // is not saved in config. For our purpose we don't want this functionality.
    // Since the client is specifically requesting a form mode it should be considered
    // an error if that form mode is not found.
    // We can't simply check `$form_display->getMode() !== $entity_form_mode`
    // because the requested form mode could have altered by a hook and in that case we
    // should respect that change.
    // @see hook_ENTITY_TYPE_form_mode_alter()
    // @see hook_entity_form_mode_alter()
    if (!$form_display || $form_display->getMode() === EntityDisplayBase::CUSTOM_MODE) {
      throw new \UnexpectedValueException(sprintf('The "%s" form display was not found', $entity_form_mode));
    }
    assert($form_display instanceof EntityFormDisplay);
    $form_state = new FormState();
    $form->setFormDisplay($form_display, $form_state);

    return $this->formBuilder()->buildForm($form, $form_state);
  }

}
