<?php

namespace Drupal\experience_builder\Form;

// ⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️
// ⚠️ 🔨🧹  This file will be thrown away. Do not review in detail, ever.   🧹🔨 ⚠️
// ⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️⚠️

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays a ComponentTreeItem field based on the props of an SDC.
 *
 * As the naming suggests, something much more elegant should replace this.
 *
 * @todo Remove in https://www.drupal.org/project/experience_builder/issues/3461422
 */
final class DummyComponentFieldForm extends FormBase {

  /**
   * Stores the tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The node type storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $nodeTypeStorage;

  /**
   * Constructs a new EditFieldForm.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityStorageInterface $node_type_storage
   *   The node type storage.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, ModuleHandlerInterface $module_handler, EntityStorageInterface $node_type_storage) {
    $this->moduleHandler = $module_handler;
    $this->nodeTypeStorage = $node_type_storage;
    $this->tempStoreFactory = $temp_store_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
      $container->get('module_handler'),
      $container->get('entity_type.manager')->getStorage('node_type')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'component_field_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?FieldableEntityInterface $entity = NULL, string $field_name = 'field_xb_demo'): array {
    $tree = $this->getRequest()->get('tree');
    $props = $this->getRequest()->get('props');
    $tree = is_array($tree) ? $tree : Json::decode($tree);
    $props = is_array($props) ? $tree : Json::decode($props);

    // Transform the tree constructed by the client to the tree structure
    // expected by the server.
    // @todo Refactor. We won't keep using TwoTerribleTextAreasWidget anyway.
    foreach ($tree as &$placed_component) {
      $placed_component['component'] = $placed_component['type'];
      unset($placed_component['type']);
      unset($placed_component['nodeType']);
    }
    $tree = [ComponentTreeStructure::ROOT_UUID => $tree];

    if (empty($entity)) {
      $entity = Node::create([
        'title' => 'temp',
        'type' => 'article',
      ]);

      $entity->set($field_name, [
        'tree' => Json::encode($tree),
        'props' => Json::encode($props),
      ]);

      $form_state->set('entity', $entity);
      $form_state->set('field_name', $field_name);
      // @todo Implement buildForm() method.
      $display = EntityFormDisplay::collectRenderDisplay($entity, 'default');
      foreach ($display->getComponents() as $name => $options) {
        if ($name != $field_name) {
          $display->removeComponent($name);
        }
      }
      $form_state->set('form_display', $display);
    }

    $form_state->get('form_display')->buildForm($entity, $form, $form_state);

    // Add a dummy changed timestamp field to attach form errors to.
    if ($entity instanceof EntityChangedInterface) {
      $form['changed_field'] = [
        '#type' => 'hidden',
        '#value' => $entity->getChangedTime(),
      ];
    }

    // Add a submit button. Give it a class for easy JavaScript targeting.
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#attributes' => ['class' => ['dummy-form-submit']],
    ];

    // Use the non-inline form error display for Quick Edit forms, because in
    // this case the errors are already near the form element.
    $form['#disable_inline_form_errors'] = TRUE;

    // Simplify it for optimal in-place use.
    $this->simplify($form, $form_state);

    return $form;
  }

  /**
   * Simplifies the field edit form for in-place editing.
   *
   * This function:
   * - Hides the field label inside the form, because JavaScript displays it
   *   outside the form.
   * - Adjusts textarea elements to fit their content.
   *
   * @param array &$form
   *   A reference to an associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  protected function simplify(array &$form, FormStateInterface $form_state): void {
    $field_name = $form_state->get('field_name');
    $widget_element =& $form[$field_name]['widget'];

    if ($field_name === 'field_xb_demo') {
      unset($form[$field_name]['widget'][0]['tree']);
      unset($form[$field_name]['widget'][0]['props']);
      $form[$field_name]['widget'][0]['props_editor']['#type'] = 'container';
    }

    // Hide the field label from displaying within the form, because JavaScript
    // displays the equivalent label that was provided within an HTML data
    // attribute of the field's display element outside of the form. Do this for
    // widgets without child elements (like Option widgets) as well as for ones
    // with per-delta elements. Skip single checkboxes, because their title is
    // key to their UI. Also skip widgets with multiple subelements, because in
    // that case, per-element labeling is informative.
    $num_children = count(Element::children($widget_element));
    if ($num_children == 0 && $widget_element['#type'] != 'checkbox') {
      $widget_element['#title_display'] = 'invisible';
    }
    if ($num_children == 1 && isset($widget_element[0]['value'])) {
      // @todo While most widgets name their primary element 'value', not all
      //   do, so generalize this.
      $widget_element[0]['value']['#title_display'] = 'invisible';
    }

    // Adjust textarea elements to fit their content.
    if (isset($widget_element[0]['value']['#type']) && $widget_element[0]['value']['#type'] == 'textarea') {
      $lines = count(explode("\n", $widget_element[0]['value']['#default_value']));
      $widget_element[0]['value']['#rows'] = $lines + 1;
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // @todo implement submitForm() method.
  }

}
