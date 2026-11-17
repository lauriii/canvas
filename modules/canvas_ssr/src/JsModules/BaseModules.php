<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\ComponentInfo\BoundaryCheckers;
use Drupal\canvas_ssr\ComponentInfo\ComponentGraph;
use Drupal\canvas_ssr\ComponentInfo\ComponentModules;
use Drupal\canvas_ssr\DrupalSettings\DrupalSettingsResolver;
use Drupal\canvas_ssr\Element\AstroIslandSsr;
use Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\VariationCacheInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Defines the base.
 *
 * The base is the modules nearly every render registers, and the SSR import
 * map renders resolve through.
 *
 * They're every provided module, plus what a render of each exposed
 * component adds: its graph's modules, its drupalSettings module and its
 * main module.
 *
 * A runner evaluates the preload modules into the realm renders start from,
 * and only compiles the rest. The preload modules evaluate the libraries:
 * the realm setup module, a module that sets all of the site data, then
 * one module for each library an exposed component imports, and the
 * framework renderer. No component evaluates there. One that isn't on a
 * page would otherwise change islands on every page, since what a module
 * does as it evaluates is kept, and in the browser it would change nothing.
 * The main modules are compiled, so a runner can check that they load.
 *
 * If the SSR import map has tailwind-merge, a last preload module warms it
 * up, because tailwind-merge builds its class map on first use.
 *
 * Drafts aren't included: a preview registers its own modules on top.
 * ComponentModules names a module after everything its source depends on,
 * so a draft and whatever reaches it get URLs of their own, and never
 * replace a base module.
 *
 * Nor is a component with a source that isn't valid UTF-8, since runners
 * may take base modules only as text. Otherwise one such component would
 * leave every render without a base. The render registers its own sources.
 *
 * The base's id is a digest of it, modules and import map alike. It's
 * cached on the cache tags of the components, the module providers, the
 * global asset library and the site data, and varies by the cache
 * contexts those declare. The providers and the import map name modules by
 * URL, so there's a base for each base path the site is served under.
 * Runners evaluate the base with the site data set, and a module can read
 * it as it evaluates, so there's one for each host and theme too. Each has
 * its own id, so a runner can keep them side by side.
 *
 * The base itself isn't cached. It's built only for a runner that lacks its
 * id, and runners keep what they build from it.
 *
 * With the id comes an index of the base's components, by main module. A
 * component's main module URL depends on everything a render of it
 * registers, so a runner using the base can render one of them by invoking
 * its main module, with nothing to register or check.
 *
 * The id and base are kept for the request until a cache tag of theirs is
 * invalidated, as when a request pushes and then renders. The next id() is
 * then read or built afresh, and changes() tells the renderer to start over.
 * Component tags are the exception. Components are named by content, so a
 * stale base can't disagree with what a render registers for them, and the
 * change can wait for the next request.
 *
 * @see \Drupal\canvas_ssr\ComponentInfo\ComponentModules
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::useBase()
 * @see docs/adr/0010-a-base-is-built-once-and-never-changes-output.md
 *
 * @phpstan-import-type Base from \Drupal\canvas_ssr\JsRunner\RunnerInterface
 */
final class BaseModules implements CacheTagsInvalidatorInterface {

  /**
   * How long to keep a base whose component's parse gave up, in seconds.
   *
   * The parse gave up for now, so the base is left without the component.
   *
   * As long as a transient error is cached.
   *
   * @see \Drupal\canvas_ssr\Renderer::TRANSIENT_ERROR_TAG
   */
  private const int GAVE_UP_MAX_AGE = 60;

  /**
   * Cache keys for the base's id and components.
   */
  private const array ID_KEYS = ['canvas_ssr', 'base_modules', 'id', 'components'];

  /**
   * The framework the base's main modules use: AstroIsland's default.
   *
   * An island of another framework gets a main module the base lacks, so
   * its render registers that itself.
   */
  private const string FRAMEWORK = AstroIslandSsr::DEFAULT_FRAMEWORK;

  /**
   * Where the modules only a base has go.
   *
   * Drupal serves nothing here, so they can't collide with a provider's.
   */
  private const string PATH_PREFIX = '/_canvas_ssr/base/';

  /**
   * The id, once known.
   */
  private ?string $id = NULL;

  /**
   * The base, once built.
   *
   * @var Base|null
   */
  private ?array $base = NULL;

  /**
   * Whether each of the base's components hydrates, keyed by main module.
   *
   * @var array<string, bool>
   */
  private array $components = [];

  /**
   * The cache tags of the id and base, once known.
   *
   * @var string[]
   */
  private array $tags = [];

  /**
   * How many times the id and base have been forgotten.
   */
  private int $changes = 0;

  /**
   * Constructs a BaseModules.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the exposed components.
   * @param iterable<\Drupal\canvas_ssr\JsModules\Provider\ModuleProviderInterface> $moduleProviders
   *   The module providers, highest priority first. This merges them itself,
   *   so the current render's provided modules are untouched.
   * @param \Drupal\canvas_ssr\DrupalSettings\DrupalSettingsResolver $drupalSettingsResolver
   *   Resolves each component's drupalSettings, and says what the site data
   *   depends on.
   * @param \Drupal\canvas_ssr\JsModules\SiteImportMap $siteImportMap
   *   The site import map, which the SSR import map is built from.
   * @param \Drupal\Core\Cache\VariationCacheInterface $cache
   *   Where the id is kept. It's read at the contexts of the import map
   *   and the site data, which are known without reading either. The
   *   cache redirects from there only to contexts a provider adds.
   * @param \Drupal\canvas_ssr\ComponentInfo\BoundaryCheckers $boundaryCheckers
   *   Say which components hydrate.
   * @param \Drupal\canvas_ssr\ComponentInfo\ComponentModules $componentModules
   *   Names and rewrites each graph's modules, as renders do.
   * @param \Drupal\canvas_ssr\JsModules\ModuleEvaluationHooks $moduleEvaluationHooks
   *   Appends the lines that freeze each module's exports, as renders do.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly iterable $moduleProviders,
    private readonly DrupalSettingsResolver $drupalSettingsResolver,
    private readonly SiteImportMap $siteImportMap,
    private readonly VariationCacheInterface $cache,
    private readonly BoundaryCheckers $boundaryCheckers,
    private readonly ComponentModules $componentModules,
    private readonly ModuleEvaluationHooks $moduleEvaluationHooks,
  ) {}

  /**
   * Returns the base's id, defining the base if no id is cached.
   *
   * The id is read once until it's forgotten.
   *
   * @return string
   *   A digest of the base, its import map included.
   */
  public function id(): string {
    if ($this->id === NULL) {
      $cached = $this->cache->get(self::ID_KEYS, $this->knownCacheability());
      if (self::isUsable($cached)) {
        $this->id = $cached->data['id'];
        $this->components = $cached->data['components'];
        $this->tags = $cached->tags;
      }
    }

    return $this->id ?? $this->define();
  }

  /**
   * Returns the cache tags of the base's id, which change with the base.
   *
   * Call it after id().
   *
   * @return string[]
   *   The tags.
   */
  public function cacheTags(): array {
    return $this->tags;
  }

  /**
   * Returns the base with its id, defining it once per request.
   *
   * @return array{0: string, 1: Base}
   *   The id and the base. If the base changed since id() was read, this is
   *   the current base and its own id, and id() returns that from now on.
   */
  public function getDefinition(): array {
    if ($this->base === NULL) {
      $this->define();
    }
    \assert($this->id !== NULL && $this->base !== NULL);

    return [$this->id, $this->base];
  }

  /**
   * Returns whether the base's component with a main module hydrates.
   *
   * Call it after id() or getDefinition(), whichever gave the id the runner
   * uses.
   *
   * @param string $main_module
   *   The component's main module URL, from ComponentModules::mainModule().
   *
   * @return bool|null
   *   Whether the component hydrates, or NULL if the base doesn't cover it.
   */
  public function componentHydrates(string $main_module): ?bool {
    return $this->components[$main_module] ?? NULL;
  }

  /**
   * Returns how many times this request's id and base were forgotten.
   *
   * What a render registered before a change can disagree with the new
   * base, so a renderer that sees this go up starts over.
   *
   * @return int
   *   The count, starting at 0.
   */
  public function changes(): int {
    return $this->changes;
  }

  /**
   * Forgets the id and base when one of their cache tags is invalidated.
   *
   * Not for component tags, which can wait for the next request.
   *
   * @param string[] $tags
   *   The cache tags being invalidated.
   */
  public function invalidateTags(array $tags): void {
    $tags = \array_intersect($tags, $this->tags);
    if ($tags === [] || \array_diff($tags, $this->componentTags()) === []) {
      return;
    }
    $this->id = NULL;
    $this->base = NULL;
    $this->components = [];
    $this->tags = [];
    $this->changes++;
  }

  /**
   * Returns whether a cached id has what this reads with it.
   *
   * @param object|false $cached
   *   The cache item, or FALSE.
   *
   * @return bool
   *   TRUE if it's usable.
   *
   * @phpstan-assert-if-true object{data: array{id: string, components: array<mixed>}, tags: array<string>} $cached
   */
  private static function isUsable(object|false $cached): bool {
    return $cached !== FALSE
      && \is_array($cached->data ?? NULL)
      && \is_string($cached->data['id'] ?? NULL)
      && \is_array($cached->data['components'] ?? NULL);
  }

  /**
   * Defines the base, and caches its id.
   *
   * @return string
   *   The id.
   */
  private function define(): string {
    $cacheability = new CacheableMetadata();
    [$this->base, $this->components] = $this->collect($cacheability);
    $this->id = \hash('sha256', \serialize($this->base));
    $this->tags = $cacheability->getCacheTags();
    $this->cache->set(self::ID_KEYS, ['id' => $this->id, 'components' => $this->components], $cacheability, $this->knownCacheability());

    return $this->id;
  }

  /**
   * Returns the cacheability the base is known to vary by before it's built.
   *
   * The base always depends on the import map and the site data, so it
   * varies by at least their contexts, and reads go straight to them.
   */
  private function knownCacheability(): CacheableMetadata {
    return (new CacheableMetadata())
      ->setCacheContexts($this->siteImportMap->getCacheContexts())
      ->addCacheContexts($this->drupalSettingsResolver->siteDataCacheability()->getCacheContexts());
  }

  /**
   * Returns the cache tags the components give the base.
   *
   * @return string[]
   *   The tags.
   */
  private function componentTags(): array {
    return $this->entityTypeManager->getDefinition(JavaScriptComponent::ENTITY_TYPE_ID)?->getListCacheTags() ?? [];
  }

  /**
   * Collects the base, and which of its components hydrate.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the base depends on.
   *
   * @return array{0: Base, 1: array<string, bool>}
   *   The base, with the SSR import map, and whether each component hydrates
   *   keyed by main module. Components are left out of the index if
   *   there's no checker for their framework. A component the checker
   *   can't tell about is left out of the base, so it renders on its own,
   *   and falls back to CSR as long as the checker still can't tell.
   */
  private function collect(CacheableMetadata $cacheability): array {
    $checker = $this->boundaryCheckers->get(self::FRAMEWORK);
    $provided = new AllProvidedModules($this->moduleProviders);
    $ssr_import_map = new SsrImportMap($this->siteImportMap, $provided);

    $cacheability->addCacheTags($this->componentTags());
    $cacheability->addCacheableDependency($ssr_import_map);
    // Runners evaluate the base with it set.
    $cacheability->addCacheableDependency($this->drupalSettingsResolver->siteDataCacheability());

    $modules = $provided->sources();
    $provided_sources = $modules;
    // Every render relies on these, so there's no base without them.
    if (!self::allText($modules)) {
      throw new UnusableBaseException('A module provider gave a module that is not valid UTF-8.', CacheableMetadata::createFromObject($cacheability));
    }
    $import_map = $ssr_import_map->get();
    $compile = [];
    $component_modules = [];
    $libraries = [];
    $components = [];
    /** @var \Drupal\canvas\Entity\JavaScriptComponent[] $exposed */
    $exposed = $this->entityTypeManager
      ->getStorage(JavaScriptComponent::ENTITY_TYPE_ID)
      ->loadByProperties(['status' => TRUE]);
    // Sort, so the same components always give the same base.
    \ksort($exposed);
    foreach ($exposed as $component) {
      $graph = ComponentGraph::of($component);
      $drupal_settings = $this->drupalSettingsResolver->forGraph($graph);
      if ($drupal_settings === NULL) {
        continue;
      }
      $main_module = $this->componentModules->mainModule($graph, $drupal_settings->module(), self::FRAMEWORK);
      if ($main_module === NULL || isset($modules[$main_module->url])) {
        continue;
      }
      // Runners take base modules only as text.
      $component_sources = $main_module->sources();
      if (!self::allText($component_sources)) {
        continue;
      }

      $hydrates = $checker?->hydrates($graph->root, $graph->dependencies);
      if ($checker !== NULL && $hydrates === NULL) {
        // A parse that gave up for now may not next time, so a base without
        // the component is kept only as briefly as a transient error.
        if ($this->componentModules->parseGaveUpForNow($graph)) {
          $cacheability->mergeCacheMaxAge(self::GAVE_UP_MAX_AGE);
        }
        continue;
      }

      // A component whose imports don't all load is kept too. A runner may
      // report its main module as unloadable, and renders of it then fall
      // back to CSR without invoking.
      $modules += $component_sources;
      $component_modules += $main_module->componentSources();
      $compile[] = $main_module->url;
      // The main module doesn't import it.
      $compile[] = $main_module->librariesUrl;
      $libraries += \array_fill_keys($this->componentModules->libraryImports($graph), TRUE);
      if ($hydrates !== NULL) {
        $components[$main_module->url] = $hydrates;
      }
    }

    // First, so the realm is locked before any other module evaluates. Every
    // main module imports it first too, but a preload module needn't be one.
    $preload = [];
    if (isset($modules[SsrOnlyModuleProvider::REALM_SETUP_PATH])) {
      $preload[] = SsrOnlyModuleProvider::REALM_SETUP_PATH;
    }
    // Then the site data, so a library can read it as it evaluates, as it
    // can when a render imports it after the component's drupalSettings
    // module.
    $site_data = $this->drupalSettingsResolver->forSiteData()->module();
    $preload[] = self::addBaseModule($modules, 'site-data', $site_data);
    // Each library on its own, so one that fails to load or evaluate leaves
    // the others evaluated. One the import map doesn't resolve fails in
    // every render, as it would here. The framework renderer and the
    // hydration data module go first, as a main module imports them before
    // its component, and so before any library the component imports.
    $first = [
      SsrOnlyModuleProvider::rendererSpecifier(self::FRAMEWORK),
      SsrOnlyModuleProvider::HYDRATION_DATA_SPECIFIER,
    ];
    $libraries = \array_diff_key($libraries, \array_flip($first));
    \ksort($libraries, SORT_STRING);
    foreach ([...$first, ...\array_keys($libraries)] as $specifier) {
      $specifier = (string) $specifier;
      if ($ssr_import_map->resolves($specifier)) {
        $preload[] = self::addBaseModule($modules, 'library', 'import ' . \json_encode($specifier, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n");
      }
    }
    if ($ssr_import_map->hasUnscopedImport('tailwind-merge') && isset($modules[SsrOnlyModuleProvider::TW_MERGE_WARMUP_PATH])) {
      $preload[] = SsrOnlyModuleProvider::TW_MERGE_WARMUP_PATH;
    }

    $component_modules = \array_diff_key(\array_intersect_key($modules, $component_modules), $provided_sources);
    $modules = $this->moduleEvaluationHooks->apply(\array_intersect_key($modules, $provided_sources), $import_map, provided: TRUE)
      + $this->moduleEvaluationHooks->apply($component_modules, $import_map, component: TRUE)
      + $this->moduleEvaluationHooks->apply(\array_diff_key($modules, $provided_sources, $component_modules), $import_map);

    $base = ['modules' => $modules, 'preload' => $preload, 'compile' => $compile, 'importMap' => $import_map];

    return [$base, $components];
  }

  /**
   * Adds a module only the base has, named by its source.
   *
   * @param array<string, string> $modules
   *   The base's modules, added to.
   * @param string $kind
   *   What it is, which names its directory.
   * @param string $source
   *   Its source.
   *
   * @return string
   *   Its module URL.
   */
  private static function addBaseModule(array &$modules, string $kind, string $source): string {
    $url = self::PATH_PREFIX . $kind . '/' . \substr(\hash('sha256', $source), 0, 32) . '.js';
    $modules[$url] = $source;

    return $url;
  }

  /**
   * Checks whether every source is valid UTF-8.
   *
   * @param array<string, string> $sources
   *   Sources keyed by module URL.
   *
   * @return bool
   *   TRUE if they all are.
   */
  private static function allText(array $sources): bool {
    foreach ($sources as $source) {
      if (!\mb_check_encoding($source, 'UTF-8')) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
