<?php

declare(strict_types=1);

namespace Drupal\canvas_views_spike\Plugin\views\row;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\row\RowPluginBase;

/**
 * Renders a Views row through an explicitly chosen Canvas content template.
 *
 * Spike only: proves a contrib module can drive Canvas rendering per row using
 * today's public API, without any change to Canvas.
 *
 * @ingroup views_row_plugins
 *
 * @ViewsRow(
 *   id = "canvas_template",
 *   title = @Translation("Canvas content template"),
 *   help = @Translation("Renders each row using a Canvas content template."),
 *   theme = "views_view_row_canvas_template",
 *   register_theme = FALSE
 * )
 */
final class CanvasTemplateRow extends RowPluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesFields = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['template_id'] = ['default' => ''];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    $templates = \Drupal::entityTypeManager()
      ->getStorage(ContentTemplate::ENTITY_TYPE_ID)
      ->loadMultiple();
    $form['template_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Content template'),
      '#options' => \array_map(static fn ($t) => $t->id(), $templates),
      '#default_value' => $this->options['template_id'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render($row): array {
    $entity = $row->_entity ?? NULL;
    if (!$entity instanceof FieldableEntityInterface) {
      // The wall: a Views row is not guaranteed to carry an entity at all.
      return ['#markup' => ''];
    }
    $template = \Drupal::entityTypeManager()
      ->getStorage(ContentTemplate::ENTITY_TYPE_ID)
      ->load($this->options['template_id']);
    if (!$template instanceof ContentTemplate) {
      return ['#markup' => ''];
    }
    return $template->build($entity);
  }

}
