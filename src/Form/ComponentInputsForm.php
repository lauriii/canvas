<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\InternalXbFieldNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Allows editing the prop sources for a component.
 */
final class ComponentInputsForm extends FormBase {

  /**
   * The component plugin manager.
   *
   * @var \Drupal\Core\Theme\ComponentPluginManager
   */
  protected $componentPluginManager;

  public function __construct(
    ComponentPluginManager $componentPluginManager,
  ) {
    // Unable to use property injection due to extending a class that does not
    // use it.
    $this->componentPluginManager = $componentPluginManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ComponentPluginManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'component_inputs_form';
  }

  /**
   * {@inheritdoc}
   *
   * @see \Drupal\Core\Entity\Entity\EntityFormDisplay::buildForm()
   * @see \Drupal\Core\Field\WidgetBase::form()
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?FieldableEntityInterface $entity = NULL): array {
    // ⚠️ This is HORRIBLY HACKY and will go away! ☺️
    // @see \Drupal\experience_builder\Controller\ApiLayoutController
    if (is_null($entity)) {
      throw new \UnexpectedValueException('The $entity parameter should never be NULL.');
    }
    // We just need to verify that the entity has a XB field
    // so that component form can be displayed.
    InternalXbFieldNameResolver::getXbFieldName($entity);

    $component_id = json_decode($this->getRequest()->get('tree'), TRUE)['type'];
    $component = Component::load($component_id);
    assert($component !== NULL);
    $component_instance_uuid = $this->getRequest()->get('selected');

    $form = $component->getComponentSource()->buildConfigurationForm($form, $form_state, $component_instance_uuid, $entity, $component->get('settings'));

    $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];
    $form_id = $this->getFormId();
    $form['#attributes']['data-form-id'] = $form_id;
    if ($this->getRequest()->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER) !== NULL) {
      // Add the data-ajax flag and manually add the form ID as pre render
      // callbacks aren't fired during AJAX rendering because the whole form is
      // not rendered, just the returned elements.
      FormIdPreRender::addAjaxAttribute($form, $form_id);
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
