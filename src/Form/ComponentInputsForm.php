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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\ConstraintViolationList;

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

    $tree = $this->getRequest()->get('form_xb_tree');
    $component_id = json_decode($tree, TRUE)['type'];
    $component = Component::load($component_id);
    assert($component !== NULL);
    $component_instance_uuid = $this->getRequest()->get('form_xb_selected');

    $request = $this->requestStack->getCurrentRequest();
    \assert($request instanceof Request);
    $props = $request->get('form_xb_props');
    $client_model = json_decode($props, TRUE);

    // Make sure these get sent in subsequent AJAX requests.
    // Note: they're prefixed with `form_` to avoid storage in the UI state.
    // @see ui/src/components/form/inputBehaviors.tsx
    $form['form_xb_selected'] = [
      '#type' => 'hidden',
      '#value' => $component_instance_uuid,
    ];
    $form['form_xb_tree'] = [
      '#type' => 'hidden',
      '#value' => $tree,
    ];
    $form['form_xb_props'] = [
      '#type' => 'hidden',
      '#value' => $props,
    ];

    $violations = new ConstraintViolationList();
    $inputs = $component->getComponentSource()->clientModelToInput($component_instance_uuid, $component, $client_model, $violations);
    // Don't complain about invalid received values except to developers.
    // @see https://en.wikipedia.org/wiki/Robustness_principle
    assert($violations->count() === 0);

    $form['#component'] = $component;

    $form = $component->getComponentSource()->buildConfigurationForm($form, $form_state, $component_instance_uuid, $inputs, $entity, $component->get('settings'));
    assert(isset($form['#attributes']['data-form-id']));
    $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];
    if ($this->getRequest()->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER) !== NULL) {
      // Add the data-ajax flag and manually add the form ID as pre render
      // callbacks aren't fired during AJAX rendering because the whole form is
      // not rendered, just the returned elements.
      FormIdPreRender::addAjaxAttribute($form, $form['#attributes']['data-form-id']);
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
