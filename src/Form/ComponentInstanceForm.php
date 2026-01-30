<?php

declare(strict_types=1);

namespace Drupal\canvas\Form;

use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Fallback;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Allows editing a component instance.
 *
 * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::buildComponentInstanceForm()
 */
final class ComponentInstanceForm extends FormBase {

  public const FORM_ID = 'component_instance_form';

  public function __construct(
    // These must be protected so that DependencySerializationTrait, which is
    // used by the parent class, can access it.
    protected ComponentTreeLoader $componentTreeLoader,
    protected ThemeHandlerInterface $themeHandler,
    protected ComponentSourceManager $componentSourceManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $component_tree_loader = $container->get(ComponentTreeLoader::class);
    $component_source_manager = $container->get(ComponentSourceManager::class);
    \assert($component_tree_loader instanceof ComponentTreeLoader);
    \assert($component_source_manager instanceof ComponentSourceManager);

    return new static(
      $component_tree_loader,
      $container->get('theme_handler'),
      $component_source_manager,
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
  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $entity = NULL, ?FieldableEntityInterface $preview_entity = NULL): array {
    // ⚠️ This is HORRIBLY HACKY and will go away! ☺️
    // @see \Drupal\canvas\Controller\ApiLayoutController
    if (is_null($entity)) {
      throw new \UnexpectedValueException('The $entity parameter should never be NULL.');
    }
    \assert($entity instanceof FieldableEntityInterface || ($entity instanceof ContentTemplate && $preview_entity instanceof FieldableEntityInterface));
    // @phpstan-ignore-next-line property.notFound
    if (!$this->themeHandler->themeExists('canvas_stark') || !$this->themeHandler->listInfo()['canvas_stark']->status) {
      return [
        '#type' => 'markup',
        '#markup' => $this->t('The canvas_stark theme must be enabled for this form to work.'),
      ];
    }
    $host_entity = $entity instanceof ContentTemplate ? $preview_entity : $entity;

    $request = $this->getRequest();
    $tree = $request->request->getString('form_canvas_tree');
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
    if ($request->query->has('form_canvas_selected')) {
      $component_instance_uuid = $request->query->getString('form_canvas_selected');
    }
    else {
      $component_instance_uuid = $request->request->getString('form_canvas_selected');
    }

    $props = $request->request->getString('form_canvas_props');
    $client_model = json_decode($props, TRUE);

    // Make sure these get sent in subsequent AJAX requests.
    // Note: they're prefixed with `form_` to avoid storage in the UI state.
    // @see ui/src/components/form/inputBehaviors.tsx
    $form['form_canvas_selected'] = [
      '#type' => 'hidden',
      '#value' => $component_instance_uuid,
    ];
    $form['form_canvas_tree'] = [
      '#type' => 'hidden',
      '#value' => $tree,
    ];
    $form['form_canvas_props'] = [
      '#type' => 'hidden',
      '#value' => $props,
    ];

    // Prevent form submission while specifying values for component instance's
    // inputs, because changes are saved via Redux instead of a traditional
    // submit.
    // @see ui/src/components/form/inputBehaviors.tsx
    // @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/form#method
    $form['#method'] = 'dialog';

    $parents = ['canvas_component_props', $component_instance_uuid];
    $sub_form = ['#parents' => $parents, '#tree' => TRUE];
    if (!$component->getComponentSource()->isBroken()) {
      $inputs = $component->getComponentSource()->clientModelToInput($component_instance_uuid, $component, $client_model, $host_entity);
      $instance_form = $component->getComponentSource()->buildComponentInstanceForm($sub_form, $form_state, $component, $component_instance_uuid, $inputs, $entity, $component->get('settings'));
    }
    else {
      $inputs_to_show = match(TRUE) {
        // Common case.
        is_array($client_model) && array_key_exists('resolved', $client_model) => $client_model['resolved'],
        // For robustness.
        // @see https://en.wikipedia.org/wiki/Robustness_principle
        is_array($client_model) => $client_model,
        // Worst case: fall back to stored data, if this component instance had
        // previously been saved. If none, fall back to the empty array.
        default => $this->componentTreeLoader->load($entity)
          ->getComponentTreeItemByUuid($component_instance_uuid)
          ?->getInputs() ?? [],
      };
      $fallback_source = $this->componentSourceManager->createInstance(Fallback::PLUGIN_ID, ['fallback_reason' => $this->t('Component is missing. Fix the component or copy values to a new component.')]);
      \assert($fallback_source instanceof ComponentSourceInterface);
      $instance_form = $fallback_source->buildComponentInstanceForm($sub_form, $form_state, $component, $component_instance_uuid, $inputs_to_show, $entity, $component->get('settings'));
    }

    $form['#attributes']['data-form-id'] = self::FORM_ID;

    $form['canvas_component_props'][$component_instance_uuid] = $instance_form;
    $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];
    $is_ajax = $request->request->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER) ?? $request->query->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER);
    if ($is_ajax !== NULL) {
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
