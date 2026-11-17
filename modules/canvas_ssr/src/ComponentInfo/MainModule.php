<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\ComponentInfo;

use Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider;

/**
 * The module an invoke imports, and every module it needs registered.
 *
 * A render adds three modules to a component graph's: a drupalSettings
 * module, the main module that imports it, the framework renderer and the
 * component, and a libraries module, which the renderer invokes first when
 * the realm may not have evaluated a library the components import. Each
 * module URL holds a hash of the module's source, so renders with the same
 * source share the module, here and in the base.
 *
 * The drupalSettings module's hash covers its component too. A module
 * evaluates once per realm, so a drupalSettings module shared with another
 * component could have evaluated earlier, and an island since could have
 * changed drupalSettings. This component's top-level code would then miss
 * the site data. With its own drupalSettings module, that module evaluates
 * in the invoke that first evaluates the component, or in the libraries
 * module's invoke just before it, with only the island's libraries in
 * between.
 *
 * @see \Drupal\canvas_ssr\ComponentInfo\ComponentModules::mainModule()
 */
final class MainModule {

  /**
   * The module URL prefix for the modules built for a component.
   *
   * Drupal serves nothing here, so they can't collide with a provider's.
   */
  private const string PATH_PREFIX = '/_canvas_ssr/component/';

  /**
   * Constructs a MainModule.
   *
   * @param string $url
   *   The main module's module URL.
   * @param array<string, string> $modules
   *   The drupalSettings module's, the libraries module's and the main
   *   module's sources, keyed by module URL.
   * @param \Closure(): array<string, string> $componentSources
   *   Returns the component graph's sources keyed by module URL. Called
   *   only when they're asked for, since reading them is slow.
   * @param list<string> $componentUrls
   *   The module URLs of the component graph's modules.
   * @param string $librariesUrl
   *   The module URL of the libraries module, which the renderer invokes
   *   before the main module when the realm may not have evaluated them.
   * @param list<string> $libraries
   *   The specifiers of the libraries it evaluates.
   * @param list<string> $libraryFetchers
   *   The module URLs of the graph's components that a library may fetch
   *   for.
   */
  private function __construct(
    public readonly string $url,
    private readonly array $modules,
    private readonly \Closure $componentSources,
    public readonly array $componentUrls,
    public readonly string $librariesUrl,
    public readonly array $libraries,
    public readonly array $libraryFetchers,
  ) {}

  /**
   * Builds a component's main module and drupalSettings module.
   *
   * @param string $component_url
   *   The component's module URL.
   * @param string $settings_source
   *   The drupalSettings module's source.
   * @param string $framework
   *   The component's framework. A module provider supplies its framework
   *   renderer.
   * @param \Closure(): array<string, string> $component_sources
   *   Returns the component graph's sources keyed by module URL.
   * @param list<string> $component_urls
   *   The module URLs of the component graph's modules.
   * @param list<string> $libraries
   *   The bare specifiers the component graph's modules import.
   * @param list<string> $library_fetchers
   *   The module URLs of the graph's components that a library may fetch
   *   for.
   *
   * @return self
   *   The main module.
   */
  public static function build(string $component_url, string $settings_source, string $framework, \Closure $component_sources, array $component_urls, array $libraries = [], array $library_fetchers = []): self {
    $settings_url = self::PATH_PREFIX . 'drupal-settings/' . self::hash($component_url . "\n" . $settings_source) . '.js';
    // The framework renderer and the hydration data module first, as the
    // main module imports them before the component, and as a base
    // preloads them before any library.
    $renderer = SsrOnlyModuleProvider::rendererSpecifier($framework);
    \sort($libraries, SORT_STRING);
    $libraries = \array_values(\array_unique([$renderer, SsrOnlyModuleProvider::HYDRATION_DATA_SPECIFIER, ...$libraries]));
    $libraries_source = self::librariesSource(SsrOnlyModuleProvider::REALM_SETUP_SPECIFIER, $settings_url, $libraries);
    $libraries_url = self::PATH_PREFIX . 'libraries/' . self::hash($libraries_source) . '.js';
    $main_source = self::source(
      SsrOnlyModuleProvider::REALM_SETUP_SPECIFIER,
      $settings_url,
      SsrOnlyModuleProvider::rendererSpecifier($framework),
      $component_url,
      SsrOnlyModuleProvider::HYDRATION_DATA_SPECIFIER,
    );
    $main_url = self::PATH_PREFIX . 'main/' . self::hash($main_source) . '.js';

    return new self(
      $main_url,
      [
        $settings_url => $settings_source,
        $libraries_url => $libraries_source,
        $main_url => $main_source,
      ],
      $component_sources,
      $component_urls,
      $libraries_url,
      $libraries,
      $library_fetchers,
    );
  }

  /**
   * Returns every module an invoke of the main module needs registered.
   *
   * @return array<string, string>
   *   The component graph's sources, and those generatedSources() returns,
   *   keyed by module URL.
   */
  public function sources(): array {
    return $this->componentSources() + $this->generatedSources();
  }

  /**
   * Returns the sources of the component's modules, and its dependencies'.
   *
   * @return array<string, string>
   *   Sources, keyed by module URL.
   */
  public function componentSources(): array {
    return ($this->componentSources)();
  }

  /**
   * Returns the sources canvas_ssr wrote for the island.
   *
   * @return array<string, string>
   *   The drupalSettings module's, the libraries module's and the main
   *   module's, keyed by module URL.
   */
  public function generatedSources(): array {
    return $this->modules;
  }

  /**
   * Hashes a module's source for its module URL.
   *
   * @param string $source
   *   The source.
   *
   * @return string
   *   The hash.
   */
  private static function hash(string $source): string {
    return \substr(\hash('sha256', $source), 0, 32);
  }

  /**
   * Builds the main module's source.
   *
   * It imports the component and passes it to the framework renderer, so
   * the framework renderer can be a fixed file.
   *
   * The realm setup module is imported first, so the realm is locked before
   * any other module evaluates. The drupalSettings module comes next,
   * because components may read drupalSettings while they evaluate.
   *
   * It depends only on its imports' module URLs, so every island of the
   * same component, framework and site data reuses it.
   *
   * @param string $setup_specifier
   *   The realm setup module's specifier.
   * @param string $settings_url
   *   The drupalSettings module's module URL. Evaluating it sets the site
   *   data.
   * @param string $renderer_specifier
   *   The framework renderer's specifier.
   * @param string $component_url
   *   The component's module URL.
   * @param string $hydration_data_specifier
   *   The hydration data module's specifier.
   *
   * @return string
   *   The JS source.
   */
  private static function source(string $setup_specifier, string $settings_url, string $renderer_specifier, string $component_url, string $hydration_data_specifier): string {
    return \sprintf(<<<'JS'
import { beginIsland, endIsland, importsUnsettled } from %4$s;
import { collectHydrationData, resetHydrationData } from %5$s;
import %1$s;
import render from %2$s;
import Component from %3$s;

// Returns JSON for MainModuleResult: the markup, with a hydrated island's
// hydration data, or what was thrown. A throw while rendering is caught, so the
// runner keeps its realm.
export default async function(payloadJson) {
  const { drupalSettings, siteDrupalSettings, hydrates, ...payload } = JSON.parse(payloadJson);
  const result = {};
  // Awaited, so a component that throws while rendering asynchronously is
  // caught here too.
  try {
    // Undoes what earlier islands left in the realm that it can, and sets
    // this island's drupalSettings. A module that evaluates during the
    // render gets only the site data. It throws if something the island's
    // modules left running changed what islands share.
    beginIsland(drupalSettings, siteDrupalSettings);
    // Nothing a library provided for an earlier island is collected for
    // this one, even if that island threw first.
    resetHydrationData();
    const html = await render(Component, payload);
    if (typeof html !== 'string') {
      throw new Error(`The framework renderer returned ${typeof html}, not markup.`);
    }
    // A static island ships no code, so nothing reads hydration data.
    const hydrationData = hydrates === true ? collectHydrationData() : null;
    result.html = html;
    if (hydrationData !== null) {
      result.hydrationData = hydrationData;
    }
  } catch (e) {
    result.error = `${e?.message}`;
  }
  // What the island's libraries provided is held no longer than its render.
  resetHydrationData();
  // An import() a component made and didn't wait for may finish after this
  // returns. The island stays open until Renderer has waited for it, so
  // what follows it is this island's, not a later one's.
  if (importsUnsettled()) {
    result.imports = true;
  }
  else {
    endIsland();
  }
  return JSON.stringify(result);
}
JS,
      self::encodeSpecifier($settings_url),
      self::encodeSpecifier($renderer_specifier),
      self::encodeSpecifier($component_url),
      self::encodeSpecifier($setup_specifier),
      self::encodeSpecifier($hydration_data_specifier),
    );
  }

  /**
   * Builds the libraries module's source.
   *
   * Invoking it evaluates the libraries the island's components import,
   * one after another, before any of the components' modules. A library
   * evaluates only once, so what it changes as it evaluates is kept for
   * every later island, and a component's module may change nothing then.
   * Evaluating them apart keeps the two from being mistaken for each other,
   * and is what a base does, so a render is the same with one and without.
   *
   * A library that can't load, or throws, is left for the component that
   * imports it to fail on. One a component only imports with import()
   * evaluates here too.
   *
   * @param string $setup_specifier
   *   The realm setup module's specifier.
   * @param string $settings_url
   *   The drupalSettings module's module URL, for a library that reads the
   *   site data as it evaluates.
   * @param list<string> $libraries
   *   The libraries' specifiers, in the order to evaluate them.
   *
   * @return string
   *   The JS source.
   */
  private static function librariesSource(string $setup_specifier, string $settings_url, array $libraries): string {
    $imports = '';
    foreach ($libraries as $specifier) {
      $imports .= '    () => import(' . self::encodeSpecifier($specifier) . "),\n";
    }

    return \sprintf(<<<'JS'
import { evaluateLibraries } from %1$s;
import %2$s;

export default async function() {
  await evaluateLibraries([
%3$s  ]);
  return '';
}
JS,
      self::encodeSpecifier($setup_specifier),
      self::encodeSpecifier($settings_url),
      $imports,
    );
  }

  /**
   * Encodes a specifier as a JS string literal.
   *
   * @param string $specifier
   *   The specifier.
   *
   * @return string
   *   The quoted literal.
   */
  private static function encodeSpecifier(string $specifier): string {
    return \json_encode($specifier, JSON_THROW_ON_ERROR);
  }

}
