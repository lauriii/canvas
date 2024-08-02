<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\experience_builder\PropSource\PropSource;
use Drupal\experience_builder\PropSource\StaticPropSource;

/**
 * Allows editing the prop sources for a component.
 */
final class ComponentPropsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'component_props_form';
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\Core\Entity\Entity\EntityFormDisplay::buildForm()
   * @see \Drupal\Core\Field\WidgetBase::form()
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?FieldableEntityInterface $entity = NULL): array {
    $props = $this->getRequest()->get('props');
    $component_instance_uuid = $this->getRequest()->get('selected');
    $stored_prop_sources = json_decode($props, TRUE)[$component_instance_uuid];

    // ⚠️ This is HORRIBLY HACKY and will go away! ☺️
    // @see \Drupal\experience_builder\Controller\SdcController::layout()
    if (!$entity || $entity->bundle() !== 'article') {
      throw new \LogicException('For now, this assumes the entity is an article!');
    }

    $form['#parents'] = ['xb_component_props', $component_instance_uuid];
    foreach ($stored_prop_sources as $sdc_prop_name => $prop_source_array) {
      $source = PropSource::parse($prop_source_array);
      if ($source instanceof StaticPropSource) {
        $form[$sdc_prop_name] = $source->formTemporaryRemoveThisExclamationExclamationExclamation($component_instance_uuid, $sdc_prop_name, $entity, $form, $form_state);
      }
      // @todo Design is undefined for the DynamicPropSource UX. Related: https://www.drupal.org/project/experience_builder/issues/3459234
      // @todo Design is undefined for the AdaptedPropSource UX.
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // @todo implement submitForm() method.
  }

}
