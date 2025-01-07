<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\FieldableEntityInterface;

final class EntityFormController extends ControllerBase {

  use EntityFormTrait;

  public function form(string $entity_type, FieldableEntityInterface $entity, string $entity_form_mode): array {
    // The 'default' value sent to `\Drupal\Core\Entity\EntityTypeManagerInterface::getFormObject`
    // is for 'operation' not form mode.
    $form = $this->entityTypeManager()->getFormObject($entity_type, 'default');
    $form_state = $this->buildFormState($form, $entity, $entity_form_mode);

    return $this->formBuilder()->buildForm($form, $form_state);
  }

}
