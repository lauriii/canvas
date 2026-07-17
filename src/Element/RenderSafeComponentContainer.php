<?php

declare(strict_types=1);

namespace Drupal\canvas\Element;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a render element that provides safety against an exception.
 */
#[RenderElement(self::PLUGIN_ID)]
final class RenderSafeComponentContainer extends RenderElementBase implements ContainerFactoryPluginInterface {

  public const PLUGIN_ID = 'component_container';

  /**
   * Constructs a new RenderSafeComponentContainer.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected RendererInterface $renderer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#pre_render' => [
        [$this, 'renderComponent'],
      ],
      '#component_context' => '',
      '#component' => [],
      '#is_preview' => FALSE,
      '#component_uuid' => '',
    ];
  }

  public function renderComponent(array $element): array {
    $is_preview = $element['#is_preview'] ?? FALSE;
    $context = new RenderContext();
    $element['#children'] = $this->renderer->executeInRenderContext($context, function () use (&$element, $context, $is_preview) {
      try {
        if ($is_preview) {
          // In a preview, a component crash must be contained no matter where
          // it is thrown — including during placeholder replacement, which
          // core performs only at the render root, after (and outside) this
          // pre_render. Rendering the preview as a self-contained render root
          // pulls that placeholder replacement into this try/catch, so a
          // component whose deferred render crashes (the common case for
          // views, blocks and other cacheable sub-renders) degrades to a
          // fallback instead of taking down all of Canvas. On the live front
          // end we keep the in-context render below, so placeholdering and
          // fine-grained cacheability are preserved there.
          // @see https://www.drupal.org/i/3541431
          $markup = $this->renderer->renderInIsolation($element['#component']);
          // ::renderInIsolation() isolates the component's bubbleable metadata
          // onto #component; forward it to the current render context so
          // cacheability and attached assets are preserved.
          $context->push(BubbleableMetadata::createFromRenderArray($element['#component']));
          return $markup;
        }
        return $this->renderer->render($element['#component']);
      }
      // @todo Remove when https://www.drupal.org/i/2367555 is fixed.
      catch (EnforcedResponseException | FormAjaxException $e) {
        throw $e;
      }
      catch (\Throwable $e) {
        // In this scenario because rendering fails the context isn't updated or
        // bubbled.
        // TRICKY: depending on where an exception is thrown, it is possible
        // that the render context is in a broken state. Typically, the context
        // count at this point should be 1. But in some cases (e.g. when a Twig
        // RuntimeError occurs), it may be >1. This would in turn trigger a
        // "Bubbling failed" assertion error in ::executeInRenderContext(). This
        // defeats the purpose of the RenderSafeComponentContainer! So, unwind
        // the render context to the top level when an exception occurs, so that
        // ::handleComponentException() can safely render a fallback.
        while ($context->count() > 1) {
          $context->update($element);
          $context->bubble();
        }
        $fallback = self::handleComponentException(
          $e,
          $element['#component_context'] ?? '',
          $element['#is_preview'] ?? FALSE,
          $element['#component_uuid'] ?? '',
          CacheableMetadata::createFromRenderArray($element['#component']),
        );
        // Convey to the caller that this component instance render crashed.
        $element['#render_crashed'] = TRUE;
        return $this->renderer->render($fallback);
      }
    });
    unset($element['#component']);
    unset($element['#pre_render']);
    if (!$context->isEmpty()) {
      $context->pop()->applyTo($element);
    }
    return $element;
  }

  public static function handleComponentException(\Throwable $e, string $componentContext, bool $isPreview, string $componentUuid, CacheableMetadata $component_exception_cacheability): array {
    $error_message = \sprintf('%s occurred during rendering of component %s in %s: %s', $e::class, $componentUuid, $componentContext, $e->getMessage());
    \Drupal::logger('canvas')->error($error_message);
    $is_verbose = \Drupal::configFactory()->get('system.logging')->get('error_level') === ERROR_REPORTING_DISPLAY_VERBOSE;
    if ($isPreview) {
      return [
        '#type' => 'container',
        '#attributes' => [
          'data-component-uuid' => $componentUuid,
        ],
        '#markup' => $is_verbose
          ? Markup::create('<pre style="white-space: pre-wrap"><code>' . Xss::filterAdmin($error_message) . '</code></pre>')
          : new TranslatableMarkup('Component failed to render, check logs for more detail.'),
      ];
    }
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'data-component-uuid' => $componentUuid,
      ],
      '#markup' => $is_verbose
        ? Markup::create('<pre style="white-space: pre-wrap"><code>' . Xss::filterAdmin($error_message) . '</code></pre>')
        : new TranslatableMarkup('Oops, something went wrong! Site admins have been notified.'),
    ];
    $component_exception_cacheability->applyTo($build);
    return $build;
  }

}
