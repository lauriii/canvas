<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\ComponentInfo\BoundaryCheckers;
use Drupal\canvas_ssr\ComponentInfo\ComponentGraph;
use Drupal\canvas_ssr\ComponentInfo\ComponentModules;
use Drupal\canvas_ssr\ComponentInfo\MainModule;
use Drupal\canvas_ssr\ComponentInfo\MainModuleResult;
use Drupal\canvas_ssr\DrupalSettings\DrupalSettingsResolver;
use Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings;
use Drupal\canvas_ssr\Fetch\Exception\FetchRefusedException;
use Drupal\canvas_ssr\Fetch\FetchedCacheability;
use Drupal\canvas_ssr\Fetch\FetchingLibraries;
use Drupal\canvas_ssr\Fetch\FetchInterface;
use Drupal\canvas_ssr\Fetch\Marshaler\FetchMarshaler;
use Drupal\canvas_ssr\JsModules\BaseModules;
use Drupal\canvas_ssr\JsModules\ModuleEvaluationHooks;
use Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider;
use Drupal\canvas_ssr\JsModules\SsrImportMap;
use Drupal\canvas_ssr\JsModules\UnusableBaseException;
use Drupal\canvas_ssr\JsRunner\Exception\MemoryLimitException;
use Drupal\canvas_ssr\JsRunner\Exception\RunnerBusyException;
use Drupal\canvas_ssr\JsRunner\Exception\ScriptException;
use Drupal\canvas_ssr\JsRunner\Exception\TimeLimitException;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\DestructableInterface;
use Psr\Log\LoggerInterface;

/**
 * Renders an island to HTML on the server.
 *
 * Decides whether SSR can render the component's graph, resolves its
 * drupalSettings, registers modules, binds the same-site fetch, and collects
 * the render's cacheability. It takes props and rendered slot HTML, and returns
 * HTML and cacheability.
 *
 * Imports resolve through the SSR import map: the site import map with the
 * provided modules' specifiers added. AstroIslandSsr checks the island ships
 * the site import map.
 *
 * Framework support is a framework renderer, like js/renderers/preact.js.
 * The component's main module imports it by a specifier the SSR import map
 * has only for a supported framework.
 *
 * A render that produces no markup falls back to CSR, cached by why: a bail
 * or a script failure as long as a render would be, a runner failure
 * briefly. See ADR 2. Exceptions from loading the component entities
 * propagate, since they'd break CSR too.
 *
 * A throw while a module evaluates, or an import that can't load, fails the
 * invoke, as a script failure, and the runner keeps its realm, as a browser
 * keeps its page after a throw. So does a result that never settles, where
 * the runner can tell. Where it can't, the process waiting on it ends, a
 * runner failure. A throw while the framework renderer renders the
 * component is caught by the main module and returned instead: only the
 * component's code failed, and the framework renderer restores its own
 * state. Both are script failures.
 *
 * What it remembers about the base lasts until the end of the request,
 * even where a PHP process serves many requests with the same services.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface
 * @see docs/adr/0002-any-failure-falls-back-to-csr.md
 */
final class Renderer implements DestructableInterface {

  /**
   * What starts the message of a render that refused its own markup.
   *
   * The framework renderer throws it when markup would parse differently in
   * the browser than what the component renders there, as when a script's
   * content would end the script early.
   *
   * @see js/renderers/preact.js
   */
  private const MISMATCH_PREFIX = 'SSR mismatch: ';

  /**
   * Starts what's thrown for hydration data devalue can't encode.
   *
   * @see js/hydration-data.js
   */
  private const string HYDRATION_DATA_PREFIX = 'SSR hydration data: ';

  /**
   * The most hydration data an island carries, in bytes in the page.
   *
   * That's as escaped for its attribute, where each quote takes six bytes,
   * and devalue's encoding has many. The page carries it ahead of the
   * island's markup, and the render cache keeps it with each island. A
   * hydrated island whose libraries provided more falls back to CSR, as its
   * first render in the browser would need all of it.
   */
  private const int HYDRATION_DATA_MAX_BYTES = 131072;

  /**
   * Starts what's thrown for an early read of an island's data.
   *
   * That's page data or interface translations, read while a module
   * imported during the render evaluates.
   *
   * @see js/realm-setup.js
   */
  private const string EARLY_READ_PREFIX = 'SSR early read: ';

  /**
   * Starts what a component's module throws for changing the realm.
   *
   * That's a change to the globals, console or preact's options as it
   * evaluates.
   *
   * @see js/realm-setup.js
   */
  private const string MODULE_SCOPE_PREFIX = 'SSR module scope: ';

  /**
   * Starts it when something an island's modules left running changed them.
   *
   * The modules themselves evaluated, so a later island that imports one
   * wouldn't fail on it, but would render without the change. So such
   * islands fall back to CSR too, until the runner is reset.
   */
  private const string LEFT_RUNNING_PREFIX = self::MODULE_SCOPE_PREFIX . "after the island's modules evaluated";

  /**
   * How long, in seconds, to cache a result of a transient error.
   *
   * That's a CSR fallback for a runner failure, or markup after a fetch
   * that failed or got a server error. Not 0, which would stop the whole
   * page being cached, and retry SSR on every view while the cause lasts. A
   * CSR fallback is still correct, so serving it for a minute is fine.
   *
   * @see self::TRANSIENT_ERROR_TAG
   */
  private const int BRIEF_MAX_AGE = 60;

  /**
   * The longest a fallback for what an earlier island's fetch got is cached.
   *
   * What ruled the fetch out may have been that island's own, like a URL
   * from one of its props. The island's fallback ends when its host is
   * saved, but a later island elsewhere on the page may be cached apart
   * from that host, where nothing would tell. So its fallback isn't kept
   * for good. Only the render cache and dynamic page cache go by this: the
   * page isn't made to expire with it, as it is for a transient error.
   */
  private const int TAKEN_ON_MAX_AGE = 3600;

  /**
   * How many times an island's unsettled imports are waited for.
   *
   * Each round waits for those pending. More than a few means each import
   * makes another, without end.
   *
   * @see self::settleImports()
   */
  private const int IMPORT_ROUNDS = 100;

  /**
   * How long an island's unsettled imports are waited for, in seconds.
   *
   * Each round is an invoke with a time limit of its own, so without this
   * an island could run for all of them, one after another. A module that's
   * registered loads in milliseconds.
   *
   * @see self::settleImports()
   */
  private const int IMPORT_SECONDS = 2;

  /**
   * The cache tag on a result of a transient error.
   *
   * Drupal's page cache ignores max-age, so a page with such an island would
   * otherwise be served long after the error passed. TransientErrorSubscriber
   * makes a page with this tag expire with its max-age, from every cache.
   * Nothing invalidates it.
   *
   * @see \Drupal\canvas_ssr\EventSubscriber\TransientErrorSubscriber
   */
  public const string TRANSIENT_ERROR_TAG = 'canvas_ssr_transient_error';

  /**
   * Matches what JS engines say for a write to something frozen.
   *
   * The realm setup module freezes the built-ins, and every module's
   * exports once it evaluates, so a component that writes to one throws a
   * TypeError. Only its message tells it from other TypeErrors, and engines
   * word it differently, so this lists the wordings of the engines runners
   * are likeliest to use: V8's, as in Deno and Node, then QuickJS's, then
   * JavaScriptCore's, as in Bun. For each: setting a read-only property,
   * adding one to an object that isn't extensible, and deleting or
   * redefining one that isn't configurable.
   *
   * The list isn't complete. A runner whose engine words these another way
   * loses only the warning. Some engines word other mistakes the same way,
   * like assigning to a const, so those get the warning too.
   *
   * @see self::warnOfFrozenWrite()
   */
  private const string FROZEN_WRITE = "/Cannot assign to read only property|is not extensible|Cannot (?:delete|redefine) property"
    . "|'[^']*' is read-only|could not delete property|property is not configurable"
    . "|Attempted to assign to readonly property|Attempting to change [^.]*(?:readonly|unconfigurable) property|Unable to delete property/";

  /**
   * The cache tag on a CSR fallback for a base the runner can't use yet.
   *
   * The fallback is cached like any result, not for a minute, since a base
   * may take a long time to become usable, and a site may have many pages.
   * A runner that can use the base again invalidates it, so those islands
   * render with SSR next time. The fallback also has the base's own tags,
   * so a new base ends it too.
   *
   * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::useBase()
   */
  public const string BASE_PENDING_TAG = 'canvas_ssr_base_pending';

  /**
   * The cacheability of what the current render fetched.
   *
   * The fetch binding outlives a render, so it reads this property instead
   * of capturing its own. It's set only while an invoke runs. An island
   * rendered from inside one sees it set, and so falls back before it could
   * use the runner. See render().
   */
  private ?FetchedCacheability $currentFetchCacheability = NULL;

  /**
   * The module URLs of the current render's component graph.
   *
   * Set and cleared with the property above.
   *
   * @var list<string>
   */
  private array $currentModuleUrls = [];

  /**
   * Those of the current render's components a library may fetch for.
   *
   * Set and cleared with the property above.
   *
   * @var list<string>
   *
   * @see \Drupal\canvas_ssr\Fetch\FetchingLibraries
   */
  private array $currentLibraryFetchers = [];

  /**
   * The machine name of the current render's root component.
   *
   * Set and cleared with the property above.
   */
  private ?string $currentComponent = NULL;

  /**
   * The cache tags of what the current render depends on.
   *
   * Set and cleared with the property above.
   *
   * @var list<string>
   */
  private array $currentDependencyTags = [];

  /**
   * What each component module's code fetched since the runner was reset.
   *
   * A component's module evaluates once in a realm, so a later island of
   * the component reads what the module kept: what it fetched as it
   * evaluated, or what an earlier island's render stored in it. That island
   * makes no fetch of its own, so it takes on what the module's code
   * fetched. A fetch is recorded for the island's component modules with
   * code on its call stack, and, when the stack names a library that
   * fetches for the components importing it, for those components too. A
   * fetch whose call stack names neither is recorded for all of them.
   *
   * @var array<string, \Drupal\canvas_ssr\Fetch\FetchedCacheability>
   *
   * @see self::recordFetch()
   */
  private array $fetchedByModule = [];

  /**
   * Why islands that import a component's module fall back, by module URL.
   *
   * Each is from an island whose modules left something running that
   * changed what islands share, since the runner was last reset: what the
   * realm setup module said, the island's component, and the cache tags of
   * what the island depended on.
   *
   * Which module left it running can't be told, so each the island's
   * invoke evaluated is recorded. One an earlier island evaluated can't
   * have, since what it left running had run before that island rendered.
   * So a later island that imports one falls back only until what the
   * island depended on changes, as when its component is fixed.
   *
   * @var array<string, array{reason: string, component: string, tags: list<string>}>
   */
  private array $leftRunningByModule = [];

  /**
   * The component modules evaluated since the runner was last reset, as keys.
   *
   * Those of each island whose main module ran, so its static imports had
   * all evaluated.
   *
   * @var array<string, true>
   */
  private array $evaluatedComponentModules = [];

  /**
   * The libraries evaluated since the runner was last reset, as keys.
   *
   * @var array<string, true>
   */
  private array $evaluatedLibraries = [];

  /**
   * The components of the islands invoked in the realm, in order.
   *
   * A realm's memory is shared by the islands invoked in it, so one that
   * runs out may have been left too little by these, whatever came of
   * them.
   *
   * @var list<string>
   */
  private array $realmComponents = [];

  /**
   * The components an island that just ran out of memory rendered after.
   *
   * Set when it's to render again in a new realm, and NULL otherwise.
   *
   * @var list<string>|null
   */
  private ?array $renderedBefore = NULL;

  /**
   * Whether the island is rendering again, having run out of memory.
   */
  private bool $renderingAgain = FALSE;

  /**
   * Whether the island just ran out of memory with a realm to itself.
   *
   * It would again with the same inputs, so failed() caches its fallback
   * as a render would be.
   */
  private bool $ranOutAlone = FALSE;

  /**
   * Whether an invoke failed before its main module ran.
   *
   * What its modules left behind is then dropped before the next island's
   * modules evaluate, by invoking its libraries module.
   */
  private bool $invokeFailed = FALSE;

  /**
   * BaseModules::changes() when the runner's registrations were made.
   */
  private int $baseChanges = 0;

  /**
   * BaseModules::changes() when the base last failed, or NULL.
   */
  private ?int $baseFailedAt = NULL;

  /**
   * What the base depends on, when it failed the same way it will again.
   *
   * NULL when it failed for a reason that may pass, which is a transient
   * error.
   */
  private ?CacheableMetadata $baseFailure = NULL;

  /**
   * BaseModules::changes() when the runner couldn't use the base yet.
   *
   * NULL if it hasn't said so. It isn't asked again until the base changes,
   * since asking may wait.
   */
  private ?int $basePendingAt = NULL;

  public function __construct(
    private readonly BoundaryCheckers $boundaryCheckers,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly DrupalSettingsResolver $drupalSettingsResolver,
    private readonly RunnerInterface $runner,
    private readonly FetchInterface $fetcher,
    private readonly FetchMarshaler $fetchMarshaler,
    private readonly LoggerInterface $logger,
    private readonly BaseModules $baseModules,
    private readonly SsrImportMap $ssrImportMap,
    private readonly ComponentModules $componentModules,
    private readonly HostStatus $hostStatus,
    private readonly ModuleEvaluationHooks $moduleEvaluationHooks,
    private readonly LoggerInterface $throttledLogger,
    private readonly FetchingLibraries $fetchingLibraries,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Forgets that the base failed or was pending, so the next request asks
   * the runner again. A base built after this response would otherwise go
   * unused by a process that keeps its services.
   */
  public function destruct(): void {
    // RunnerGuard resets the runner now too.
    $this->forgetRealm();
    $this->baseFailedAt = NULL;
    $this->baseFailure = NULL;
    $this->basePendingAt = NULL;
  }

  /**
   * Renders an island to HTML.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The island's component.
   * @param array<string, mixed> $props
   *   The props to render the component with.
   * @param array<string, string> $slots
   *   Rendered slot HTML keyed by slot name. Passed as props of the same
   *   name, overriding props.
   * @param string $framework
   *   The island's framework, which picks the boundary checker and the
   *   framework renderer. Without both, the island falls back to CSR.
   * @param bool $is_preview
   *   Whether this is a preview, which renders drafts. Other renders use
   *   published components only.
   *
   * @return \Drupal\canvas_ssr\RenderResult
   *   The HTML and its cacheability, or no HTML to fall back to CSR.
   */
  public function render(
    JavaScriptComponent $component,
    array $props,
    array $slots,
    string $framework,
    bool $is_preview = FALSE,
  ): RenderResult {
    $result = $this->renderInRealm($component, $props, $slots, $framework, $is_preview);
    if ($this->renderedBefore === NULL) {
      return $result;
    }

    // It ran out of memory in a realm earlier islands had used some of. The
    // runner has thrown that realm away, so it renders once more, with a
    // new realm's memory to itself.
    $before = $this->renderedBefore;
    $this->renderedBefore = NULL;
    $this->renderingAgain = TRUE;
    try {
      $result = $this->renderInRealm($component, $props, $slots, $framework, $is_preview);
    }
    finally {
      $this->renderingAgain = FALSE;
    }
    if ($result->html !== NULL) {
      // Nothing else says so, and a page of such components renders each
      // of them twice.
      $this->throttledLogger->warning('The {component} component ran out of memory on its first server-side render, and rendered on its second, in a new realm. The islands rendered before it had used part of the first realm\'s memory: {before}. One of their components may keep more than it needs to.', [
        'component' => $component->id(),
        'before' => \implode(', ', \array_unique($before)),
      ]);
    }
    return $result;
  }

  /**
   * Renders an island in the runner's realm.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The component.
   * @param array<string, mixed> $props
   *   The props.
   * @param array<string, string> $slots
   *   The slots.
   * @param string $framework
   *   The island's framework.
   * @param bool $is_preview
   *   Whether this is a preview.
   *
   * @return \Drupal\canvas_ssr\RenderResult
   *   The HTML and its cacheability, or no HTML to fall back to CSR.
   *
   * @see self::render()
   */
  private function renderInRealm(
    JavaScriptComponent $component,
    array $props,
    array $slots,
    string $framework,
    bool $is_preview,
  ): RenderResult {
    // An island rendered from inside another's invoke, as on the error page
    // a fetch got, can't use the runner. It's refused before anything is
    // resolved for it: its site and page data would be the subrequest's,
    // kept for the islands the page renders next, and what it's taken to
    // have fetched would replace the outer island's record. It recurs every
    // time, and every island varies by whether it's inside another, so the
    // fallback is cached as a render.
    if ($this->currentFetchCacheability !== NULL) {
      return RenderResult::clientSide();
    }

    // Registrations last until reset. If what defines the base changed
    // since they were made, as when a request pushes and then renders, some
    // would disagree with the new base. Start over, so none outlive what
    // they came from.
    $base_changes = $this->baseModules->changes();
    if ($base_changes !== $this->baseChanges) {
      $this->baseChanges = $base_changes;
      $this->runner->reset();
      $this->forgetRealm();
    }

    // Before checking availability, since the runner may need the base to
    // start. The base brings the SSR import map imports resolve through. A
    // base that failed this request fails the rest of its islands too, so
    // it isn't built from again for each.
    $base = FALSE;
    $base_pending = $this->basePendingAt === $base_changes;
    if ($this->baseFailedAt !== $base_changes && !$base_pending) {
      // Whether the base can be defined at all, whatever the runner does
      // with the closure's exception. Every other failure, a database error
      // defining it, say, may pass.
      $this->baseFailure = NULL;
      $get_definition = function (): array {
        try {
          return $this->baseModules->getDefinition();
        }
        catch (UnusableBaseException $e) {
          $this->baseFailure = CacheableMetadata::createFromObject($e);
          throw $e;
        }
      };
      try {
        $base = $this->runner->useBase($this->baseModules->id(), $get_definition);
        if ($base === FALSE) {
          // A fallback is cached until the runner builds the base, so only
          // when it says it will.
          $base_pending = $this->runner->hasPendingBase();
          if ($base_pending) {
            $this->basePendingAt = $base_changes;
          }
          else {
            $this->baseFailedAt = $base_changes;
          }
        }
      }
      catch (\Throwable $e) {
        $base = FALSE;
        $this->baseFailedAt = $base_changes;
        if ($e instanceof UnusableBaseException) {
          $this->baseFailure = CacheableMetadata::createFromObject($e);
        }
        $this->throttledLogger->warning('SSR base unavailable: {message}', ['message' => $e->getMessage()]);
      }
    }
    $base_in_use = $base !== FALSE;
    $unloadable = \is_array($base) ? $base : [];

    // Availability depends on nothing a cache tag could invalidate. A
    // runner becomes available with a deploy, which clears caches anyway.
    if (!$this->runner->isAvailable()) {
      $this->hostStatus->recordRunnerUnavailable();

      return RenderResult::clientSide();
    }
    $this->hostStatus->recordLimitations();

    // Rendering without the base would register every provided module, the
    // slowest way to render. A base the runner isn't ready to use is cached
    // until the runner says it is. One that can't be defined is cached until
    // what it depends on changes. What else stopped the base may pass, so
    // SSR is tried again soon.
    if (!$base_in_use) {
      $fallback = RenderResult::clientSide();
      if ($base_pending) {
        return $fallback->addCacheTags([self::BASE_PENDING_TAG, ...$this->baseModules->cacheTags()]);
      }

      return $this->baseFailure !== NULL
        ? $fallback->addCacheableDependency($this->baseFailure)
        : self::transient($fallback);
    }

    // Without a boundary checker we can't tell whether the markup must
    // hydrate.
    $boundary_checker = $this->boundaryCheckers->get($framework);
    if ($boundary_checker === NULL) {
      return RenderResult::clientSide();
    }

    // Nor without a framework renderer.
    if (!$this->ssrImportMap->hasUnscopedImport(SsrOnlyModuleProvider::rendererSpecifier($framework))) {
      return RenderResult::clientSide()->addCacheableDependency($this->ssrImportMap);
    }

    $graph = ComponentGraph::of($component, $is_preview ? $this->autoSaveManager : NULL);
    // What the markup will depend on. Canvas gives the island its root's
    // cacheability, which covers the components the root imports but not
    // theirs. The markup holds the whole graph, so saving any component in
    // it must invalidate the island.
    $dependencies = new CacheableMetadata();
    foreach ($graph->all() as $graph_component) {
      $dependencies->addCacheableDependency($graph_component);
    }
    // Resolve the site and page data the graph declares, and its interface
    // translations. A change to any component in the graph could undo
    // either bail below.
    $drupal_settings = $this->drupalSettingsResolver->forGraph($graph);
    if ($drupal_settings === NULL) {
      return RenderResult::clientSide()->addCacheableDependency($dependencies);
    }

    // No compiled JS means nothing to invoke, so bail.
    $main_module = $this->componentModules->mainModule($graph, $drupal_settings->module(), $framework);
    if ($main_module === NULL) {
      $this->logger->debug(
        'SSR bail: component {component} has no compiled JavaScript.',
        ['component' => $component->id()]
      );
      return RenderResult::clientSide()->addCacheableDependency($dependencies);
    }

    // The markup also depends on the site and page data, the interface
    // translations, and the import map its imports resolve through. What it
    // fetches is added as it's known.
    $dependencies->addCacheableDependency($drupal_settings);
    $dependencies->addCacheableDependency($this->ssrImportMap);

    // A module a component imports by URL isn't known as one of its
    // libraries or components, so it isn't evaluated ahead and what it
    // fetches isn't recorded against it. A library has a name, and another
    // component is "@/components/…".
    foreach ($this->componentModules->urlImports($graph) as [$importer, $specifier]) {
      $this->throttledLogger->warning('Rendering the {component} component on the client, since the {importer} component imports "{specifier}" by its URL, which SSR doesn\'t let a component do. Import a library by its name, and another component as "@/components/…".', [
        'component' => $component->id(),
        'importer' => $importer,
        'specifier' => $specifier,
      ]);
      return self::addRenderDependencies(RenderResult::clientSide(), $dependencies);
    }
    $payload = self::buildPayload($props, $slots, $drupal_settings, $is_preview);

    // If the base covers the component, invoke its main module as it is.
    // Registering its modules would add the same sources under the same
    // module URLs, so it couldn't change the result.
    $base_hydrates = $this->baseModules->componentHydrates($main_module->url);
    $fetched = $this->fetchedWith($main_module->componentUrls);
    // What its modules may hold already rules its markup out, so there's
    // nothing to render for.
    $fallback = $this->fetchFallback($fetched, $dependencies, (string) $component->id(), TRUE);
    if ($fallback !== NULL) {
      return $fallback;
    }
    foreach ($main_module->componentUrls as $url) {
      if (isset($this->leftRunningByModule[$url])) {
        return $this->leftRunningFallback((string) $component->id(), $this->leftRunningByModule[$url], $dependencies, $fetched);
      }
    }
    if ($base_hydrates !== NULL) {
      try {
        // An unloadable main module would only fail, the same way until the
        // base changes, so the fallback is cached as a render would be.
        if (\in_array($main_module->url, $unloadable, TRUE)) {
          $this->logger->debug('SSR bail: base component {main} cannot load.', ['main' => $main_module->url]);
          return self::addRenderDependencies(RenderResult::clientSide(), $dependencies);
        }
        return $this->invokeMainModule($main_module, $payload, $base_hydrates, $dependencies, $fetched, (string) $component->id());
      }
      catch (\Throwable $e) {
        // The base covers components whose imports don't resolve, which
        // fail here as they would anywhere.
        $this->logger->debug(
          'SSR failed: base component {main}: {message}',
          ['main' => $main_module->url, 'message' => $e->getMessage()]
        );
        return $this->failed($e, $dependencies, $fetched, (string) $component->id());
      }
    }

    // The base recorded whether its components hydrate. For one it doesn't
    // cover, the boundary checker reads the graph's sources.
    $hydrates = $boundary_checker->hydrates($graph->root, $graph->dependencies);
    if ($hydrates === NULL) {
      $this->logger->debug(
        'SSR bail: whether component {component} hydrates cannot be told.',
        ['component' => $component->id()]
      );
      $fallback = RenderResult::clientSide()->addCacheableDependency($dependencies);

      // A parse that ran out of time, or of what the request left it, may
      // not next time. One that can't succeed recurs until the component
      // changes, which its tags say.
      return $this->componentModules->parseGaveUpForNow($graph) ? self::transient($fallback) : $fallback;
    }

    try {
      // Imports load as they're reached, so order doesn't matter. The base
      // covers every provided module, and registering the same source again
      // does nothing, so a repeat island registers nothing new.
      $import_map = $this->ssrImportMap->get();
      $sources = $this->moduleEvaluationHooks->apply($main_module->componentSources(), $import_map, component: TRUE)
        + $this->moduleEvaluationHooks->apply($main_module->generatedSources(), $import_map);
      foreach ($sources as $url => $source) {
        $this->runner->addModule($url, $source);
      }

      return $this->invokeMainModule($main_module, $payload, $hydrates, $dependencies, $fetched, (string) $component->id());
    }
    // A script failure or a runner failure. The runner is ready for the
    // next island either way.
    catch (\Throwable $e) {
      $this->logger->debug(
        'SSR failed: {message}',
        ['message' => $e->getMessage()]
      );
      return $this->failed($e, $dependencies, $fetched, (string) $component->id());
    }
  }

  /**
   * Forgets what's known of the runner's realm, when the runner is reset.
   */
  private function forgetRealm(): void {
    $this->fetchedByModule = [];
    $this->leftRunningByModule = [];
    $this->evaluatedComponentModules = [];
    $this->evaluatedLibraries = [];
    $this->realmComponents = [];
    $this->invokeFailed = FALSE;
  }

  /**
   * Starts an island's fetched cacheability from what its modules may hold.
   *
   * That's what the code of each of its component modules fetched since
   * the realm began, in whatever island. The island's own fetches are
   * added to it as they're made.
   *
   * A module that keeps what another module's code fetched isn't covered,
   * unless it imports that module: a library that keeps what a component's
   * function fetched for it, say, or a component that keeps what its
   * importer handed it. An island that reads it from there takes on
   * nothing.
   *
   * @param list<string> $module_urls
   *   The module URLs of the island's component graph.
   *
   * @return \Drupal\canvas_ssr\Fetch\FetchedCacheability
   *   The cacheability, which the fetch binding adds to.
   */
  private function fetchedWith(array $module_urls): FetchedCacheability {
    $fetched = new FetchedCacheability();
    foreach ($module_urls as $url) {
      if (isset($this->fetchedByModule[$url])) {
        $fetched->inherit($this->fetchedByModule[$url]);
      }
    }

    return $fetched;
  }

  /**
   * Falls back to CSR if what an island fetched, or took on, rules SSR out.
   *
   * SSR refuses fetches the browser makes, so markup made after a failed
   * one may not be what the browser renders, even if the component handled
   * the rejection. One that fails the same way every time is warned of,
   * since the component may work in the browser, so its author may never
   * notice it isn't rendered on the server.
   *
   * Markup made from a response whose cacheability isn't known can't be
   * cached as it is. Whether that's so depends on the endpoint, not on the
   * render, so the fallback is cached as a render would be.
   *
   * A fallback for what the island took on is cached for at most
   * TAKEN_ON_MAX_AGE.
   *
   * @param \Drupal\canvas_ssr\Fetch\FetchedCacheability $fetched
   *   What the island fetched, with what it took on.
   * @param \Drupal\Core\Cache\CacheableMetadata $dependencies
   *   What the markup would depend on, besides what it fetches.
   * @param string $component
   *   The root component's machine name, for the log.
   * @param bool $taken_on
   *   Whether it's what the island took on, before it rendered. After, it's
   *   what the island fetched itself.
   *
   * @return \Drupal\canvas_ssr\RenderResult|null
   *   The fallback, or NULL if neither rules SSR out.
   */
  private function fetchFallback(FetchedCacheability $fetched, CacheableMetadata $dependencies, string $component, bool $taken_on): ?RenderResult {
    if ($fetched->failure !== NULL) {
      $context = ['component' => $component, 'fetcher' => $fetched->failedIn, 'reason' => $fetched->failure];
      if (!$fetched->failureRecurs) {
        $this->logger->debug($taken_on
          ? 'SSR bail: component {component} may read what a fetch of component {fetcher} got, which failed: {reason}'
          : 'SSR bail: a fetch of component {component} failed: {reason}', $context);
      }
      elseif (!$taken_on) {
        $this->throttledLogger->warning('Rendering the {component} component on the client, since SSR can\'t make a fetch it makes, which may work in the browser: {reason}', $context);
      }
      elseif ($fetched->failedIn === $component) {
        $this->throttledLogger->warning('Rendering the {component} component on the client, since an earlier island of it made a fetch SSR can\'t make. Its code may have kept what the fetch got, which may differ in the browser: {reason}', $context);
      }
      else {
        $this->throttledLogger->warning('Rendering the {component} component on the client, since it shares code with the {fetcher} component, which rendered earlier and made a fetch SSR can\'t make. That code may have kept what the fetch got, which may differ in the browser: {reason}', $context);
      }
    }
    elseif ($fetched->unknown) {
      $this->logger->debug($taken_on
        ? 'SSR bail: component {component} may read what an earlier island fetched, a response whose cacheability is not known.'
        : 'SSR bail: component {component} fetched a response whose cacheability is not known.', ['component' => $component]);
    }
    else {
      return NULL;
    }
    $fallback = self::addRenderDependencies(RenderResult::clientSide(), $dependencies, $fetched);

    return $taken_on ? $fallback->mergeCacheMaxAge(self::TAKEN_ON_MAX_AGE) : $fallback;
  }

  /**
   * Makes a fetch that rules SSR out last only as long as its island does.
   *
   * A later island that takes it on falls back to CSR for what this island
   * did. So its fallback must end when what this island depends on changes,
   * as when its component is fixed. Its own cache tags wouldn't tell.
   *
   * @param \Drupal\canvas_ssr\Fetch\FetchedCacheability $one
   *   What the fetch got.
   */
  private function endsWithItsIsland(FetchedCacheability $one): void {
    $one->addCacheTags($this->currentDependencyTags);
  }

  /**
   * Records what a fetch got, for the current render and for later islands.
   *
   * Later islands take it on through the component modules that may keep
   * it: those with code on the fetch's call stack. That's the module that
   * made the call, and any whose function called into it. When the stack
   * names none of the island's component modules, as when a library made
   * the call after awaiting something, any of them may keep it.
   *
   * A library that fetches for the components that import it may make the
   * call when none of them is on the stack, though one gets what it
   * fetched. So when the stack names such a library's module, the fetch
   * also counts for the island's components that import one. It does when
   * the stack names a component too: a function a component handed to
   * such a library may have awaited something before it fetched, which
   * leaves only its own frame.
   *
   * @param \Drupal\canvas_ssr\Fetch\FetchedCacheability $one
   *   What the fetch got.
   * @param string $request_json
   *   The marshaled request, which carries the call stack.
   */
  private function recordFetch(FetchedCacheability $one, string $request_json): void {
    // In case a call fires outside a render.
    if ($this->currentFetchCacheability === NULL) {
      return;
    }
    $this->currentFetchCacheability->inherit($one);
    $keepers = $this->fetchMarshaler->callers($request_json, $this->currentModuleUrls);
    // With neither on the stack, there's no telling whose fetch it is.
    if ($this->currentLibraryFetchers !== [] && ($keepers !== [] || $this->fetchMarshaler->callers($request_json, $this->fetchingLibraries->moduleUrls()) !== [])) {
      $keepers = \array_unique([...$keepers, ...$this->currentLibraryFetchers]);
    }
    foreach ($keepers === [] ? $this->currentModuleUrls : $keepers as $url) {
      $this->fetchedByModule[$url] ??= new FetchedCacheability();
      $this->fetchedByModule[$url]->inherit($one);
    }
  }

  /**
   * Falls back to CSR after an exception.
   *
   * @param \Throwable $e
   *   What was thrown.
   * @param \Drupal\Core\Cache\CacheableMetadata $dependencies
   *   What the render's markup would have depended on.
   * @param \Drupal\Core\Cache\CacheableMetadata $fetched
   *   What the render fetched before it failed.
   * @param string $component
   *   The component's machine name.
   *
   * @return \Drupal\canvas_ssr\RenderResult
   *   The fallback. A script failure would recur with the same inputs, so
   *   it depends on what a render would. A runner failure may not, so it's
   *   cached briefly.
   */
  private function failed(\Throwable $e, CacheableMetadata $dependencies, CacheableMetadata $fetched, string $component): RenderResult {
    $result = RenderResult::clientSide();
    // The runner refused an invoke while another was running. render()
    // refuses such an island before it gets here, so it's the runner's own
    // guard, and the realm is as it was.
    if ($e instanceof RunnerBusyException) {
      return $result;
    }
    // If it's to render again, that render says how it went.
    if ($this->renderedBefore === NULL) {
      $this->warnOfLimit($component, $e);
    }
    // It had all of a realm's memory, so it would run out again with the
    // same inputs. Another try would only fill the realm once more.
    if ($this->ranOutAlone) {
      $this->ranOutAlone = FALSE;
      return self::addRenderDependencies($result, $dependencies, $fetched);
    }
    if ($e instanceof ScriptException) {
      $this->warnOfModuleScope($component, $e->getMessage());
      $this->warnOfFrozenWrite($component, $e->getMessage());
      return self::addRenderDependencies($result, $dependencies, $fetched);
    }

    return self::transient($result);
  }

  /**
   * Warns when a component's render went past a time or memory limit.
   *
   * It's a runner failure, but rarely one that passes: an accidental
   * infinite loop, say, or heavy work on large data. Past the time limit
   * it's retried soon. Past the memory limit it isn't. The
   * component may still work in the browser, so its author may never
   * notice it isn't rendered on the server. It's throttled, since it may
   * recur on every render of the component.
   *
   * @param string $component
   *   The component's machine name.
   * @param \Throwable $e
   *   Why it failed.
   */
  private function warnOfLimit(string $component, \Throwable $e): void {
    if ($e instanceof TimeLimitException || $e instanceof MemoryLimitException) {
      $this->throttledLogger->warning('Rendering the {component} component on the client, since its server-side render went past a time or memory limit: {message}', [
        'component' => $component,
        'message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Falls back for what an earlier island's modules left running.
   *
   * The fallback ends when what that island depended on changes, as when
   * its component is fixed. The island's own tags wouldn't tell, if the
   * module it imports wasn't the one that did it.
   *
   * @param string $component
   *   The island's component's machine name.
   * @param array{reason: string, component: string, tags: list<string>} $left_running
   *   What the earlier island recorded.
   * @param \Drupal\Core\Cache\CacheableMetadata $dependencies
   *   What the island's markup would have depended on.
   * @param \Drupal\Core\Cache\CacheableMetadata $fetched
   *   What it took on from its modules.
   *
   * @return \Drupal\canvas_ssr\RenderResult
   *   The fallback.
   */
  private function leftRunningFallback(string $component, array $left_running, CacheableMetadata $dependencies, CacheableMetadata $fetched): RenderResult {
    if ($left_running['component'] === $component) {
      $this->warnOfModuleScope($component, $left_running['reason']);
    }
    else {
      $this->throttledLogger->warning('Rendering the {component} component on the client, since it shares code with the {culprit} component, which rendered earlier, and something its modules left running changes what SSR shares between islands: {reason}', [
        'component' => $component,
        'culprit' => $left_running['component'],
        'reason' => self::moduleScopeReason($left_running['reason']),
      ]);
    }
    $result = self::addRenderDependencies(RenderResult::clientSide(), $dependencies, $fetched);
    $result->addCacheTags($left_running['tags']);

    return $result;
  }

  /**
   * Warns when a component's module changed the realm as it evaluated.
   *
   * What a module does as it evaluates lasts for every later island in the
   * realm, whatever its component, so a component's module may not change
   * the globals, console or preact's options then. Nor may what it leaves
   * running, once it has. The component works in the browser, so its author
   * may never notice it isn't rendered on the server. It's throttled, since
   * it recurs on every render of the component.
   *
   * @param string $component
   *   The component's machine name.
   * @param string $message
   *   Why it failed.
   */
  private function warnOfModuleScope(string $component, string $message): void {
    if (\str_contains($message, self::MODULE_SCOPE_PREFIX)) {
      $template = \str_contains($message, self::LEFT_RUNNING_PREFIX)
        ? 'Rendering the {component} component on the client, since something its modules left running changes what SSR shares between islands: {reason}'
        : 'Rendering the {component} component on the client, since a module it imports changes what SSR shares between islands as it evaluates: {reason}';
      $this->throttledLogger->warning($template, [
        'component' => $component,
        'reason' => self::moduleScopeReason($message),
      ]);
    }
  }

  /**
   * Returns the line of a module scope error that says what was changed.
   *
   * @param string $message
   *   The error, which has the module scope prefix.
   *
   * @return string
   *   What follows the prefix, to the end of its line.
   */
  private static function moduleScopeReason(string $message): string {
    $start = (int) \strpos($message, self::MODULE_SCOPE_PREFIX) + \strlen(self::MODULE_SCOPE_PREFIX);

    return (string) \strtok(\substr($message, $start), "\n");
  }

  /**
   * Warns when a component failed by writing to something frozen.
   *
   * Islands share a realm, so it's frozen, but the component works in the
   * browser, so its author may never notice it isn't rendered on the
   * server. It's throttled, since it recurs on every render of the
   * component.
   *
   * @param string $component
   *   The component's machine name.
   * @param string $message
   *   Why it failed.
   */
  private function warnOfFrozenWrite(string $component, string $message): void {
    if (\preg_match(self::FROZEN_WRITE, $message) === 1) {
      $this->throttledLogger->warning('Rendering the {component} component on the client, since it wrote to a built-in or a module export, which SSR freezes so islands can\'t change each other: {message}', [
        'component' => $component,
        'message' => $message,
      ]);
    }
  }

  /**
   * Invokes a component's main module, and pairs its HTML with cacheability.
   *
   * Its libraries module is invoked first, if the realm may not have
   * evaluated one of its libraries, so they evaluate before the component's
   * modules do. An invoke that failed before its main module ran is another
   * reason: the libraries module starts by dropping what it left behind.
   *
   * @param \Drupal\canvas_ssr\ComponentInfo\MainModule $main_module
   *   The main module.
   * @param array<string, mixed> $payload
   *   The payload to render with. Whether the markup hydrates is added.
   * @param bool $hydrates
   *   Whether the markup hydrates in the browser.
   * @param \Drupal\Core\Cache\CacheableMetadata $dependencies
   *   What the markup depends on, besides what it fetches.
   * @param \Drupal\canvas_ssr\Fetch\FetchedCacheability $fetched
   *   Collects what the invoke fetches. The caller keeps it, so a failure
   *   still depends on what was fetched before it.
   * @param string $component
   *   The root component's machine name, for the log.
   *
   * @return \Drupal\canvas_ssr\RenderResult
   *   The result, with no HTML if the component threw or returned nothing.
   *
   * @throws \Throwable
   *   When the invoke fails.
   */
  private function invokeMainModule(
    MainModule $main_module,
    array $payload,
    bool $hydrates,
    CacheableMetadata $dependencies,
    FetchedCacheability $fetched,
    string $component,
  ): RenderResult {
    $before = $this->realmComponents;
    $this->realmComponents[] = $component;
    $first_evaluated = \array_keys(\array_diff_key(\array_fill_keys($main_module->componentUrls, TRUE), $this->evaluatedComponentModules));
    $this->currentComponent = $component;
    $this->currentDependencyTags = $dependencies->getCacheTags();
    $this->currentFetchCacheability = $fetched;
    $this->currentModuleUrls = $main_module->componentUrls;
    $this->currentLibraryFetchers = $main_module->libraryFetchers;
    try {
      // Bind fetch in case the runner was reset. Binding again does nothing
      // otherwise.
      $this->bindFetch();
      $libraries = \array_fill_keys($main_module->libraries, TRUE);
      if ($this->invokeFailed || \array_diff_key($libraries, $this->evaluatedLibraries) !== []) {
        $this->runner->invoke($main_module->librariesUrl, '""');
        $this->invokeFailed = FALSE;
        $this->evaluatedLibraries += $libraries;
      }
      // A hydrated island's main module also returns its hydration data.
      $payload_json = \json_encode($payload + ['hydrates' => $hydrates], JSON_THROW_ON_ERROR);
      $result = MainModuleResult::fromInvoke($this->runner->invoke($main_module->url, $payload_json));
      $this->evaluatedComponentModules += \array_fill_keys($main_module->componentUrls, TRUE);
      if ($result->importsUnsettled && !$this->settleImports()) {
        $this->throttledLogger->warning('Rendering the {component} component on the client, since after {rounds} rounds or {seconds} seconds of waiting, it was still importing modules it didn\'t wait for while it rendered.', [
          'component' => $component,
          'rounds' => self::IMPORT_ROUNDS,
          'seconds' => self::IMPORT_SECONDS,
        ]);
        // They'd go on importing during later islands.
        $this->runner->reset();
        $this->forgetRealm();
        throw new ScriptException('The component went on importing modules after it rendered.');
      }
    }
    // The runner refused it, since another invoke is running. See failed().
    catch (RunnerBusyException $e) {
      $this->realmComponents = $before;
      throw $e;
    }
    catch (ScriptException $e) {
      $this->invokeFailed = TRUE;
      throw $e;
    }
    catch (\Throwable $e) {
      // A runner failure, after which the runner has no realm. If it ran
      // out of the memory that earlier islands left it, render() has it
      // render once more.
      if ($e instanceof MemoryLimitException) {
        if ($before === [] || $this->renderingAgain) {
          $this->ranOutAlone = TRUE;
        }
        else {
          $this->renderedBefore = $before;
        }
      }
      $this->forgetRealm();
      throw $e;
    }
    finally {
      $this->currentFetchCacheability = NULL;
      $this->currentModuleUrls = [];
      $this->currentLibraryFetchers = [];
      $this->currentComponent = NULL;
      $this->currentDependencyTags = [];
    }
    // What it took on didn't rule SSR out, or it wouldn't have rendered. So
    // what does now is its own.
    $fallback = $this->fetchFallback($fetched, $dependencies, $component, FALSE);
    if ($fallback !== NULL) {
      return $fallback;
    }
    if ($result->html !== NULL) {
      $hydration_data = $hydrates ? $result->hydrationData : NULL;
      // As the island's attribute escapes it.
      $page_bytes = $hydration_data === NULL ? 0 : \strlen(Html::escape($hydration_data));
      if ($page_bytes <= self::HYDRATION_DATA_MAX_BYTES) {
        return self::addRenderDependencies(new RenderResult($result->html, $hydrates, $hydration_data), $dependencies, $fetched);
      }
      // Cached as a render would be, since the same data would recur.
      $this->logger->warning('Rendering the {component} component on the client, since its hydration data is {bytes} bytes in the page, more than the {max} an island may carry. Fetch less, such as with a sparse fieldset.', [
        'component' => $component,
        'bytes' => $page_bytes,
        'max' => self::HYDRATION_DATA_MAX_BYTES,
      ]);
      return self::addRenderDependencies(RenderResult::clientSide(), $dependencies, $fetched);
    }

    // The main module caught a script failure, so the runner kept its realm.
    // It would recur with the same inputs, so the fallback is cached as a
    // render would be. A mismatch is warned of, since unlike a component
    // that throws, the component works in the browser, so its author may
    // never notice it isn't rendered on the server.
    $error = (string) $result->error;
    if (\str_contains($error, self::LEFT_RUNNING_PREFIX)) {
      // Should the modules' evaluation have gone untracked, they're all
      // suspects.
      $this->leftRunningByModule += \array_fill_keys($first_evaluated === [] ? $main_module->componentUrls : $first_evaluated, [
        'reason' => $error,
        'component' => $component,
        'tags' => $dependencies->getCacheTags(),
      ]);
    }
    if (\str_starts_with($error, self::MISMATCH_PREFIX)) {
      $this->logger->warning('Rendering the {component} component on the client, since its server-rendered HTML would parse differently: {reason}', [
        'component' => $component,
        'reason' => \substr($error, \strlen(self::MISMATCH_PREFIX)),
      ]);
    }
    elseif (\str_starts_with($error, self::HYDRATION_DATA_PREFIX)) {
      $this->logger->warning('Rendering the {component} component on the client, since its hydration data can\'t be carried to the browser: {reason}', [
        'component' => $component,
        'reason' => \substr($error, \strlen(self::HYDRATION_DATA_PREFIX)),
      ]);
    }
    elseif (\str_contains($error, self::EARLY_READ_PREFIX)) {
      $this->throttledLogger->warning('Rendering the {component} component on the client, since it read page data or interface translations while a module it imported during the render evaluated: {reason}', [
        'component' => $component,
        'reason' => \substr($error, \strpos($error, self::EARLY_READ_PREFIX) + \strlen(self::EARLY_READ_PREFIX)),
      ]);
    }
    else {
      $this->logger->debug('SSR failed: {reason}', ['reason' => $error]);
      $this->warnOfModuleScope($component, $error);
      $this->warnOfFrozenWrite($component, $error);
    }

    return self::addRenderDependencies(RenderResult::clientSide(), $dependencies, $fetched);
  }

  /**
   * Waits for the import() calls an island's components didn't wait for.
   *
   * A runner may finish loading a module only once the invoke that
   * imported it is over. What the component does with it then would happen
   * during a later island, and be taken for that island's. Each invoke of
   * the realm setup module waits for the imports pending, and the runner
   * runs the job queue dry before the invoke ends. So once an invoke finds
   * none pending and none made since the last, nothing is left to make
   * one. It's called during the island's invoke, so what they fetch
   * meanwhile is the island's.
   *
   * @return bool
   *   FALSE if imports were still being made after IMPORT_ROUNDS invokes,
   *   or IMPORT_SECONDS.
   *
   * @throws \Throwable
   *   When an invoke fails.
   */
  private function settleImports(): bool {
    $deadline = \hrtime(TRUE) + self::IMPORT_SECONDS * 1_000_000_000;
    for ($round = 0; $round < self::IMPORT_ROUNDS && \hrtime(TRUE) < $deadline; $round++) {
      if ($this->runner->invoke(SsrOnlyModuleProvider::REALM_SETUP_PATH, '""') === '') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Binds the same-site fetch in the realm.
   *
   * Only the first binding until a reset takes effect, so the closure reads
   * $currentFetchCacheability rather than capturing the render. The
   * marshaler converts requests and responses to and from strings.
   *
   * A failed fetch reaches the component as a rejection, as in the browser, but
   * the island falls back to CSR, since the browser's fetch may have worked. It
   * may not fail next time, so the fallback is cached briefly, as a transient
   * error. A refused one fails the same way every time, so it isn't. Nor is a
   * server error's answer to be relied on, so it's cached briefly too, with
   * what cacheability it has. A response whose cacheability isn't known still
   * reaches the component, as in the browser, but the island falls back to CSR.
   */
  private function bindFetch(): void {
    $this->runner->bindFunction(
      'fetch',
      function (string $request_json): string {
        // The fetch rejects with the reason, as in the browser. A request
        // the component gave that isn't one fails the same way every time,
        // as a refused one does.
        try {
          $request = $this->fetchMarshaler->unmarshalRequest($request_json);
        }
        catch (\InvalidArgumentException | \JsonException $e) {
          return $this->fetchFailed($e, TRUE, $request_json);
        }
        try {
          // The fetcher resolves and checks the request's URL itself.
          $response = $this->fetcher->fetch($request);
        }
        catch (FetchRefusedException $e) {
          return $this->fetchFailed($e, TRUE, $request_json);
        }
        catch (\Throwable $e) {
          return $this->fetchFailed($e, FALSE, $request_json);
        }
        $fetched = new FetchedCacheability();
        if ($response->hasCacheability()) {
          $fetched->addCacheableDependency($response->getCacheableMetadata());
        }
        if ($response->getStatusCode() >= 500) {
          self::transient($fetched);
        }
        elseif (!$response->hasCacheability()) {
          $fetched->unknown = TRUE;
          $this->endsWithItsIsland($fetched);
        }
        try {
          $marshaled = $this->fetchMarshaler->marshalResponse($response);
        }
        catch (\JsonException $e) {
          // JSON carries the body to the realm as text, so one that isn't
          // UTF-8, like an image, can't be returned. It fails the same way
          // until the response changes, which its cacheability tells.
          return $this->fetchFailed(new \RuntimeException('SSR fetch can only return a response whose body is UTF-8 text.', 0, $e), TRUE, $request_json, $fetched);
        }
        $this->recordFetch($fetched, $request_json);

        return $marshaled;
      },
      $this->fetchMarshaler->marshalRequestJs(),
      $this->fetchMarshaler->unmarshalResponseJs(),
    );
  }

  /**
   * Records a failed fetch, and returns it for the fetch to reject with.
   *
   * The browser's fetch may not have failed, so the render's markup isn't
   * used, whatever the component did with the rejection.
   *
   * @param \Throwable $e
   *   Why it failed.
   * @param bool $recurs
   *   Whether it fails the same way every time, as a refused one does.
   *   Otherwise it's a transient error.
   * @param string $request_json
   *   The marshaled request.
   * @param \Drupal\canvas_ssr\Fetch\FetchedCacheability|null $fetched
   *   What the fetch got, if it got a response before it failed.
   *
   * @return string
   *   The marshaled failure.
   */
  private function fetchFailed(\Throwable $e, bool $recurs, string $request_json, ?FetchedCacheability $fetched = NULL): string {
    $fetched ??= new FetchedCacheability();
    if (!$recurs) {
      self::transient($fetched);
    }
    $fetched->failure = $e->getMessage();
    $fetched->failureRecurs = $recurs;
    $fetched->failedIn = $this->currentComponent;
    $this->endsWithItsIsland($fetched);
    $this->recordFetch($fetched, $request_json);

    return $this->fetchMarshaler->marshalFailure($e);
  }

  /**
   * Marks a result as one of a transient error, cached briefly.
   *
   * @param T $cacheability
   *   The result, or what it depends on.
   *
   * @return T
   *   The same.
   *
   * @template T of \Drupal\Core\Cache\RefinableCacheableDependencyInterface
   */
  private static function transient(RefinableCacheableDependencyInterface $cacheability): RefinableCacheableDependencyInterface {
    return $cacheability
      ->mergeCacheMaxAge(self::BRIEF_MAX_AGE)
      ->addCacheTags([self::TRANSIENT_ERROR_TAG]);
  }

  /**
   * Adds what a render depends on to its result.
   *
   * That's every component in the graph, the site and page data, the SSR
   * import map, and anything fetched.
   *
   * @param \Drupal\canvas_ssr\RenderResult $result
   *   The result.
   * @param \Drupal\Core\Cache\CacheableMetadata $dependencies
   *   What the markup depends on, besides what it fetches.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $fetched
   *   What it fetched, if it invoked.
   *
   * @return \Drupal\canvas_ssr\RenderResult
   *   The same result.
   */
  private static function addRenderDependencies(RenderResult $result, CacheableMetadata $dependencies, ?CacheableMetadata $fetched = NULL): RenderResult {
    $result->addCacheableDependency($dependencies);
    if ($fetched !== NULL) {
      $result->addCacheableDependency($fetched);
    }

    return $result;
  }

  /**
   * Builds the payload the main module renders with.
   *
   * Slots are passed as props, and their names are listed so the framework
   * renderer knows which props to turn back into slots.
   *
   * The island's drupalSettings go in the payload rather than a module, so
   * no module's source varies by island.
   *
   * @param array<string, mixed> $props
   *   The props to render with.
   * @param array<string, string> $slots
   *   Rendered slot HTML keyed by slot name.
   * @param \Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings $drupal_settings
   *   The drupalSettings the component's graph gets.
   * @param bool $is_preview
   *   Whether this is a preview. Its JSON:API client reads working copies,
   *   as it does in the browser.
   *
   * @return array{props: array<string, mixed>, slots: array<int, string>, preview: bool, drupalSettings: array<string, mixed>|null, siteDrupalSettings: array<string, mixed>|null}
   *   The payload.
   *
   * @see \Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings::value()
   */
  private static function buildPayload(array $props, array $slots, GraphDrupalSettings $drupal_settings, bool $is_preview): array {
    return [
      // A slot wins over a prop of the same name.
      'props' => $slots + $props,
      // PHP turns numeric keys into ints. Slot names are strings.
      'slots' => \array_map(\strval(...), \array_keys($slots)),
      'preview' => $is_preview,
      // NULL leaves it undefined.
      'drupalSettings' => $drupal_settings->value(),
      // What a module that evaluates during the render gets instead.
      'siteDrupalSettings' => $drupal_settings->siteValue(),
    ];
  }

}
