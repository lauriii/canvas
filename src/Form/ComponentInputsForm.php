<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Form;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Entity\ComponentInterface;
use Drupal\experience_builder\Storage\ComponentTreeLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Allows editing the prop sources for a component.
 */
final class ComponentInputsForm extends FormBase {

  public const FORM_ID = 'component_inputs_form';

  public function __construct(
    // This must be protected so that DependencySerializationTrait, which is
    // used by the parent class, can access it.
    protected ElementInfoManagerInterface $elementInfoManager,
    // This must be protected so that DependencySerializationTrait, which is
    // used by the parent class, can access it.
    protected ComponentTreeLoader $componentTreeLoader,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $component_tree_loader = $container->get(ComponentTreeLoader::class);
    assert($component_tree_loader instanceof ComponentTreeLoader);

    return new static(
      $container->get(ElementInfoManagerInterface::class),
      $component_tree_loader,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return self::FORM_ID;
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
    $this->componentTreeLoader->load($entity);

    $request = $this->getRequest();
    $tree = $request->get('form_xb_tree');
    [$component_id, $version] = \explode('@', \json_decode($tree, TRUE)['type']);
    if (empty($version)) {
      throw new \UnexpectedValueException('No component version specified.');
    }
    $component = Component::load($component_id);
    \assert($component instanceof ComponentInterface);
    // Load the version of the Component that was instantiated. This is what
    // allows older component instances to continue to use older/previous
    // component-source specific settings, such as the field type/widget for a
    // particular SDC or code component prop.
    $component->loadVersion($version);
    $component_instance_uuid = $request->get('form_xb_selected');

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

    // Prevent form submission while specifying values for component inputs,
    // because changes are saved via Redux instead of a traditional submit.
    // @see ui/src/components/form/inputBehaviors.tsx
    // @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/form#method
    $form['#method'] = 'dialog';

    $inputs = $component->getComponentSource()->clientModelToInput($component_instance_uuid, $component, $client_model);

    $form['#component'] = $component;
    $form['#attributes']['data-form-id'] = 'component_inputs_form';

    $parents = ['xb_component_props', $component_instance_uuid];
    $sub_form = ['#parents' => $parents, '#component' => $component];
    $form['xb_component_props'][$component_instance_uuid] = $this->applyElementParents(
      $component->getComponentSource()->buildConfigurationForm($sub_form, $form_state, $component, $component_instance_uuid, $inputs, $entity, $component->get('settings')),
      $parents
    );
    $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];
    if ($request->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER) !== NULL) {
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

  public function applyElementParents(array $element, array $parents): array {
    foreach (Element::children($element) as $child) {
      if (\array_key_exists('#parents', $element[$child]) && $element[$child]['#parents'] !== $parents) {
        // Ignore elements with existing, but different parents.
        continue;
      }
      $this_parents = $parents;
      if ($this->elementHasInput($element[$child])) {
        $this_parents[] = $child;
      }
      $element[$child]['#parents'] = $this_parents;
      $element[$child] = $this->applyElementParents($element[$child], $this_parents);
    }
    return $element;
  }

  public function elementHasInput(array $element): bool {
    $type = $element['#type'] ?? NULL;
    if ($type === NULL) {
      return FALSE;
    }
    return $this->elementInfoManager->getInfoProperty($type, '#input', FALSE);
  }

}
