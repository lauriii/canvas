<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsModules\Provider;

use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\canvas_ssr\Kernel\GlobalAssetLibraryTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests SSR renders what a CLI push uploaded.
 *
 * A push writes .js files into one directory under public:// and lists them
 * in the global asset library. PushedManifestProviderTest covers which
 * entries are provided.
 *
 * Scenarios can share a method because saving the asset library drops the
 * merged provided modules. They each use their own specifiers, though, since
 * the runner keeps registered modules from earlier renders.
 *
 * @legacy-covers \Drupal\canvas_ssr\JsModules\Provider\CanvasPushedManifestModuleProvider
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\JsModules\Provider\PushedManifestProviderTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class PushedManifestModulesTest extends CanvasSsrKernelTestBase {

  use GlobalAssetLibraryTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    // Saving the global AssetLibrary records file usage.
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->cleanupGlobalAssetLibraryModules();
    parent::tearDown();
  }

  /**
   * Tests SSR resolves the modules the CLI push uploaded.
   */
  public function testSsrResolvesWhatCliPushUploaded(): void {
    $this->assertScenarios([
      // A push lists the shared chunk under "shared". It's in no import map;
      // the barrels import it relatively.
      'a third-party package, and the chunk two of them share' => function (): void {
        $shared_uri = $this->writeGlobalAssetModule(
          'index-BLiUnf3h.js',
          'export const shared = 21;'
        );
        $switch_uri = $this->writeGlobalAssetModule(
          'react-switch.js',
          'import { shared } from "./index-BLiUnf3h.js"; export const Switch = shared;'
        );
        $checkbox_uri = $this->writeGlobalAssetModule(
          'react-checkbox.js',
          'import { shared } from "./index-BLiUnf3h.js"; export const Checkbox = shared;'
        );
        $this->setGlobalAssetLibraryEntries([
          ['name' => '@radix-ui/react-switch', 'uri' => $switch_uri],
          ['name' => '@radix-ui/react-checkbox', 'uri' => $checkbox_uri],
        ], shared: [
          ['name' => './vendor/index-BLiUnf3h.js', 'uri' => $shared_uri],
        ]);

        $source_code_js = <<<'JS'
        import { Switch } from "@radix-ui/react-switch";
        import { Checkbox } from "@radix-ui/react-checkbox";
        export default function Root() {
          return <div data-radix-sum={String(Switch + Checkbox)}>ok</div>;
        }
        JS;
        $compiled_js = <<<'JS'
        import { jsx as _jsx } from "react/jsx-runtime";
        import { Switch } from "@radix-ui/react-switch";
        import { Checkbox } from "@radix-ui/react-checkbox";
        export default function Root() {
          return _jsx("div", { "data-radix-sum": String(Switch + Checkbox), children: "ok" });
        }
        JS;
        $root = $this->createComponent('radix shared chunk consumer', $source_code_js, $compiled_js);

        $output = $this->renderComponent($root);
        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('data-radix-sum="42"', $output);
      },

      'a local @/ asset module' => function (): void {
        $format_price_uri = $this->writeGlobalAssetModule(
          'formatPrice.js',
          'export const formatPrice = (value) => `$${value}`;'
        );
        $this->setGlobalAssetLibraryEntries(assets: [
          ['name' => '@/lib/formatPrice', 'uri' => $format_price_uri],
        ]);

        $component_source = 'import { formatPrice } from "@/lib/formatPrice"; export default function Root(){ return null; }';
        $root = $this->createComponent('root import scenario', $component_source);

        self::assertStringNotContainsString(
          self::CLIENT_ONLY_ATTRIBUTE,
          $this->renderComponent($root)
        );
      },

      // "dayjs" maps to the barrel's URL, and both files are registered by
      // URL, so the barrel's relative import resolves to the sibling.
      'a bare specifier reaching its sibling chunk relatively' => function (): void {
        $helpers_uri = $this->writeGlobalAssetModule(
          '_commonjsHelpers-abc123.js',
          'export function getDefaultExportFromCjs(x) { return x && x.__esModule ? x.default : x; }'
        );
        $dayjs_uri = $this->writeGlobalAssetModule(
          'dayjs-xyz789.js',
          'import { getDefaultExportFromCjs as g } from "./_commonjsHelpers-abc123.js"; export default { format: () => "mocked" };'
        );
        $this->setGlobalAssetLibraryEntries([
          ['name' => 'dayjs', 'uri' => $dayjs_uri],
        ], shared: [
          ['name' => './vendor/_commonjsHelpers-abc123.js', 'uri' => $helpers_uri],
        ]);

        $source_code_js = <<<'JS'
        import dayjs from "dayjs";
        export default function Root() {
          return <div data-formatted={dayjs.format()}>ok</div>;
        }
        JS;
        $compiled_js = <<<'JS'
        import { jsx as _jsx } from "react/jsx-runtime";
        import dayjs from "dayjs";
        export default function Root() {
          return _jsx("div", { "data-formatted": dayjs.format(), children: "ok" });
        }
        JS;
        $root = $this->createComponent('bare specifier with sibling chunk', $source_code_js, $compiled_js);

        $output = $this->renderComponent($root);
        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('data-formatted="mocked"', $output);
      },
    ]);
  }

}
