<?php

declare(strict_types=1);

namespace Drupal\canvas_views_poc\Plugin\views\row;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Pattern;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\row\RowPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders each Views row as a Canvas component, fed by Views field values.
 *
 * This deliberately does not go through content templates. A content template
 * renders one content entity in one view mode, so it only covers views whose
 * rows are entities. This row plugin covers the rest: rows built from field
 * handlers, aggregated rows, and rows over base tables that have no entity at
 * all. It reads values from the display's field handlers, never from
 * $row->_entity.
 *
 * @ingroup views_row_plugins
 *
 * @ViewsRow(
 *   id = "canvas_component",
 *   title = @Translation("Canvas component"),
 *   help = @Translation("Renders each row as a Canvas component, with its props bound to Views fields."),
 *   theme = "views_view_row_canvas_component",
 *   register_theme = FALSE
 * )
 */
final class CanvasComponentRow extends RowPluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesFields = TRUE;

  /**
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly UuidInterface $uuidGenerator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(EntityTypeManagerInterface::class),
      $container->get('uuid'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The options definition.
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['component_id'] = ['default' => ''];
    $options['prop_map'] = ['default' => []];
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed $form
   *   The options form. Always an array; the parent interface leaves it
   *   untyped, and narrowing it here violates contravariance.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);

    $components = $this->entityTypeManager
      ->getStorage(Component::ENTITY_TYPE_ID)
      ->loadByProperties(['status' => TRUE]);
    $component_options = [];
    foreach ($components as $component) {
      \assert($component instanceof Component);
      $component_options[$component->id()] = $component->label();
    }
    \asort($component_options);

    $form['component_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Component'),
      '#options' => $component_options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $this->options['component_id'],
      '#description' => $this->t('Save the display, then reopen this form to map the component props to Views fields.'),
    ];

    $props = $this->getComponentProps();
    if ($props === []) {
      return;
    }

    $field_options = [];
    foreach ($this->displayHandler->getHandlers('field') as $id => $handler) {
      $field_options[$id] = $handler->adminLabel();
    }

    $form['prop_map'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Prop bindings'),
      '#tree' => TRUE,
    ];
    foreach ($props as $prop_name) {
      $form['prop_map'][$prop_name] = [
        '#type' => 'select',
        '#title' => $prop_name,
        '#options' => $field_options,
        '#empty_option' => $this->t('- Use the component default -'),
        '#default_value' => $this->options['prop_map'][$prop_name] ?? '',
      ];
    }
  }

  /**
   * Returns the prop names of the selected component.
   *
   * @return string[]
   *   The prop names, or an empty array if no usable component is selected.
   */
  private function getComponentProps(): array {
    $component = $this->loadComponent();
    if ($component === NULL) {
      return [];
    }
    return \array_keys($component->getComponentSource()->getDefaultExplicitInput());
  }

  /**
   * Loads the selected component.
   */
  private function loadComponent(): ?Component {
    $id = $this->options['component_id'];
    if ($id === '') {
      return NULL;
    }
    $component = $this->entityTypeManager
      ->getStorage(Component::ENTITY_TYPE_ID)
      ->load($id);
    return $component instanceof Component ? $component : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The row's render array.
   */
  public function render($row): array {
    \assert($row instanceof ResultRow);
    $component = $this->loadComponent();
    if ($component === NULL) {
      return [];
    }

    $source = $component->getComponentSource();
    $inputs = $source->getDefaultExplicitInput();

    // Replace the static default of each bound prop with this row's value. The
    // defaults are already valid static prop sources, so only the stored value
    // changes and the tree stays valid without any new prop source type.
    // StylePluginBase::getField() is used instead of calling the field
    // handler's advancedRender() directly, because it guards against a missing
    // render context (StylePluginBase::renderFields()), which the Views UI
    // live preview renders without.
    foreach ($this->options['prop_map'] as $prop_name => $field_id) {
      if ($field_id === '' || !isset($inputs[$prop_name], $this->view->field[$field_id])) {
        continue;
      }
      $rendered = $this->view->style_plugin?->getField($row->index, $field_id);
      $inputs[$prop_name]['value'] = \trim(\strip_tags((string) $rendered));
    }

    $tree = [
      [
        'uuid' => $this->uuidGenerator->generate(),
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => $inputs,
      ],
    ];

    // An unsaved Pattern is used purely as a render vehicle, because rendering
    // a component tree requires an entity that carries one. Canvas has no
    // supported entry point for rendering a tree that a module assembled, which
    // is the one friction point this proof of concept ran into.
    $vehicle = Pattern::create([
      'id' => 'canvas_views_poc_row',
      'label' => 'Canvas Views POC row',
      'component_tree' => $tree,
    ]);

    return $vehicle->getComponentTree()->toRenderable($vehicle, FALSE);
  }

}
