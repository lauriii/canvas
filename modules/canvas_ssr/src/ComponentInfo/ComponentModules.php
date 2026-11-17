<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\ComponentInfo;

use Drupal\canvas_ssr\Fetch\FetchingLibraries;
use Drupal\canvas_ssr\JsModules\ModuleEvaluationHooks;
use Drupal\canvas_ssr\JsParser\JsModuleParser;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Names a component graph's modules and builds its root's main module.
 *
 * Each component's imports of the others are pointed at their modules.
 *
 * A component's URL already changes with its compiled JS. But a preview
 * renders a dependency's draft under the same import as the published
 * component, so the importer's rewritten source changes with no change to
 * its own URL. A runner that keeps modules, across islands or in the base,
 * would then have two sources under one module URL.
 *
 * So a component that imports others gets its URL plus a hash of the
 * subgraph it reaches: each component's URL and the URLs it depends on.
 * That's everything its rewritten source depends on, so a module URL never
 * has two sources. A component that reaches none keeps its URL, which
 * already identifies its source. A dependency shared by several roots
 * reaches the same subgraph from each, so it gets one module URL, on every
 * page and in the base.
 *
 * Each "@/components/…" import of a component the importer reaches is
 * rewritten to that component's module URL. That's the set Canvas puts in
 * the importer's scoped import map, so an import of anything else fails
 * here, as it would in the browser. Imports are found by parsing, so text that
 * only looks like one, in a string or a comment, is left alone.
 *
 * Module URLs come from the graph alone, and need no parsing. What the
 * components import, and rewriting their sources, does, through the
 * parser's cache.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::getScopedDependencies()
 */
final readonly class ComponentModules {

  /**
   * The specifier prefix a component uses to import another.
   */
  private const string SPECIFIER_PREFIX = '@/components/';

  public function __construct(
    private FileUrlGeneratorInterface $fileUrlGenerator,
    private JsModuleParser $jsModuleParser,
    private FetchingLibraries $fetchingLibraries,
  ) {}

  /**
   * Builds the main module for a graph's root.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph.
   * @param string $settings_source
   *   The drupalSettings module's source, for the graph's site data.
   * @param string $framework
   *   The root's framework.
   *
   * @return \Drupal\canvas_ssr\ComponentInfo\MainModule|null
   *   The main module, or NULL if the root has no compiled JS, so there's
   *   nothing to invoke.
   *
   * @see \Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings::module()
   */
  public function mainModule(ComponentGraph $graph, string $settings_source, string $framework): ?MainModule {
    $module_urls = $this->moduleUrls($graph);
    $component_url = $module_urls[(string) $graph->root->id()] ?? NULL;
    if ($component_url === NULL) {
      return NULL;
    }

    return MainModule::build(
      $component_url,
      $settings_source,
      $framework,
      fn(): array => $this->sources($graph, $module_urls),
      \array_values($module_urls),
      $this->libraryImports($graph),
      $this->libraryFetchers($graph, $module_urls),
    );
  }

  /**
   * Returns the modules of the components that a library may fetch for.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph.
   * @param array<string, string> $module_urls
   *   What moduleUrls() returned for it.
   *
   * @return list<string>
   *   Their module URLs.
   *
   * @see \Drupal\canvas_ssr\Fetch\FetchingLibraries
   */
  private function libraryFetchers(ComponentGraph $graph, array $module_urls): array {
    $fetchers = [];
    foreach ($graph->all() as $component) {
      $machine_name = (string) $component->id();
      if (!$component->hasJs() || !isset($module_urls[$machine_name])) {
        continue;
      }
      $source = $component->getJs();
      $parsed = $this->jsModuleParser->parse($source, $machine_name);
      // One that can't be parsed can't be told, so it counts.
      if ($parsed === NULL || $this->fetchingLibraries->mayFetchFor(\array_column($parsed->getImports(), 0), $source)) {
        $fetchers[] = $module_urls[$machine_name];
      }
    }

    return $fetchers;
  }

  /**
   * Returns the bare specifiers a graph's components import.
   *
   * Those of other components aren't included, nor relative or absolute
   * ones. Dynamic imports are, since they name libraries too.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph.
   *
   * @return list<string>
   *   The specifiers, each once, in no particular order.
   */
  public function libraryImports(ComponentGraph $graph): array {
    $specifiers = [];
    foreach ($graph->all() as $component) {
      if (!$component->hasJs()) {
        continue;
      }
      $parsed = $this->jsModuleParser->parse($component->getJs(), (string) $component->id());
      foreach ($parsed?->getImports() ?? [] as [$specifier]) {
        if (
          $specifier === ''
          || \str_starts_with($specifier, '.')
          || \str_starts_with($specifier, '/')
          || \str_starts_with($specifier, self::SPECIFIER_PREFIX)
          || \preg_match('#^[a-zA-Z][a-zA-Z\d+\-.]*:#', $specifier) === 1
        ) {
          continue;
        }
        $specifiers[$specifier] = TRUE;
      }
    }

    return \array_keys($specifiers);
  }

  /**
   * Returns the imports a graph's components make by URL.
   *
   * That's a specifier that starts with "/", "./" or "../", or with a
   * scheme. Dynamic imports are included, when the specifier is a string.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph.
   *
   * @return list<array{0: string, 1: string}>
   *   For each, the importing component's machine name and the specifier.
   */
  public function urlImports(ComponentGraph $graph): array {
    $imports = [];
    foreach ($graph->all() as $component) {
      if (!$component->hasJs()) {
        continue;
      }
      $machine_name = (string) $component->id();
      $parsed = $this->jsModuleParser->parse($component->getJs(), $machine_name);
      foreach ($parsed?->getImports() ?? [] as [$specifier]) {
        if (\preg_match('#^(\.{0,2}/|[a-zA-Z][a-zA-Z\d+\-.]*:)#', $specifier) === 1) {
          $imports[] = [$machine_name, $specifier];
        }
      }
    }

    return $imports;
  }

  /**
   * Returns whether parsing any component in a graph gave up for now.
   *
   * A component that couldn't be parsed for a reason that may pass is a
   * transient error. One that can't be parsed at all isn't.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph, which was parsed this request.
   *
   * @return bool
   *   TRUE if a parse of one of its components gave up for now.
   *
   * @see \Drupal\canvas_ssr\JsParser\JsModuleParser::gaveUpForNow()
   */
  public function parseGaveUpForNow(ComponentGraph $graph): bool {
    foreach ($graph->all() as $component) {
      if ($component->hasJs() && $this->jsModuleParser->gaveUpForNow($component->getJs())) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns the module URL of every component with compiled JS.
   *
   * A component without compiled JS is left out, so importing it fails and
   * the island falls back to CSR. An empty module would instead give its
   * importer undefined exports.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph.
   *
   * @return array<string, string>
   *   Module URLs keyed by machine name.
   */
  public function moduleUrls(ComponentGraph $graph): array {
    $urls = $this->urls($graph);
    $module_urls = [];
    foreach ($urls as $machine_name => $url) {
      if (self::dependencies($graph, $urls, $machine_name) === []) {
        $module_urls[$machine_name] = $url;
        continue;
      }
      $subgraph = [];
      foreach (self::reach($graph, $urls, $machine_name) as $reached => $_) {
        $dependencies = \array_map(
          static fn (string $dependency): string => $urls[$dependency],
          self::dependencies($graph, $urls, $reached),
        );
        \sort($dependencies, SORT_STRING);
        $subgraph[$urls[$reached]] = $dependencies;
      }
      \ksort($subgraph, SORT_STRING);
      $module_urls[$machine_name] = $url . '~' . \substr(\hash('sha256', \serialize($subgraph)), 0, 32);
    }

    return $module_urls;
  }

  /**
   * Returns each component's source, with its imports rewritten.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph.
   * @param array<string, string> $module_urls
   *   What moduleUrls() returned for it.
   *
   * @return array<string, string>
   *   Sources keyed by module URL.
   */
  public function sources(ComponentGraph $graph, array $module_urls): array {
    $urls = $this->urls($graph);
    $sources = [];
    foreach ($graph->all() as $component) {
      $machine_name = (string) $component->id();
      if (!$component->hasJs() || !isset($module_urls[$machine_name])) {
        continue;
      }
      // One that imports no component may still call import().
      $sources[$module_urls[$machine_name]] = $this->rewrite($component->getJs(), $machine_name, \array_intersect_key($module_urls, self::reach($graph, $urls, $machine_name)));
    }

    return $sources;
  }

  /**
   * Returns the URL the browser loads each component's compiled JS from.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph.
   *
   * @return array<string, string>
   *   URLs keyed by machine name.
   */
  private function urls(ComponentGraph $graph): array {
    $urls = [];
    foreach ($graph->all() as $component) {
      if ($component->hasJs()) {
        $urls[(string) $component->id()] = $this->fileUrlGenerator->generateString($component->getJsPath());
      }
    }

    return $urls;
  }

  /**
   * Returns a component's direct dependencies that have compiled JS.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph.
   * @param array<string, string> $urls
   *   What urls() returned for it.
   * @param string $machine_name
   *   The component.
   *
   * @return list<string>
   *   Their machine names.
   */
  private static function dependencies(ComponentGraph $graph, array $urls, string $machine_name): array {
    return \array_values(\array_filter(
      $graph->edges[$machine_name] ?? [],
      static fn (string $dependency): bool => isset($urls[$dependency]),
    ));
  }

  /**
   * Returns the components a component reaches, itself included.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentGraph $graph
   *   The graph.
   * @param array<string, string> $urls
   *   What urls() returned for it.
   * @param string $machine_name
   *   The component.
   *
   * @return array<string, true>
   *   Machine names, as keys.
   */
  private static function reach(ComponentGraph $graph, array $urls, string $machine_name): array {
    $reached = [$machine_name => TRUE];
    $pending = [$machine_name];
    while ($pending !== []) {
      foreach (self::dependencies($graph, $urls, \array_pop($pending)) as $dependency) {
        if (!isset($reached[$dependency])) {
          $reached[$dependency] = TRUE;
          $pending[] = $dependency;
        }
      }
    }

    return $reached;
  }

  /**
   * Rewrites a source's component imports and its import() calls.
   *
   * The imports are pointed at their modules, and the calls are tracked.
   *
   * @param string $source
   *   The compiled JS.
   * @param string $machine_name
   *   The component it's the compiled JS of.
   * @param array<string, string> $module_urls
   *   The modules it may import, keyed by machine name.
   *
   * @return string
   *   The source with those imports rewritten. Unparsable source comes back
   *   as it is, for the runner to report.
   */
  private function rewrite(string $source, string $machine_name, array $module_urls): string {
    $module = $this->jsModuleParser->parse($source, $machine_name);
    if ($module === NULL) {
      return $source;
    }
    // What to put in place of each range of the source. None overlap.
    $edits = [];
    foreach ($module->getImports() as [$specifier, $start, $end]) {
      $module_url = \str_starts_with($specifier, self::SPECIFIER_PREFIX)
        ? $module_urls[\substr($specifier, \strlen(self::SPECIFIER_PREFIX))] ?? NULL
        : NULL;
      if ($module_url !== NULL) {
        $edits[] = [$start, $end, \json_encode($module_url, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
      }
    }
    // Each import() is handed to the realm setup module, so the island
    // waits for it. One the component didn't wait for would otherwise
    // finish during a later island, and what it then did would be that
    // island's.
    foreach ($module->getDynamicImports() as [$start, $end]) {
      $edits[] = [$start, $start, ModuleEvaluationHooks::TRACK_IMPORT . '('];
      $edits[] = [$end, $end, ')'];
    }
    \usort($edits, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

    $rewritten = '';
    $copied = 0;
    foreach ($edits as [$start, $end, $replacement]) {
      $rewritten .= \substr($source, $copied, $start - $copied) . $replacement;
      $copied = $end;
    }
    $rewritten .= \substr($source, $copied);

    return $rewritten;
  }

}
