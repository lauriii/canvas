<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Element;

use Drupal\canvas\Element\AstroIsland;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\canvas_ssr\Cache\NestedRenderCacheContext;
use Drupal\canvas_ssr\JsModules\SiteImportMap;
use Drupal\canvas_ssr\Renderer;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Security\Attribute\TrustedCallback;
use Drupal\Core\Template\Attribute;

/**
 * Puts server-rendered output into Canvas's astro_island element.
 *
 * This is the render-array side of SSR. It renders the island's slots,
 * calls Renderer, and writes the result back. Renderer only deals in plain
 * values.
 *
 * AstroIsland still builds the island in every case but a static island:
 * unchanged for a CSR fallback, and with the server-rendered markup inside
 * for a hydrated island. A static island needs no island wrapper, so that
 * path prints the markup itself.
 *
 * @see \Drupal\canvas\Element\AstroIsland
 * @see \Drupal\canvas_ssr\Renderer
 */
final class AstroIslandSsr extends AstroIsland {

  // Caps islands nested in slots, each of which re-enters the pre-render.
  // ComponentGraph handles cycles between components itself.
  private const MAX_DEPTH = 20;

  /**
   * Twig that prints the server-rendered markup.
   *
   * The '#inner_html' of a hydrated island, or the whole template of a
   * static one.
   */
  private const SSR_HTML_TEMPLATE = '{{ __aie_ssr_html|raw }}';

  /**
   * The framework an island without #framework is built with.
   *
   * It's AstroIsland's default, which Canvas spells out rather than
   * declaring.
   *
   * @see \Drupal\canvas\Element\AstroIsland
   */
  public const string DEFAULT_FRAMEWORK = 'preact';

  /**
   * How many islands deep the current pre-render is nested.
   */
  private static int $depth = 0;

  /**
   * Pre-render callback.
   *
   * @param array $element
   *   The element being processed.
   *
   * @return array<string, mixed>
   *   AstroIsland's output for a CSR fallback. Otherwise the markup,
   *   wrapped in an island if it hydrates.
   *
   * @throws \JsonException
   */
  #[TrustedCallback]
  public static function preRenderIsland(array $element): array {
    // canvas.api.config.list renders a hover preview for every listed
    // component. SSR for each would slow or hang the request, so use CSR.
    $routeMatch = \Drupal::routeMatch();
    if (
      $routeMatch->getRouteName() === 'canvas.api.config.list'
      && \in_array($routeMatch->getRawParameter('canvas_config_entity_type_id'), ['js_component', 'component'], TRUE)
    ) {
      return parent::preRenderIsland($element);
    }

    $component_entity = JavaScriptComponent::load($element['#machine_name']);
    if ($component_entity === NULL) {
      return parent::preRenderIsland($element);
    }

    if (self::$depth >= self::MAX_DEPTH) {
      \Drupal::logger('canvas_ssr')->warning(
        'Skipping SSR for component "{component}" because max nesting depth {depth} was reached.',
        [
          'component' => $element['#machine_name'],
          'depth' => self::$depth,
        ]
      );
      return parent::preRenderIsland($element);
    }

    if (!static::shipsSiteImportMap($element)) {
      \Drupal::logger('canvas_ssr')->debug(
        'SSR bail: component {component} has an import map other than the site\'s.',
        ['component' => $element['#machine_name']]
      );
      return parent::preRenderIsland($element);
    }

    /** @var \Drupal\canvas_ssr\Renderer $ssr_renderer */
    $ssr_renderer = \Drupal::service(Renderer::class);
    \assert($ssr_renderer instanceof Renderer);

    // Render slots inside the depth count, since nested islands are what it
    // counts.
    self::$depth++;
    try {
      $slots = static::renderSlots($element);
      $ssr_result = $ssr_renderer->render(
        $component_entity,
        // Canvas keeps these from the component in the browser too.
        \array_diff_key($element['#props'] ?? [], \array_flip(self::CANVAS_INTERNAL_PROP_KEYS)),
        $slots,
        static::getFramework($element),
        // Only previews render drafts. Auto-saves are keyed by entity, not by
        // editor, so elsewhere they'd leak one editor's draft to everyone.
        (bool) ($element['#preview'] ?? FALSE),
      );
    }
    finally {
      self::$depth--;
    }

    // The markup depends on site and page data, and on fetched data. A CSR
    // fallback depends on what made SSR fail, and may hold only briefly.
    // An island inside another's render always falls back, so each varies
    // by whether it is.
    $cacheability = CacheableMetadata::createFromRenderArray($element);
    $cacheability->addCacheContexts([NestedRenderCacheContext::ID]);
    $cacheability->addCacheableDependency($ssr_result);
    $cacheability->applyTo($element);

    // renderSlots() left its markup in the element, so CSR doesn't render the
    // slots again.
    if ($ssr_result->html === NULL) {
      return parent::preRenderIsland($element);
    }

    if (!$ssr_result->hydrates) {
      $element['inline-template'] = [
        '#type' => 'inline_template',
        '#template' => self::SSR_HTML_TEMPLATE,
        '#context' => ['__aie_ssr_html' => $ssr_result->html],
      ];
      // The markup can still have <astro-slot> wrappers. Astro's CSS keeps
      // them out of the layout.
      $element['#attached']['library'][] = 'canvas/astro.hydration';
      return $element;
    }

    return static::buildHydratedIsland($element, $ssr_result->html, $ssr_result->hydrationData);
  }

  /**
   * Returns the island's framework.
   *
   * The same value picks the framework renderer here and the client-side
   * renderer in the browser.
   *
   * @param array<string, mixed> $element
   *   The render element.
   *
   * @return string
   *   The framework name, defaulting to Canvas's default.
   */
  private static function getFramework(array $element): string {
    $framework = $element['#framework'] ?? NULL;

    return \is_string($framework) ? $framework : self::DEFAULT_FRAMEWORK;
  }

  /**
   * Checks the island ships the site import map.
   *
   * SSR resolves every island with the SSR import map, built from the site
   * import map. Canvas builds each island's map from the site import map
   * too, but it can be altered, or read on either side of an asset library
   * push. An island whose map differs falls back to CSR, with the map it
   * ships.
   *
   * Scopes are left out. They differ per island by design, and SSR finds
   * components through the island's graph instead.
   *
   * @param array<string, mixed> $element
   *   The render element.
   *
   * @return bool
   *   TRUE if the island's top-level imports are the site import map's.
   *
   * @see \Drupal\canvas_ssr\JsModules\SiteImportMap
   */
  private static function shipsSiteImportMap(array $element): bool {
    $site_import_map = \Drupal::service(SiteImportMap::class);
    \assert($site_import_map instanceof SiteImportMap);
    $key = ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS;

    // Each island's map is its own array, so this compares every entry.
    // That's still well under a microsecond.
    return ($element['#import_maps'][$key] ?? NULL) === $site_import_map->imports();
  }

  /**
   * Renders an island's slots once, for whichever path uses them.
   *
   * SSR needs the slots as strings. A hydrated island also prints them in
   * <template>s, and a CSR fallback prints them directly. So each slot's markup
   * replaces its render array, and Canvas doesn't render it again.
   *
   * Metadata still bubbles as usual: the pre-render runs inside the
   * element's render context, so it collects the slots' libraries and
   * cacheability. A static island relies on this, since it prints no
   * slots.
   *
   * @param array<string, mixed> $element
   *   The render element. Rendered slots replace its '#slots' render arrays.
   *
   * @return array<string, string>
   *   Slot HTML keyed by slot name, printed as Canvas prints slots, preview
   *   markers included.
   */
  private static function renderSlots(array &$element): array {
    $slots = $element['#slots'] ?? [];
    if (!\is_array($slots) || $slots === []) {
      return [];
    }

    $renderer = \Drupal::service('renderer');
    \assert($renderer instanceof RendererInterface);
    $props = $element['#props'] ?? [];

    $rendered = [];
    $replacements = $slots;
    foreach ($slots as $slot_name => $slot_content) {
      // A string slot is plain text, which Twig escapes when printed.
      $printable = \is_string($slot_content) ? $slot_content : '';
      if (\is_array($slot_content)) {
        // Keep the MarkupInterface, so the markup isn't escaped or filtered
        // again.
        $printable = $renderer->render($slot_content);
        $replacements[$slot_name] = ['#markup' => $printable];
      }
      $rendered[$slot_name] = static::printSlotForRunner($slot_name, $printable, $props);
    }
    $element['#slots'] = $replacements;

    return $rendered;
  }

  /**
   * Prints one slot the way Canvas does, for the runner.
   *
   * In previews, the Canvas UI finds slots by `<!-- canvas-slot-start-... -->`
   * comments. Canvas's Twig visitor wraps every `{{ variable }}` print in a
   * CanvasWrapperNode, which decides whether to add them. So printing the
   * slot through Twig adds the markers, in whatever format Canvas uses, to
   * the string the runner gets.
   *
   * @param string|int $slot_name
   *   The slot name, also used in the marker.
   * @param string|\Drupal\Component\Render\MarkupInterface $slot_content
   *   The slot's rendered markup, or a plain string to be escaped.
   * @param array<string, mixed> $props
   *   The island's props. CanvasWrapperNode reads Canvas's internal props to
   *   decide whether to add markers.
   *
   * @return string
   *   The printed slot, with any markers.
   *
   * @see \Drupal\canvas\Twig\CanvasPropVisitor
   * @see \Drupal\canvas\Twig\CanvasWrapperNode
   */
  private static function printSlotForRunner(
    string|int $slot_name,
    string|MarkupInterface $slot_content,
    array $props,
  ): string {
    // Twig can't print a name like "my-slot" as a variable: it would read it
    // as subtraction. Canvas's own template can't either, so there's no
    // marker to lose. Print it directly.
    if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $slot_name) !== 1) {
      return $slot_content instanceof MarkupInterface
        ? (string) $slot_content
        : Html::escape($slot_content);
    }

    $renderer = \Drupal::service('renderer');
    \assert($renderer instanceof RendererInterface);

    // In isolation: the slot's metadata already bubbled when it rendered.
    $build = [
      '#type' => 'inline_template',
      '#template' => \sprintf('{{ %s }}', $slot_name),
      '#context' => [(string) $slot_name => $slot_content]
      + \array_intersect_key($props, \array_flip(self::CANVAS_INTERNAL_PROP_KEYS)),
    ];

    return (string) $renderer->renderInIsolation($build);
  }

  /**
   * Wraps server-rendered markup in Canvas's island.
   *
   * AstroIsland builds the wrapper. SSR only changes what goes inside it and
   * how the island hydrates.
   *
   * @param array<string, mixed> $element
   *   The render element.
   * @param string $ssr_html
   *   The server-rendered HTML.
   * @param string|null $hydration_data
   *   The island's hydration data, encoded by devalue, or NULL if it has none.
   *
   * @return array<string, mixed>
   *   The island's render array.
   *
   * @throws \JsonException
   */
  private static function buildHydratedIsland(array $element, string $ssr_html, ?string $hydration_data): array {
    // Set the id before AstroIsland would generate one, so the prefix below
    // matches it.
    $element['#uuid'] ??= \Drupal::service(UuidInterface::class)->generate();

    // Canvas merges these over its own, so both keys win.
    $attributes = $element['#attributes'] ?? [];
    $element['#attributes'] = ($attributes instanceof Attribute ? $attributes : new Attribute($attributes))
      // Hydrate the existing markup on load.
      ->setAttribute('client', 'load')
      // The client's useId() prefix.
      ->setAttribute('prefix', \substr((string) $element['#uuid'], 0, 8));
    // Canvas's island client renderer gives each library its part before
    // hydrating, so the first render in the browser has the state the markup
    // was rendered with.
    if ($hydration_data !== NULL) {
      $element['#attributes']->setAttribute('hydration-data', $hydration_data);
    }

    // Print the markup inside the island. It goes in as Twig context, not
    // as part of the template.
    $element['#inner_html'] = self::SSR_HTML_TEMPLATE;
    $element = parent::preRenderIsland($element);
    $element['inline-template']['#context']['__aie_ssr_html'] = $ssr_html;

    return $element;
  }

}
