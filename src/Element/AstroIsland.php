<?php

declare(strict_types=1);

namespace Drupal\canvas\Element;

use Drupal\canvas\GlobalImports;
use Drupal\Component\Utility\Html;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\Template\Attribute;

/**
 * Provides a render element for an Astro island web component.
 *
 * @see https://docs.astro.build/en/concepts/islands/
 *
 * Properties:
 * - #uuid: A unique ID for this island.
 * - #component_url: URL of component to hydrate. This will be a JavaScript
 *   file.
 * - #name: A name for the component.
 * - #machine_name: Machine name of the source component.
 * - #props: Array of properties for the JavaScript component where the keys are
 *   the prop names and the values are the prop values. Only values that can be
 *   serialized to JSON are supported - such as scalar values or objects that
 *   implement \JsonSerializable.
 *   #slots: Array of child slots for the JavaScript component. The slots are
 *   keyed by their name. In the case of frameworks like React and Preact that
 *   only support a single child slot, this slot should be named 'default'. The
 *   values represent the content to be rendered into the slot and should be
 *   valid render arrays or a string. String values will be treated as plain
 *   text.
 * - #preview: A boolean to indicate whether the rendered component should use
 *   the draft version. Defaults to FALSE.
 * - #framework: Name of the framework to use when rehydrating. Only 'preact' is
 *   supported at present.
 * - #import_maps: Keyed array of importmap entries where the keys are the bare
 *   import names and the values are the resolved URL.
 * - #inner_html: Twig template fragment rendered inside the canvas-island
 *   wrapper, before slot templates. Defaults to the script tags that load the
 *   renderer and component bundles.
 * - #attributes: Optional extra attributes to merge into the canvas-island
 *   wrapper. Overrides the defaults when keys collide.
 *
 * @see \Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor::processAttachments
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script/type/importmap
 *
 * Usage example:
 * @code
 * $build['recital_final'] = [
 *   '#type' => 'astro_island',
 *   '#uuid' => 'da6bf2a2-3d4b-42a2-bb05-03a0e33a2d79',
 *   '#name' => 'Jazz Hands (elite)',
 *   '#machine_name' => 'jazz_hands_elite',
 *   '#component_url' => '/uri/to/jazz-hands-elite.js',
 *   '#props' => [
 *     'oscillation_size' => 'extremely_animated',
 *     'oscillations' => 12,
 *     'finale_routine' => ['jump:large', 'splits:full', 'fist_pump'],
 *    ],
 *   '#slots' => [
 *     'default' => "We're off to the regionals Janet!',
 *    ],
 *   '#import_maps' => [
 *     'preact' => '/path/to/preact.js',
 *     'emoji' => '/path/to/emoji.js',
 *   ],
 * ];
 * @endcode
 */
#[RenderElement(self::PLUGIN_ID)]
class AstroIsland extends RenderElementBase {

  public const PLUGIN_ID = 'astro_island';

  /**
   * Prop keys reserved for Canvas.
   */
  protected const CANVAS_INTERNAL_PROP_KEYS = ['canvas_uuid', 'canvas_slot_ids', 'canvas_is_preview'];

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#pre_render' => [
        [static::class, 'preRenderIsland'],
      ],
      '#machine_name' => NULL,
      '#slots' => [],
      '#props' => [],
      '#framework' => 'preact',
      '#preview' => FALSE,

      // Reduce layout shift by blocking further document rendering until the
      // renderer-url and component-url scripts are loaded, so that fetching
      // them doesn't add delay between a rendering with the island blank and
      // the hydrated rendering. This doesn't eliminate layout shift entirely,
      // because with Astro's client="only" directive, Astro waits until the
      // entire page is loaded before hydrating islands.
      // @todo Investigate if it's possible to hydrate islands immediately
      //   after the <canvas-island> element is parsed rather than on page load.
      '#inner_html' => '<script type="module" src="{{ __aie_renderer }}" blocking="render"></script>'
      . '<script type="module" src="{{ __aie_component_url }}" blocking="render"></script>',
    ];
  }

  /**
   * Pre-render callback.
   */
  public static function preRenderIsland(array $element): array {
    $component_url = $element['#component_url'] ?? NULL;
    if ($component_url === NULL) {
      return ['#plain_text' => \sprintf('You must pass a #component_url for an element of #type %s', self::PLUGIN_ID)];
    }

    $component_name = $element['#name'] ?? NULL;
    if ($component_name === NULL) {
      return ['#plain_text' => \sprintf('You must pass a #name for an element of #type %s', self::PLUGIN_ID)];
    }

    $renderer_url = static::buildRendererUrl();
    $attributes = new Attribute([
      'uid' => $element['#uuid'] ?? \Drupal::service(UuidInterface::class)->generate(),
      'component-url' => $component_url,
      'component-export' => 'default',
      'renderer-url' => $renderer_url,
      'props' => \json_encode(static::buildProps($element), JSON_THROW_ON_ERROR),
      'ssr' => '',
      'client' => 'only',
      'opts' => \json_encode([
        'name' => $component_name,
        'value' => $element['#framework'] ?? 'preact',
      ], JSON_THROW_ON_ERROR),
    ]);
    if (!empty($element['#slots'])) {
      $attributes['await-children'] = '';
    }
    if (isset($element['#attributes'])) {
      $attributes->merge($element['#attributes']);
    }

    $element['#attached']['library'][] = 'canvas/astro.hydration';

    // Return this as a new child element so that process callbacks are executed
    // for the new render array.
    $element['inline-template'] = [
      '#type' => 'inline_template',
      '#template' => static::generateTemplate(
        \array_keys($element['#slots'] ?? []),
        $element['#inner_html'] ?? '',
      ),
      '#context' => [
        // Prefix all context variables with __aie to avoid collisions with
        // slots.
        '__aie_attributes' => $attributes,
        '__aie_renderer' => $renderer_url,
        '__aie_component_url' => $component_url,
        // Add slots as named variables so the point they're printed can be
        // wrapped by CanvasWrapperNode and any passed meta props to enable
        // CanvasWrapperNode to wrap slots with HTML comments.
      ] + \array_map(static fn(array|string $slot) => \is_array($slot) ? $slot : ['#plain_text' => $slot], $element['#slots'] ?? []) +
      \array_intersect_key($element['#props'] ?? [], \array_flip(self::CANVAS_INTERNAL_PROP_KEYS)),
    ];

    // Scope any import-maps.
    if (\array_key_exists('#import_maps', $element)) {
      // Convert these to attachments that can be processed.
      // @see \Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor::processAttachments
      $element['#attached']['import_maps'] = $element['#import_maps'];
    }
    $element['#attached']['html_head_link'][] = [
      [
        'rel' => 'modulepreload',
        'fetchpriority' => 'high',
        'href' => $component_url,
      ],
    ];
    return $element;
  }

  /**
   * Builds the renderer URL with cache-busting query string.
   */
  protected static function buildRendererUrl(): string {
    $client = \Drupal::service(LibraryDiscoveryInterface::class)->getLibraryByName('canvas', 'astro.client');
    \assert(isset($client['js'][0]['data']) && count($client['js']) === 1);
    // We handle manually adding this library, so we need to handle the cache
    // busting too.
    return base_path() . $client['js'][0]['data'] . '?' . \Drupal::service(GlobalImports::class)->getQueryString();
  }

  /**
   * Builds the props payload that gets JSON-encoded into the props="" attr.
   *
   * @param array<string, mixed> $element
   *   The render element.
   *
   * @return array<string, array{string, mixed}>|\stdClass
   *   Mapped props ready for JSON encoding. \stdClass when empty so the
   *   encoded value is {} rather than [].
   */
  protected static function buildProps(array $element): array|\stdClass {
    $mapped = \array_map(
      static fn(mixed $prop_value): array => ['raw', $prop_value],
      \array_diff_key($element['#props'] ?? [], \array_flip(self::CANVAS_INTERNAL_PROP_KEYS))
    );
    return \count($mapped) === 0 ? new \stdClass() : $mapped;
  }

  /**
   * Builds inline template.
   *
   * @param string[]|int[] $slot_names
   *   Slot names.
   * @param string $inner_html
   *   Twig template fragment spliced inside the canvas-island wrapper before
   *   the slot templates.
   *
   * @return string
   */
  protected static function generateTemplate(array $slot_names, string $inner_html): string {
    $template = '<canvas-island{{ __aie_attributes }}>' . $inner_html;
    foreach ($slot_names as $slot_name) {
      // Prevent XSS via malicious render array.
      $escaped_slot_name = Html::escape((string) $slot_name);
      if ($slot_name === 'default' || $slot_name === 'children') {
        $template .= \sprintf('<template data-astro-template>{{ %s }}</template>', $escaped_slot_name);
        continue;
      }
      $template .= \sprintf('<template data-astro-template="%s">{{ %s }}</template>', $escaped_slot_name, $escaped_slot_name);
    }
    $template .= '</canvas-island>';
    return $template;
  }

}
