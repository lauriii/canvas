<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Plugin\DisplayVariant;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Display\Attribute\PageDisplayVariant;
use Drupal\Core\Display\PageVariantInterface;
use Drupal\Core\Display\VariantBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Renders an embedded headless preview with Drupal status messages above it.
 */
#[PageDisplayVariant(
  id: self::PLUGIN_ID,
  admin_label: new TranslatableMarkup('Canvas headless preview'),
)]
final class EmbeddedHeadlessPreviewPageVariant extends VariantBase implements PageVariantInterface {

  public const string PLUGIN_ID = 'canvas_headless_preview';

  private bool $hasNodePreviewControls = FALSE;

  /**
   * {@inheritdoc}
   */
  public function setMainContent(array $main_content): static {
    // On Drupal < 11.4, only the library is attached here and the controls are
    // added later through hook_page_top().
    $this->hasNodePreviewControls = isset($main_content['#attached']['page_top']['node_preview']) ||
      \in_array('node/drupal.node.preview', $main_content['#attached']['library'] ?? [], TRUE);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle($title): static {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $can_manage_frontends = $this->configuration['can_manage_frontends'];
    \assert(\is_bool($can_manage_frontends));
    $settings = [
      'contentApiPath' => $this->configuration['content_api_path'],
      'drupalBasePath' => $this->configuration['drupal_base_path'],
      'frontendBasePath' => $this->configuration['frontend_base_path'],
      'frontendOrigin' => $this->configuration['frontend_origin'],
      'previewUrl' => $this->configuration['preview_url'],
    ];
    foreach ($settings as $setting) {
      \assert(\is_string($setting));
    }

    $build = [
      '#theme' => 'canvas_page_variant',
      '#content' => [
        'canvas_headless_preview' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['canvas-headless-preview'],
          ],
          'messages' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['canvas-headless-preview__messages'],
            ],
            'status' => [
              '#type' => 'status_messages',
              '#include_fallback' => TRUE,
            ],
          ],
          'iframe' => [
            '#type' => 'html_tag',
            '#tag' => 'iframe',
            '#value' => '',
            '#attributes' => [
              'class' => ['canvas-headless-preview__frame'],
              'src' => 'about:blank',
              'title' => $this->t('Headless content preview'),
            ],
          ],
          'error' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['canvas-headless-preview__error'],
              'hidden' => 'hidden',
              'role' => 'alert',
            ],
            'content' => [
              '#type' => 'container',
              '#attributes' => [
                'class' => [
                  'canvas-headless-preview__error-content',
                  'messages',
                  'messages--error',
                ],
              ],
              'header' => [
                '#type' => 'container',
                '#attributes' => [
                  'class' => ['messages__header'],
                ],
                'title' => [
                  '#type' => 'html_tag',
                  '#tag' => 'h2',
                  '#value' => $this->t('The headless frontend is not responding'),
                  '#attributes' => [
                    'class' => ['messages__title'],
                  ],
                ],
              ],
              'body' => [
                '#type' => 'container',
                '#attributes' => [
                  'class' => ['messages__content'],
                ],
                'message' => [
                  '#type' => 'html_tag',
                  '#tag' => 'p',
                  '#value' => $this->t('Check that the configured frontend is running and that this browser can reach it.'),
                ],
              ],
            ],
          ],
          '#attached' => [
            'library' => ['canvas_headless/headless.preview'],
            'drupalSettings' => [
              'canvas' => [
                'headlessPreview' => $settings,
              ],
            ],
          ],
        ],
      ],
    ];
    if ($this->hasNodePreviewControls) {
      // The original main content is discarded, but its page_top form remains.
      $build['#attached']['library'][] = 'node/drupal.node.preview';
    }
    if ($can_manage_frontends) {
      $build['#content']['canvas_headless_preview']['error']['content']['body']['manage'] = [
        '#type' => 'link',
        '#title' => $this->t('Manage headless frontends'),
        '#url' => Url::fromUri('base:canvas/headless/'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];
    }
    CacheableMetadata::createFromRenderArray($build)
      ->addCacheableDependency($this)
      ->applyTo($build);
    return $build;
  }

}
