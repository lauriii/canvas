<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\GlobalImports;
use Drupal\canvas_ssr\Renderer;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a component through the astro_island element, as a page does.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\ComponentFixtureTrait
 */
trait RenderTrait {

  /**
   * The attribute on an island that fell back to CSR.
   */
  protected const CLIENT_ONLY_ATTRIBUTE = 'client="only"';

  /**
   * Renders a component.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The component.
   * @param array<string, mixed> $props
   *   The island's props.
   * @param array<string, mixed> $slots
   *   The island's slots.
   * @param string|null $uuid
   *   The island's UUID.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Set to the island's bubbled cacheability.
   * @param string $framework
   *   The island's framework.
   * @param bool $preview
   *   Whether this is a Canvas preview, where drafts are rendered.
   * @param array<string, mixed>|null $import_maps
   *   The island's import map. NULL for the one Canvas builds.
   *
   * @param-out \Drupal\Core\Cache\CacheableMetadata $cacheability
   *
   * @return string
   *   The HTML.
   */
  protected function renderComponent(
    JavaScriptComponent $component,
    array $props = [],
    array $slots = [],
    ?string $uuid = NULL,
    ?CacheableMetadata &$cacheability = NULL,
    string $framework = 'preact',
    bool $preview = FALSE,
    ?array $import_maps = NULL,
  ): string {
    $global_imports = $this->container->get(GlobalImports::class);
    \assert($global_imports instanceof GlobalImports);

    $build = [
      '#type' => 'astro_island',
      '#uuid' => $uuid,
      '#name' => (string) $component->label(),
      '#machine_name' => $component->id(),
      '#component_url' => '/canvas/api/v0/auto-saves/js/js_component/' . $component->id(),
      '#props' => $props,
      '#slots' => $slots,
      '#framework' => $framework,
      '#preview' => $preview,
      // As JsComponent::renderComponent() does.
      '#import_maps' => $import_maps ?? $global_imports->getImportMap(),
    ];

    $renderer = $this->container->get('renderer');
    \assert($renderer instanceof RendererInterface);
    $output = (string) $renderer->renderInIsolation($build);
    $cacheability = CacheableMetadata::createFromRenderArray($build);
    return $output;
  }

  /**
   * Returns the SSR renderer, to render an island without the element.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface|null $container
   *   The container to take it from. NULL for the test's.
   *
   * @return \Drupal\canvas_ssr\Renderer
   *   The renderer.
   */
  protected function ssrRenderer(?ContainerInterface $container = NULL): Renderer {
    $renderer = ($container ?? $this->container)->get(Renderer::class);
    \assert($renderer instanceof Renderer);

    return $renderer;
  }

  /**
   * Returns the component's markup from a render's output.
   *
   * A hydrated island's wrapper and scripts are stripped. A static island
   * renders plain markup.
   */
  protected function extractSsrMarkup(string $output): string {
    if (\preg_match('/<canvas-island\b[^>]*>(.*?)<\/canvas-island>/s', $output, $match) === 1) {
      $inner = \preg_replace('/<script type="module"[^>]*><\/script>/', '', $match[1]);
      \assert(\is_string($inner));
      return \trim($inner);
    }

    return \trim($output);
  }

}
