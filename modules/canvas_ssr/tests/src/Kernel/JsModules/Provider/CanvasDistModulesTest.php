<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsModules\Provider;

use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the dist modules Canvas ships render under SSR.
 *
 * The provider supplies what it finds in Canvas's astro-hydration dist
 * directory. These tests render rather than inspect the provided modules,
 * since one that fails to evaluate is no use to SSR.
 *
 * @legacy-covers \Drupal\canvas_ssr\JsModules\Provider\CanvasDistModuleProvider
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\JsModules\ImportMapCoverageTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class CanvasDistModulesTest extends CanvasSsrKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
  }

  /**
   * Tests what the dist modules render to.
   */
  public function testSsrRunsDistModulesCanvasShips(): void {
    $this->assertScenarios([
      'FormattedText renders its HTML child' => function (): void {
        self::assertSame('<div><p>hello</p></div>', $this->ssrMarkup(
          'FormattedText consumer',
          <<<'JS'
          import { FormattedText } from "drupal-canvas";
          export default function Root() {
            return <FormattedText>{"<p>hello</p>"}</FormattedText>;
          }
          JS,
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          import { FormattedText } from "drupal-canvas";
          export default function Root() {
            return _jsx(FormattedText, { children: "<p>hello</p>" });
          }
          JS,
        ));
      },

      'cn resolves its conditional class names' => function (): void {
        self::assertSame('<div class="foo bar">x</div>', $this->ssrMarkup(
          'cn consumer',
          <<<'JS'
          import { cn } from "drupal-canvas";
          export default function Root() {
            return <div className={cn("foo", { bar: true, baz: false })}>x</div>;
          }
          JS,
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          import { cn } from "drupal-canvas";
          export default function Root() {
            return _jsx("div", { className: cn("foo", { bar: true, baz: false }), children: "x" });
          }
          JS,
        ));
      },

      // Older components import cn from this barrel instead of the package.
      'the @/lib/utils barrel provides cn too' => function (): void {
        $js = <<<'JS'
        import { cn } from "@/lib/utils";
        import { createElement } from "preact";
        export default function Root() {
          return createElement("div", { class: cn("foo", "bar") });
        }
        JS;
        $root = $this->createComponent('cn helper consumer', $js);

        self::assertStringContainsString('class="foo bar"', $this->renderComponent($root));
      },

      // This barrel imports ./_commonjsHelpers.js. The provider must provide
      // that chunk under its module URL too, or the relative import fails.
      'a dist barrel importing a sibling chunk' => function (): void {
        $js = 'import { DrupalJsonApiParams } from "drupal-jsonapi-params"; export default function Root() { return null; }';
        $root = $this->createComponent('jsonapi-params consumer', $js);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $this->renderComponent($root));
      },

      'Image renders its full srcset' => function (): void {
        self::assertSame('<img alt="test image" loading="lazy" width="100" height="100" decoding="async" data-nimg="1" style="color:transparent;" sizes="auto 100vw" srcset="/test-640.jpg 640w, /test-750.jpg 750w, /test-828.jpg 828w, /test-1080.jpg 1080w, /test-1200.jpg 1200w, /test-1920.jpg 1920w, /test-2048.jpg 2048w, /test-3840.jpg 3840w" src="/test-3840.jpg"/>', $this->ssrMarkup(
          'Image consumer',
          <<<'JS'
          import { Image } from "drupal-canvas";
          export default function Root() {
            return <Image src="/test.jpg?alternateWidths=/test-{width}.jpg" alt="test image" width={100} height={100} />;
          }
          JS,
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          import { Image } from "drupal-canvas";
          export default function Root() {
            return _jsx(Image, { src: "/test.jpg?alternateWidths=/test-{width}.jpg", alt: "test image", width: 100, height: 100 });
          }
          JS,
        ));
      },
    ]);
  }

  /**
   * Renders a component and returns its SSR markup, asserting SSR happened.
   *
   * @param string $label
   *   The component's label.
   * @param string $source_code_js
   *   The authored component source.
   * @param string $compiled_js
   *   The compiled source.
   *
   * @return string
   *   The markup SSR produced.
   */
  private function ssrMarkup(string $label, string $source_code_js, string $compiled_js): string {
    $root = $this->createComponent($label, $source_code_js, $compiled_js);

    $output = $this->renderComponent($root);
    self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);

    return $this->extractSsrMarkup($output);
  }

}
