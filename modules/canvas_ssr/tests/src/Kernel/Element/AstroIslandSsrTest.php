<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Element;

use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\Core\Render\RendererInterface;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how the island element handles slots.
 *
 * RendererTest covers the rest of what an island carries. This covers how a
 * slot is printed, how often, what markers it gets, and what it leaves on the
 * island.
 *
 * @legacy-covers \Drupal\canvas_ssr\Element\AstroIslandSsr
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\RendererTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class AstroIslandSsrTest extends CanvasSsrKernelTestBase {

  /**
   * The island UUID that slot markers include.
   */
  private const string ISLAND_UUID = '5f4af5e8-337e-4aea-a5fe-15b0b9570cd3';

  /**
   * A component that prints whatever arrives in its default slot.
   */
  private const string SLOT_HOST_JS = 'import { h } from "preact"; export default function Root(props){ return h("div", { className: "slot-host" }, props.default ?? null); }';

  /**
   * The same component as a client boundary, so its island hydrates.
   */
  private const string HYDRATED_SLOT_HOST_JS = '"use client"; import { h } from "preact"; export default function Root(props){ return h("div", { className: "slot-host" }, props.default ?? null); }';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
  }

  /**
   * Tests how an island prints its slots.
   *
   * The scenarios share one site install.
   */
  public function testSlots(): void {
    $this->assertScenarios([
      ...$this->slotMarkerScenarios(),
      ...$this->slotRenderScenarios(),
      ...$this->slotBubblingScenarios(),
    ]);
  }

  /**
   * Scenarios for how slot content and markers appear in SSR output.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function slotMarkerScenarios(): array {
    // Shared by the scenarios below. None of them change it.
    $slot_host = $this->createComponent('root renders default slot', self::SLOT_HOST_JS);

    return [
      'a string prop is printed as text, not adopted as a slot' => function (): void {
        $root = $this->createComponent(
          'root renders title prop as text',
          'import { h } from "preact"; export default function Root(props){ return h("div", { className: "title-host" }, props.title ?? null); }',
        );

        $output = $this->renderComponent($root, props: ['title' => '<strong>Bold title</strong>']);

        self::assertStringContainsString('&lt;strong>', $output);
        self::assertStringContainsString('Bold title', $output);
        self::assertStringContainsString('&lt;/strong>', $output);
        self::assertStringNotContainsString('<astro-slot name="title"', $output);
      },

      'a string slot is escaped rather than parsed as markup' => function () use ($slot_host): void {
        $output = $this->renderComponent($slot_host, slots: ['default' => '<img src=x onerror=alert(1)>']);

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $output);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $output);
      },

      "the component gets none of Canvas's own props, as in the browser" => function (): void {
        $root = $this->createComponent(
          'root lists its props',
          'import { h } from "preact"; export default function Root(props){ return h("div", { className: "prop-names" }, Object.keys(props).sort().join(",")); }',
        );

        $output = $this->renderComponent($root, props: ['title' => 'Title'] + self::canvasProps([]));

        self::assertStringContainsString('<div class="prop-names">title</div>', $output);
      },

      'a declared slot in a preview is wrapped in slot markers' => function () use ($slot_host): void {
        $output = $this->renderComponent(
          $slot_host,
          props: self::canvasProps(['default']),
          slots: ['default' => '<p>Slot HTML</p>'],
          uuid: self::ISLAND_UUID,
        );

        self::assertStringNotContainsString('<canvas-island', $output);
        self::assertStringContainsString('canvas-slot-start-' . self::ISLAND_UUID . '/default', $output);
        self::assertStringContainsString('canvas-slot-end-' . self::ISLAND_UUID . '/default', $output);
      },

      // Canvas only adds markers in previews, for its UI. SSR prints slots
      // through the same Twig code, so it follows the same rule.
      'outside a preview the content is printed with no markers at all' => function () use ($slot_host): void {
        $output = $this->renderComponent(
          $slot_host,
          props: self::canvasProps(['default'], preview: FALSE),
          slots: ['default' => '<p>Slot HTML</p>'],
          uuid: self::ISLAND_UUID,
        );

        self::assertStringNotContainsString('<canvas-island', $output);
        self::assertStringContainsString('Slot HTML', $output);
        self::assertStringNotContainsString('canvas-slot-start-', $output);
        self::assertStringNotContainsString('canvas-prop-start-', $output);
      },

      // CanvasWrapperNode treats anything that isn't a declared slot as a
      // prop, and gives it prop markers.
      'content printed under an undeclared name gets prop markers' => function () use ($slot_host): void {
        $output = $this->renderComponent(
          $slot_host,
          props: self::canvasProps(['some_other_slot']),
          slots: ['default' => '<p>Slot HTML</p>'],
          uuid: self::ISLAND_UUID,
        );

        self::assertStringNotContainsString('<canvas-island', $output);
        self::assertStringContainsString('canvas-prop-start-' . self::ISLAND_UUID . '/default', $output);
        self::assertStringContainsString('canvas-prop-end-' . self::ISLAND_UUID . '/default', $output);
        self::assertStringNotContainsString('canvas-slot-start-', $output);
      },

      // Twig can't print a hyphenated name, so it gets no markers. That's
      // fine, as long as Twig doesn't read the name as subtraction and lose
      // the content.
      'a name Twig cannot print keeps its content and loses its markers' => function (): void {
        $root = $this->createComponent(
          'root renders a hyphenated slot',
          'import { h } from "preact"; export default function Root(props){ return h("div", null, props["my-slot"] ?? null); }',
        );

        $output = $this->renderComponent(
          $root,
          props: self::canvasProps(['my-slot']),
          slots: ['my-slot' => '<p>Slot HTML</p>'],
          uuid: self::ISLAND_UUID,
        );

        self::assertStringNotContainsString('<canvas-island', $output);
        self::assertStringContainsString('Slot HTML', $output);
        self::assertStringNotContainsString('canvas-slot-start-', $output);
      },
    ];
  }

  /**
   * Scenarios for a slot rendered once and each copy of it marked once.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function slotRenderScenarios(): array {
    // Shared by the scenarios below. None of them change it.
    $hydrated = $this->createComponent('root renders default slot', self::HYDRATED_SLOT_HOST_JS);

    return [
      // A hydrated island has two copies of a slot: one in the SSR markup
      // and one in the <template> the client hydrates from. SSR prints the
      // first. The second goes back in '#slots' unprinted, for Canvas to
      // print. Neither may be printed twice.
      "each copy of a hydrated island's slot carries exactly one marker pair" => function () use ($hydrated): void {
        $output = $this->renderComponent(
          $hydrated,
          props: self::canvasProps(['default']),
          slots: ['default' => ['#markup' => '<p>Slot HTML</p>']],
          uuid: self::ISLAND_UUID,
        );

        self::assertStringContainsString('client="load"', $output);
        self::assertSame(2, \substr_count($output, '<p>Slot HTML</p>'));
        self::assertSame(2, \substr_count($output, '<!-- canvas-slot-start-' . self::ISLAND_UUID . '/default -->'));
        self::assertSame(2, \substr_count($output, '<!-- canvas-slot-end-' . self::ISLAND_UUID . '/default -->'));
        // Nested markers would mean a copy was printed twice.
        self::assertStringNotContainsString(
          '<!-- canvas-slot-start-' . self::ISLAND_UUID . '/default --><!-- canvas-slot-start-' . self::ISLAND_UUID . '/default -->',
          $output,
        );
      },

      // Rendering a slot can have side effects or cost queries, so both
      // copies come from one render.
      'a hydrated island renders its slot once and shows it twice' => function () use ($hydrated): void {
        $output = $this->renderComponent(
          $hydrated,
          slots: ['default' => SlotRenderCounter::slot('<p>Slot HTML</p>')],
        );

        self::assertSame(1, SlotRenderCounter::$renders);
        self::assertStringContainsString('client="load"', $output);
        // Once in the SSR markup, once in the hydration template.
        self::assertSame(2, \substr_count($output, '<p>Slot HTML</p>'));
      },

      // With no compiled JS, SSR bails. Canvas prints the slot already
      // rendered, rather than rendering it again.
      'a slot resolved before a CSR fallback is not rendered again' => function (): void {
        $root = $this->createComponent(
          'root without compiled js',
          'import { h } from "preact"; export default function Root(props){ return h("div", null, props.default ?? null); }',
          compiled: '',
        );

        $output = $this->renderComponent(
          $root,
          slots: ['default' => SlotRenderCounter::slot('<p>Slot HTML</p>')],
        );

        self::assertSame(1, SlotRenderCounter::$renders);
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('<p>Slot HTML</p>', $output);
      },
    ];
  }

  /**
   * Scenarios for what a slot leaves on the island besides its markup.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function slotBubblingScenarios(): array {
    return [
      // A static island prints slots only inside the SSR markup, so the
      // island must carry their cacheability.
      'the cacheability of a slot SSR absorbed still bubbles' => function (): void {
        $root = $this->createComponent('root renders default slot', self::SLOT_HOST_JS);

        $output = $this->renderComponent(
          $root,
          slots: [
            'default' => [
              '#markup' => '<p>Slot HTML</p>',
              '#cache' => ['tags' => ['canvas_ssr_test:slot'], 'contexts' => ['languages:language_interface']],
            ],
          ],
          cacheability: $cacheability,
        );

        self::assertStringNotContainsString('<canvas-island', $output);
        self::assertContains('canvas_ssr_test:slot', $cacheability->getCacheTags());
        self::assertContains('languages:language_interface', $cacheability->getCacheContexts());
      },

      'the scoped import map of a nested island reaches the parent' => function (): void {
        $root = $this->createComponent('root renders default slot', self::SLOT_HOST_JS);
        $slot_component = $this->createComponent(
          'slot child',
          '"use client"; export default function SlotChild(){ return null; }',
        );

        $slot_component_url = '/example/slot-client-component.js';
        $build = [
          '#type' => 'astro_island',
          '#name' => (string) $root->label(),
          '#machine_name' => $root->id(),
          '#component_url' => '/canvas/api/v0/auto-saves/js/js_component/' . $root->id(),
          '#props' => [],
          '#slots' => [
            'default' => [
              '#type' => 'astro_island',
              '#name' => (string) $slot_component->label(),
              '#machine_name' => $slot_component->id(),
              '#component_url' => $slot_component_url,
              '#props' => [],
              '#slots' => [],
              '#framework' => 'preact',
              '#import_maps' => [
                ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS => [
                  $slot_component_url => ['slot-lib' => '/slot-lib.js'],
                ],
              ],
            ],
          ],
          '#framework' => 'preact',
        ];
        // Go through the renderer, not the pre-render callback. The renderer
        // is what bubbles the slot's attachments onto the element.
        $renderer = $this->container->get('renderer');
        \assert($renderer instanceof RendererInterface);
        $renderer->renderInIsolation($build);

        self::assertArrayHasKey('#attached', $build);
        self::assertArrayHasKey('import_maps', $build['#attached']);
        self::assertArrayHasKey(
          ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS,
          $build['#attached']['import_maps'],
        );
        self::assertArrayHasKey(
          $slot_component_url,
          $build['#attached']['import_maps'][ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS],
        );
      },
    ];
  }

  /**
   * Builds the props Canvas uses to decide on slot markers.
   *
   * @param array<int, string> $slot_ids
   *   The component's declared slot names.
   * @param bool $preview
   *   Whether this is a Canvas UI preview.
   *
   * @return array<string, mixed>
   *   Props for the island element.
   */
  private static function canvasProps(array $slot_ids, bool $preview = TRUE): array {
    return [
      'canvas_uuid' => self::ISLAND_UUID,
      'canvas_slot_ids' => $slot_ids,
      'canvas_is_preview' => $preview,
    ];
  }

}
