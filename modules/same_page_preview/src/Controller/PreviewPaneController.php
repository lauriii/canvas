<?php

namespace Drupal\same_page_preview\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\node\Controller\NodePreviewController;

/**
 * Defines a controller to render a single node in preview in an iframe.
 */
class PreviewPaneController extends NodePreviewController {

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $node_preview, $view_mode_id = 'full', $langcode = NULL) {
    $node_preview->preview_view_mode = $view_mode_id;

    $build['#attached']['library'][] = 'node/drupal.node.preview';

    $build['preview_pane'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'preview-pane',
        ],
        // @todo abstract styles into a css file.
        'style' => 'display: flex; flex-direction: column; height: 100%;',
      ],
    ];

    $build['preview_pane']['preview_controls'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['node-preview-container', 'container-inline'],
      ],
    ];

    $route_match = \Drupal::routeMatch();
    $node = $route_match->getParameter('node_preview');
    $bundle = $node->bundle() ?? '';
    $label = $node->label() ?? t('unsaved content');

    $form = \Drupal::formBuilder()->getForm('\Drupal\same_page_preview\Form\PreviewControlsForm', $node);
    $build['preview_pane']['preview_controls']['view_mode'] = $form;

    // @ben did this
    // Wrapping the iframe directly in the div makes it possible to accurately
    // position elements (such as hover outlines) above it.
    $build['preview_pane']['iframe_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'preview-iframe-wrapper',
      ]

    ];
    $build['preview_pane']['iframe_wrapper']['preview'] = [
      '#type' => 'html_tag',
      '#tag' => 'iframe',
      '#access' => $view_mode_id != DRUPAL_DISABLED && ($node_preview->access('create') || $node_preview->access('update')),
      '#attributes' => [
        'src' => $this->getPreviewUrl($node_preview, $view_mode_id) . '?mode=same_page_preview',
        'allow' => 'fullscreen',
        'loading' => 'eager',
        'class' => ['preview'],
        'name' => 'preview',
        'style' => 'width: 100%; height: 100%;',
        'title' => t('Preview of @bundle : @node_title', ['@bundle' => $bundle, '@node_title' => $label])
      ],
    ];

    // Don't render cache previews.
    unset($build['#cache']);

    return $build;
  }

  /**
   * Generate url for the node preview.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that is going to be previewed.
   * @param string $view_mode_id
   *   The view mode id.
   *
   * @return string
   *   A URL object that will be used to render the preview.
   */
  private function getPreviewUrl(EntityInterface $entity, string $view_mode_id): string {
    // Pass parameters used for preview.
    $route_parameters = [
      'node_preview' => $entity->uuid(),
      'view_mode_id' => $view_mode_id,
    ];

    $route = Url::fromRoute('entity.node.preview', $route_parameters);
    return $route->toString();
  }

  /**
   * Overrides \Drupal\Core\Entity\Controller\EntityViewController::title().
   *
   * Set preview pane title to "Preview" always.
   *
   * @param \Drupal\Core\Entity\EntityInterface|NULL $node_preview
   *   The node preview entity.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   Title of the preview pane.
   */
  public function title(EntityInterface $node_preview = NULL): string|TranslatableMarkup {
    return t('Preview');
  }

}
