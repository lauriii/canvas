<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\ComponentInfo;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\ComponentInfo\ComponentGraph;
use Drupal\canvas_ssr\ComponentInfo\ComponentModules;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a graph's module URLs, and how its imports are rewritten.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 * @see \Drupal\Tests\canvas_ssr\Kernel\JsModules\ComponentImportsTest
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ComponentModules::class)]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class ComponentModulesTest extends CanvasSsrKernelTestBase {

  /**
   * Tests module URLs, and which imports are rewritten.
   *
   * The scenarios share one site install, so each makes components with
   * machine names of its own.
   */
  public function testModuleUrlsAndImports(): void {
    $this->assertScenarios([
      'a leaf keeps its URL, and an importer gets a hash on its own' => function (): void {
        $leaf = $this->leaf('leaf', 'l');
        $root = $this->importer('root', ['leaf']);

        $urls = $this->componentModules()->moduleUrls(ComponentGraph::of($root));

        self::assertSame($this->url($leaf), $urls['leaf']);
        self::assertMatchesRegularExpression(
          '/^' . \preg_quote($this->url($root), '/') . '~[0-9a-f]{32}$/',
          $urls['root'],
        );
      },

      'a dependency shared by two roots gets one module URL from either' => function (): void {
        $this->leaf('shared_leaf', 'l');
        $this->importer('shared', ['shared_leaf']);
        $first = $this->importer('first', ['shared']);
        $second = $this->importer('second', ['shared']);
        $component_modules = $this->componentModules();

        $from_first = $component_modules->moduleUrls(ComponentGraph::of($first));
        $from_second = $component_modules->moduleUrls(ComponentGraph::of($second));

        self::assertSame($from_first['shared'], $from_second['shared']);
        self::assertSame($from_first['shared_leaf'], $from_second['shared_leaf']);
        self::assertNotSame($from_first['first'], $from_second['second']);
      },

      // The middle's own source and URL don't change, but what it imports
      // does.
      'a draft changes the module URL of everything that reaches it, and nothing else' => function (): void {
        $leaf = $this->leaf('draft_leaf', 'l');
        $this->leaf('other', 'o');
        $middle = $this->importer('middle', ['draft_leaf']);
        $root = $this->importer('draft_root', ['middle', 'other']);
        $component_modules = $this->componentModules();
        $published = $component_modules->moduleUrls(ComponentGraph::of($root));

        $draft = JavaScriptComponent::load((string) $leaf->id());
        self::assertInstanceOf(JavaScriptComponent::class, $draft);
        $draft_js = 'export default function L(){ return "draft"; }';
        $draft->set('js', ['original' => $draft_js, 'compiled' => $draft_js]);
        $auto_save = $this->container->get(AutoSaveManager::class);
        \assert($auto_save instanceof AutoSaveManager);
        $auto_save->saveEntity($draft);
        $graph = ComponentGraph::of($root, $auto_save);
        $preview = $component_modules->moduleUrls($graph);

        self::assertSame($this->url($middle), \explode('~', $preview['middle'])[0]);
        foreach (['draft_leaf', 'middle', 'draft_root'] as $renamed) {
          self::assertNotSame($published[$renamed], $preview[$renamed], $renamed);
        }
        self::assertSame($published['other'], $preview['other']);
        $sources = $component_modules->sources($graph, $preview);
        self::assertSame($draft_js, $sources[$preview['draft_leaf']]);
        self::assertStringContainsString(
          \json_encode($preview['draft_leaf'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
          $sources[$preview['middle']],
        );
      },

      // An import of a component outside the graph stays, so it fails as it
      // would in the browser. Text that only looks like an import stays too.
      'only imports of reached components are rewritten' => function (): void {
        $this->leaf('reached', 'r');
        $this->leaf('outside', 'o');
        $source = <<<'JS'
        import L from "@/components/reached";
        import O from "@/components/outside";
        // import X from "@/components/reached";
        const s = 'import("@/components/reached")';
        export default function R(){ return [L, O, s]; }
        JS;
        $root = $this->createComponent('Reaching', $source, imports: ['reached'], machine_name: 'reaching');
        $component_modules = $this->componentModules();
        $graph = ComponentGraph::of($root);

        $urls = $component_modules->moduleUrls($graph);
        $rewritten = $component_modules->sources($graph, $urls)[$urls['reaching']];

        $reached = \json_encode($urls['reached'], JSON_UNESCAPED_SLASHES);
        self::assertSame(\str_replace(
          'import L from "@/components/reached";',
          'import L from ' . $reached . ';',
          $source,
        ), $rewritten);
      },

      // Every kind of import, at byte offsets past multibyte text and with
      // a module URL longer than the specifier.
      'every kind of import is rewritten, past multibyte text' => function (): void {
        $this->leaf('parsed_leaf', 'p');
        $this->importer('parsed_middle', ['parsed_leaf']);
        $source = <<<'JS'
        import M from "@/components/parsed_middle";
        const é = "→";
        export { default as L } from "@/components/parsed_leaf";
        export * from "react";
        export default function R(){ return [M, é, import("@/components/parsed_leaf")]; }
        JS;
        $root = $this->createComponent('Parsed root', $source, imports: ['parsed_middle', 'parsed_leaf'], machine_name: 'parsed_root');
        $component_modules = $this->componentModules();
        $graph = ComponentGraph::of($root);

        $urls = $component_modules->moduleUrls($graph);
        $rewritten = $component_modules->sources($graph, $urls)[$urls['parsed_root']];

        $middle = \json_encode($urls['parsed_middle'], JSON_UNESCAPED_SLASHES);
        $leaf = \json_encode($urls['parsed_leaf'], JSON_UNESCAPED_SLASHES);
        // The import() call is wrapped, so the island waits for it.
        self::assertSame(\strtr($source, [
          '"@/components/parsed_middle"' => $middle,
          'import("@/components/parsed_leaf")' => "__canvasSsrTrackImport(import($leaf))",
          '"@/components/parsed_leaf"' => $leaf,
        ]), $rewritten);
      },

      // Whatever it's given, and in a component that imports no other.
      'every import() call is wrapped' => function (): void {
        $source = <<<'JS'
        const é = "→";
        export default async function R(){ return [é, import(name), import(await import(`./${a}.js`)).then(f)]; }
        JS;
        $root = $this->createComponent('Wrapped root', $source, machine_name: 'wrapped_root');
        $component_modules = $this->componentModules();
        $graph = ComponentGraph::of($root);

        $urls = $component_modules->moduleUrls($graph);
        self::assertSame(\strtr($source, [
          'import(name)' => '__canvasSsrTrackImport(import(name))',
          'import(await import(`./${a}.js`))' => '__canvasSsrTrackImport(import(await __canvasSsrTrackImport(import(`./${a}.js`))))',
        ]), $component_modules->sources($graph, $urls)[$urls['wrapped_root']]);
      },
    ]);
  }

  /**
   * Creates a component that imports nothing.
   */
  private function leaf(string $machine_name, string $marker): JavaScriptComponent {
    $source = \sprintf('export default function C(){ return "%s"; }', $marker);

    return $this->createComponent($machine_name, $source, machine_name: $machine_name);
  }

  /**
   * Creates a component that imports others.
   *
   * @param string $machine_name
   *   Its machine name.
   * @param list<string> $imports
   *   The machine names it imports.
   */
  private function importer(string $machine_name, array $imports): JavaScriptComponent {
    $source = '';
    foreach ($imports as $i => $import) {
      $source .= \sprintf('import C%d from "@/components/%s"; ', $i, $import);
    }
    $source .= 'export default function C(){ return null; }';

    return $this->createComponent($machine_name, $source, imports: $imports, machine_name: $machine_name);
  }

  /**
   * Returns the URL a component's compiled JS is served from.
   */
  private function url(JavaScriptComponent $component): string {
    $file_url_generator = $this->container->get(FileUrlGeneratorInterface::class);
    \assert($file_url_generator instanceof FileUrlGeneratorInterface);

    return $file_url_generator->generateString($component->getJsPath());
  }

  /**
   * Gets the service under test.
   */
  private function componentModules(): ComponentModules {
    $component_modules = $this->container->get(ComponentModules::class);
    \assert($component_modules instanceof ComponentModules);

    return $component_modules;
  }

}
