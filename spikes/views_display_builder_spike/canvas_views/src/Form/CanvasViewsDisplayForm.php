<?php

declare(strict_types=1);

namespace Drupal\canvas_views\Form;

use Drupal\canvas\Entity\Component;
use Drupal\canvas_views\Entity\CanvasViewsDisplay;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\views\Views;

/**
 * Add/edit form for Canvas views displays.
 *
 * MVP stand-in: the mappings section belongs in the Canvas editor's props
 * panel (bind a prop to one of the list's declared fields); until that
 * client work exists, mappings are edited here.
 */
final class CanvasViewsDisplayForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $entity = $this->entity;
    \assert($entity instanceof CanvasViewsDisplay);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => [CanvasViewsDisplay::class, 'load'],
        'source' => ['label'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $view_options = [];
    foreach (Views::getEnabledViews() as $view_id => $view) {
      $view_options[$view_id] = (string) $view->label();
    }
    \asort($view_options);
    $form['view_id'] = [
      '#type' => 'select',
      '#title' => $this->t('View (the query)'),
      '#options' => $view_options,
      '#default_value' => $entity->getViewId(),
      '#required' => TRUE,
      '#description' => $this->t('The view supplies the result rows and declares its fields through its field handlers. Prefer a view whose only display is the query-only Canvas display.'),
    ];

    if (!$entity->isNew()) {
      $form['design'] = [
        '#type' => 'item',
        '#title' => $this->t('Design'),
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Design this display in Canvas'),
          '#url' => Url::fromUri('base:/canvas/editor/canvas_views_display/' . $entity->id()),
          '#attributes' => ['target' => '_blank'],
        ],
      ];
      $form['mappings'] = $this->buildMappingsElement($entity);
    }

    return $form;
  }

  /**
   * One select per string prop of each component in the tree.
   */
  private function buildMappingsElement(CanvasViewsDisplay $entity): array {
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Field mappings'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t("Bind a template component's prop to one of the view's fields; the bound value replaces the prop's static value on every row."),
    ];
    $view = $entity->getViewExecutable();
    if ($view === NULL) {
      return $element;
    }
    $view->initHandlers();
    $field_options = [];
    foreach ($view->field as $field_id => $handler) {
      $field_options[$field_id] = (string) $handler->adminLabel();
    }
    $mappings = $entity->getMappings();
    $rows = [];
    foreach ($entity->get('component_tree') ?? [] as $item) {
      $component = Component::load($item['component_id']);
      if ($component === NULL) {
        continue;
      }
      $inputs = $item['inputs'] ?? [];
      if (\is_string($inputs)) {
        $inputs = Json::decode($inputs) ?? [];
      }
      foreach (\array_keys($inputs) as $prop_name) {
        $rows[$item['uuid']][$prop_name] = [
          '#type' => 'select',
          '#title' => $this->t('@component: @prop', [
            '@component' => (string) $component->label(),
            '@prop' => (string) $prop_name,
          ]),
          '#options' => $field_options,
          '#empty_option' => $this->t('- Keep the static value -'),
          '#default_value' => $mappings[$item['uuid']][$prop_name] ?? '',
        ];
      }
    }
    $any = $rows !== [];
    $element += $rows;
    if (!$any) {
      $element['empty'] = [
        '#markup' => '<p>' . $this->t('Design the display in Canvas first; its components then appear here for mapping.') . '</p>',
      ];
    }
    if ($field_options === []) {
      $element['no_fields'] = [
        '#markup' => '<p>' . $this->t('The view declares no fields. Add field handlers to the view to make values mappable.') . '</p>',
      ];
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->entity;
    \assert($entity instanceof CanvasViewsDisplay);
    $raw = $form_state->getValue('mappings') ?? [];
    $mappings = [];
    foreach ($raw as $uuid => $props) {
      if (!\is_array($props)) {
        continue;
      }
      $kept = \array_filter($props, static fn (mixed $v): bool => \is_string($v) && $v !== '');
      if ($kept !== []) {
        $mappings[$uuid] = $kept;
      }
    }
    $entity->set('mappings', $mappings);
    $result = $entity->save();
    $this->messenger()->addStatus($this->t('Saved the %label Canvas views display.', ['%label' => $entity->label()]));
    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $result;
  }

}
