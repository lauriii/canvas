<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

use Composer\Autoload\ClassLoader;
use Drupal\canvas_ssr\JsRunner\Exception\ScriptException;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\canvas_ssr\Timeouts;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs SSR in a Deno process.
 *
 * This class holds a runner's registrations and its time limits, and
 * decides when the Deno process is replaced. DenoProcess runs the process
 * and speaks its protocol; ModuleSeeder puts registered modules where the
 * process reads them; RunnerCache is the directory they go in; BaseCaches
 * builds and keeps base caches. The Deno process gets no permissions at
 * all; see ADR 9 and the README's security model.
 *
 * The Deno process is sent no modules. ModuleSeeder seeds each registered
 * source into the runner cache at its cache URL, and derives the Deno import
 * map from the base's import map, so a render sends the process just the
 * main module's cache URL.
 *
 * The Deno process holds the realm. Deno reads its import map only at
 * startup and evaluates each URL once. So when another base's import map
 * changes what loads, or a render changes a module already seeded, the
 * runner starts a new Deno process before it invokes: a new realm. The new
 * process reuses the runner cache and gets the same bindings and import
 * map. A site's map rarely changes, so this is rare.
 *
 * Each runner gets its own runner cache, deleted on reset(), so one
 * render's modules can't be reached from another runner's Deno process. If
 * a base is in use, the runner cache starts linked to its base cache: the
 * base's modules and Deno's caches for them, built once per base caches
 * directory. See useBase().
 *
 * It has no Drupal dependencies, so tests can construct it directly.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface
 */
final class DenoRunner implements RunnerInterface {

  /**
   * Where base caches are kept unless the constructor is told otherwise.
   *
   * /var/tmp is kept across reboots, and exists and is world-writable on
   * every Linux distribution and on macOS.
   */
  private const string DEFAULT_BASE_CACHES_ROOT = '/var/tmp';

  /**
   * The most memory a Deno process may use by default, in MiB.
   *
   * The constructor can be given another limit.
   *
   * Pages measured so far peaked at about 100 MiB.
   */
  public const int DEFAULT_MEMORY_LIMIT_MIB = 256;

  /**
   * How many base caches are kept under the runner caches root.
   *
   * They're kept there when the base caches root can't be used.
   *
   * That root is often memory-backed, and a base cache takes a megabyte or
   * two.
   */
  private const int FALLBACK_BASE_CACHES_LIMIT = 10;

  /**
   * The functions PHP needs to start and end a Deno process.
   *
   * Shared hosts often disable them.
   */
  private const array PROCESS_FUNCTIONS = ['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'];

  /**
   * The Deno process, once one has been started.
   */
  private ?DenoProcess $process = NULL;

  /**
   * Each binding's callback, serializer and deserializer, by name.
   *
   * They're kept until reset().
   *
   * Kept so that a new Deno process gets the bindings made before it
   * started.
   *
   * @var array<string, array{callable, string, string}>
   */
  private array $bindings = [];

  /**
   * The registered modules and what has been seeded for them.
   *
   * Outlives the Deno process, since stopping one changes neither. Created
   * lazily, so constructing a runner touches no files.
   */
  private ?ModuleSeeder $moduleSeeder = NULL;

  /**
   * This runner's runner cache, if it has one.
   *
   * NULL before it's needed and after reset().
   */
  private ?RunnerCache $runnerCache = NULL;

  /**
   * The id of the base in use, or NULL.
   *
   * @see self::useBase()
   */
  private ?string $baseId = NULL;

  /**
   * Whether the binary and directories are usable, once checked.
   *
   * Survives reset().
   */
  private ?bool $available = NULL;

  /**
   * Why the runner isn't available, once isAvailable() found it isn't.
   */
  private ?string $unavailableReason = NULL;

  /**
   * Path to the Deno binary.
   */
  private readonly string $denoBinary;

  /**
   * Where every OS user's base caches directory goes.
   */
  private readonly string $baseCachesRoot;

  /**
   * Where every OS user's runner caches directory goes.
   */
  private readonly string $runnerCachesRoot;

  /**
   * This OS user's base caches, once checked.
   *
   * NULL before they're needed, and FALSE when their directory can't be
   * used.
   */
  private BaseCaches|false|null $baseCaches = NULL;

  /**
   * Why the base caches root can't be used, if it can't.
   *
   * Base caches are then under the runner caches root instead.
   */
  private ?string $baseCachesFallback = NULL;

  /**
   * This OS user's runner caches directory, once checked.
   *
   * NULL before it's needed, and FALSE when it can't be used.
   */
  private OsUserDirectory|false|null $runnerCaches = NULL;

  /**
   * Logs cache directories that can't be used, or changed while in use.
   */
  private readonly LoggerInterface $logger;

  /**
   * Whether a changed directory was logged, so it's logged once per runner.
   */
  private bool $directoryChangeLogged = FALSE;

  /**
   * The import map entries of the base in use, from its manifest.
   *
   * They're kept so a new Deno process can be given them.
   *
   * @var array<string, mixed>
   */
  private array $ssrImports = [];

  /**
   * The time limits.
   */
  private readonly Timeouts $timeouts;

  /**
   * Constructs a DenoRunner.
   *
   * @param string|null $denoBinary
   *   Path to the Deno binary. NULL means self::defaultBinary().
   * @param array{short?: int, medium?: int, long?: int} $timeouts
   *   The time limits, in milliseconds, by name. Those left out get
   *   Timeouts::DEFAULTS, which says what each limits.
   * @param string|null $baseCachesRoot
   *   Where base caches are kept, under the OS user's directory. They outlive
   *   requests, so somewhere persistent avoids rebuilding them after a
   *   reboot. NULL means self::DEFAULT_BASE_CACHES_ROOT, which survives
   *   reboots and which any OS user may write, unlike $HOME under a web
   *   server. It's safe to delete: base caches are rebuilt on demand.
   * @param string|null $runnerCachesRoot
   *   Where each runner cache is made, under the OS user's directory, and
   *   deleted on reset(). Every render writes here, so memory-backed
   *   storage such as tmpfs is fastest. NULL means the system temp
   *   directory. It may be on another filesystem from the base caches,
   *   since a runner cache only links to its base cache and copies from
   *   it.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Logs a cache directory that can't be used, or that was deleted or
   *   replaced while in use. Every runner checks its directories, so a
   *   directory that can't be used is logged on every request; the service
   *   passes a logger that limits that. It also notes a render that gave
   *   up waiting for a base cache, which recurs if every new host's disk is
   *   slow at first. NULL logs nothing.
   * @param int $memoryLimitMib
   *   The most memory each Deno process may use, in MiB: the JS heap's
   *   limit, and where the OS reports it, the process's resident memory.
   *   Each PHP worker rendering may have one, so it's this times how many
   *   render at once that a host must have to spare.
   *
   * @see \Drupal\Tests\canvas_ssr\Unit\JsRunner\RunnerConformanceTestBase::testRunnerInterruptsWhatNeverFinishes()
   */
  public function __construct(
    ?string $denoBinary = NULL,
    array $timeouts = [],
    ?string $baseCachesRoot = NULL,
    ?string $runnerCachesRoot = NULL,
    ?LoggerInterface $logger = NULL,
    private readonly int $memoryLimitMib = self::DEFAULT_MEMORY_LIMIT_MIB,
  ) {
    if ($memoryLimitMib < 1) {
      throw new \InvalidArgumentException('The Deno runner\'s memory limit must be a positive number of MiB.');
    }
    $this->timeouts = Timeouts::fromArray($timeouts);
    $this->logger = $logger ?? new NullLogger();
    $this->denoBinary = $denoBinary ?? self::defaultBinary();
    $this->baseCachesRoot = $baseCachesRoot ?? self::DEFAULT_BASE_CACHES_ROOT;
    $this->runnerCachesRoot = $runnerCachesRoot ?? \sys_get_temp_dir();
  }

  /**
   * Returns where the binary is by default: deno in vendor/bin.
   *
   * That's Composer's bin directory unless a project sets bin-dir, which
   * Composer gives no way to read at run time. The vendor directory is
   * found as package_manager's PathLocator finds it.
   *
   * @return string
   *   The path, or '' if no Composer class loader is registered.
   *
   * @see \Drupal\package_manager\PathLocator::getVendorDirectory()
   */
  public static function defaultBinary(): string {
    $vendor_directories = \array_keys(ClassLoader::getRegisteredLoaders());
    foreach ($vendor_directories as $vendor_directory) {
      // The loader for the project this file is in.
      if (\str_starts_with(__FILE__, \dirname($vendor_directory) . '/')) {
        return $vendor_directory . '/bin/deno';
      }
    }

    return isset($vendor_directories[0]) ? $vendor_directories[0] . '/bin/deno' : '';
  }

  /**
   * Kills the Deno process and deletes the runner cache.
   *
   * The process would exit with PHP anyway, but a PHP-FPM worker outlives
   * many requests.
   */
  public function __destruct() {
    $this->reset();
  }

  /**
   * {@inheritdoc}
   *
   * Checks that the binary exists, and that the base caches and runner
   * caches directories are this OS user's alone, making them if needed. It
   * starts nothing. The version isn't checked. A Deno that can't run
   * runner.js fails its renders, which fall back to CSR.
   *
   * A runner checks once, and a runner is made for each request, so a
   * directory replaced between requests is caught by the next one.
   */
  public function isAvailable(): bool {
    if ($this->available === NULL) {
      $binary = $this->denoBinary;
      $disabled = \array_values(\array_filter(self::PROCESS_FUNCTIONS, static fn(string $name): bool => !\function_exists($name)));
      if ($disabled !== []) {
        $this->unavailableReason = \sprintf('The Deno runner is unavailable, since PHP has %s disabled, as the disable_functions setting can do. It needs them to start Deno.', \implode(', ', \array_map(static fn(string $name): string => $name . '()', $disabled)));
        $this->available = FALSE;
      }
      elseif (!\is_file($binary) || !\is_executable($binary)) {
        $this->unavailableReason = \sprintf('The Deno runner is unavailable, since there is no executable Deno binary at "%s".', $binary);
        $this->available = FALSE;
      }
      else {
        $this->available = $this->baseCaches() !== NULL && $this->runnerCachesDirectory() !== NULL;
      }
    }

    return $this->available;
  }

  /**
   * {@inheritdoc}
   */
  public function unavailableReason(): ?string {
    return $this->unavailableReason;
  }

  /**
   * {@inheritdoc}
   *
   * Without /proc, it can't read a Deno process's CPU time or memory. And
   * base caches kept under the runner caches root may not outlast a reboot
   * or a deploy.
   */
  public function limitations(): array {
    $limitations = [];
    if (@\file_get_contents('/proc/self/stat') === FALSE) {
      $limitations[] = \sprintf('The Deno runner can\'t read /proc here, as on macOS or under PHP\'s open_basedir setting. So it limits an invoke\'s time as wall time, not CPU time, which a busy host can exceed, and limits only the JS heap to %d MiB, not all of a Deno process\'s memory.', $this->memoryLimitMib);
    }
    if ($this->baseCachesFallback !== NULL) {
      $limitations[] = \sprintf('The Deno runner keeps base caches under its runner caches root, "%s", and only %d of them, since it can\'t use the base caches root, "%s": %s So they may not outlast a reboot or a deploy, and the first renders after one fall back to CSR while they\'re rebuilt.', $this->runnerCachesRoot, self::FALLBACK_BASE_CACHES_LIMIT, $this->baseCachesRoot, $this->baseCachesFallback);
    }

    return $limitations;
  }

  /**
   * {@inheritdoc}
   *
   * Nothing is seeded or sent yet. The next invoke() seeds what's pending.
   */
  public function addModule(string $url, string $source): void {
    $this->moduleSeeder()->setSource($url, $source);
  }

  /**
   * {@inheritdoc}
   *
   * The first callback bound to a name wins until reset(). The Deno process
   * gets only the name and the two JS expressions, and calls back over the
   * pipe. Binding starts the Deno process if none is running, and a new
   * process gets every binding.
   *
   * A stack trace in what the serializer made of a call names modules by
   * their cache URLs, so the callback gets it with module URLs in their
   * place. The rest of it is left as it is.
   */
  public function bindFunction(
    string $name,
    callable $callback,
    string $serialize_js,
    string $deserialize_js,
  ): void {
    if (!isset($this->bindings[$name])) {
      $callback = static fn (string $argument): string => $callback(ModuleSeeder::restoreFrameUrls(ModuleSeeder::ORIGIN, $argument));
      $this->bindings[$name] = [$callback, $serialize_js, $deserialize_js];
      if ($this->process !== NULL) {
        $this->exchange(static fn(DenoProcess $process) => $process->bind($name, $callback, $serialize_js, $deserialize_js));
        return;
      }
    }
    // A new process gets every binding, so one started here has this one.
    $this->exchange(static fn(DenoProcess $process) => NULL);
  }

  /**
   * {@inheritdoc}
   *
   * The Deno process may spend the short limit on it, not counting time
   * spent in bound functions, and the whole call may take the medium one.
   * They're enforced by killing the Deno process, since an infinite loop in
   * it never yields. A process past half its CPU time limit is replaced
   * afterwards, so a render isn't cut off by it.
   *
   * Pending modules are seeded first. If that fails, this throws and leaves
   * the Deno process alone, since nothing ran.
   *
   * A script failure throws a ScriptException, unless the runner cache has
   * lost files. Deno reports a missing file as a module that doesn't
   * resolve, but then it's the runner cache that's broken, not the JS.
   */
  public function invoke(string $main_module, string $payload): string {
    try {
      $seeder = $this->moduleSeeder();
      // Seeding writes to the runner cache.
      $this->runnerCache()->assertIntact();
      $main = $seeder->mainModuleUrl($main_module);
    }
    catch (OsUserDirectoryChangedException $e) {
      $this->directoryChanged($e);
    }
    // The realm may have evaluated a module's old source, or have an
    // outdated map. Either way the Deno process needs replacing.
    if ($seeder->takeRewritten() || ($this->process !== NULL && $seeder->denoImportMap() !== $this->process->denoImportMap())) {
      $this->stopProcess();
    }

    try {
      $value = $this->exchange(static fn(DenoProcess $process) => $process->invoke($main, $payload));
    }
    catch (ScriptException $e) {
      $message = $seeder->restoreUrls($e->getMessage());
      if (!($this->runnerCache?->exists() ?? FALSE)) {
        $this->reset();
        throw new \RuntimeException($message, 0, $e);
      }
      // A script failure keeps the realm, and the Deno process with it,
      // unless the process had to stop, mid-message.
      if ($this->process?->isStopped() ?? FALSE) {
        $this->stopProcess();
      }
      throw new ScriptException($message, 0, $e);
    }

    if (($this->process?->cpuSecondsAtLastCommand() ?? 0.0) >= $this->timeouts->cpuLimitSeconds() / 2) {
      $this->stopProcess();
    }

    return $seeder->restoreUrls($value);
  }

  /**
   * {@inheritdoc}
   *
   * Kills the Deno process and deletes the runner cache, which drops the
   * bindings, the import map, every registered module and the base in use.
   * Base caches stay.
   */
  public function reset(): void {
    $this->stopProcess();
    $this->bindings = [];
    $this->ssrImports = [];
    $this->baseId = NULL;
    // After the Deno process is gone, so nothing reads the runner cache as
    // it goes.
    $this->removeRunnerCache();
  }

  /**
   * {@inheritdoc}
   *
   * Uses the base cache for this id and this Deno binary, building it once
   * per base caches directory, under an exclusive lock. Other renders wait
   * for it, since rendering without it would be no faster, but only until
   * the build's medium time limit runs out. A base cache holds the base's
   * modules under their own origin, and Deno's caches after preloading them
   * with the base's import map. Its manifest keeps the map's entries, which
   * are in force from now on. A runner cache gets a symlink to the modules
   * and a copy of the caches.
   *
   * A build that doesn't finish in time, or fails, leaves nothing behind.
   * Then this returns FALSE, and the build is recorded in BaseRetry, to be
   * retried with the long limit once a response is sent. Until a base cache
   * is built, renders of that base don't wait: this returns FALSE at once.
   * A new base hasn't failed, so a render tries it as usual. Whoever builds
   * one invalidates Renderer::BASE_PENDING_TAG.
   *
   * It takes effect when the next runner cache is made. A runner that
   * already has one keeps the base cache it links to until reset().
   *
   * The preload modules listed are those whose imports couldn't load when
   * the base cache was built. Those that fail for want of a binding, which
   * the build lacks, aren't listed, nor are those missing a named export.
   *
   * @throws \RuntimeException
   *   When the base caches directory can't be used.
   *
   * @see self::retryBase()
   */
  public function useBase(string $id, \Closure $getDefinition): bool|array {
    $base_caches = $this->baseCaches();
    if ($base_caches === NULL) {
      throw new \RuntimeException('The Deno base caches directory can\'t be used.');
    }
    $manifest = $base_caches->manifestFor($id);
    if ($manifest === NULL) {
      // The record stays until the retry after a response clears it, even
      // if this builds the new base, so that retry invalidates the tag.
      if ((BaseRetry::read($base_caches->path())['base'] ?? NULL) === $id) {
        return FALSE;
      }
      [$id, $base] = $getDefinition();
      try {
        $manifest = $base_caches->ensure($id, $base, $this->timeouts->medium, TRUE);
      }
      catch (OsUserDirectoryChangedException $e) {
        $this->directoryChanged($e);
      }
      catch (\RuntimeException $e) {
        BaseRetry::schedule($base_caches->path(), $id, $e->getMessage());
        // A retry that builds it isn't logged, so a build that keeps running
        // out of time on fresh hosts would go unseen.
        $this->logger->notice('A render gave up on building the SSR base, so islands render in the browser until it\'s built after a response: {message}', ['message' => $e->getMessage()]);

        return FALSE;
      }
      if ($manifest === NULL) {
        return FALSE;
      }
    }
    $this->baseId = $id;
    $this->useImports($manifest->imports);

    return $manifest->unloadable === [] ? TRUE : $manifest->unloadable;
  }

  /**
   * {@inheritdoc}
   *
   * TRUE while a base cache build is recorded in BaseRetry. That costs an
   * lstat and one failed open when there's none.
   *
   * A runner that hasn't used a base yet looks where its base caches would
   * be, without making anything: under the base caches root if this OS
   * user's directory is there, and otherwise under the runner caches root,
   * where baseCaches() falls back to. It reads only from a directory that
   * is this OS user's alone.
   *
   * @see \Drupal\canvas_ssr\JsRunner\Deno\BaseRetry
   */
  public function hasPendingBase(): bool {
    if ($this->baseCaches === FALSE) {
      return FALSE;
    }
    $directory = $this->baseCaches?->path()
      ?? OsUserDirectory::pathUnder($this->baseCachesRoot)
      ?? OsUserDirectory::pathUnder($this->runnerCachesRoot);

    return $directory !== NULL && BaseRetry::read($directory) !== NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Retries the build BaseRetry records, with the long limit, once it's
   * due: after its backoff, or at once for a new base. It doesn't wait for
   * a build another process is running, since that one is retrying
   * already. It leaves the runner as it was.
   *
   * @see \Drupal\canvas_ssr\JsRunner\Deno\BaseRetry::isDue()
   */
  public function buildPendingBase(string $id, \Closure $getDefinition): array|bool|null {
    $base_caches = $this->baseCaches();
    if ($base_caches === NULL) {
      return NULL;
    }
    $record = BaseRetry::read($base_caches->path());
    if ($record === NULL) {
      return NULL;
    }
    if ($base_caches->manifestFor($id) !== NULL) {
      BaseRetry::clear($base_caches->path());

      return TRUE;
    }
    if (!BaseRetry::isDue($record, $id)) {
      return NULL;
    }
    [$id, $base] = $getDefinition();
    try {
      $base_caches->ensure($id, $base, $this->timeouts->long, FALSE);
    }
    catch (BaseCacheBusyException) {
      return NULL;
    }
    catch (OsUserDirectoryChangedException $e) {
      $this->logger->error('SSR stopped using a Deno directory: {message}', ['message' => $e->getMessage()]);

      return NULL;
    }
    catch (\RuntimeException $e) {
      $record = BaseRetry::failed($base_caches->path(), $id, $e->getMessage());

      return ['since' => $record['since'], 'due' => $record['due'], 'error' => $record['error']];
    }
    BaseRetry::clear($base_caches->path());

    return TRUE;
  }

  /**
   * Puts a base's import map entries in force.
   *
   * A running Deno process gets them right away, since import.meta.resolve()
   * reads them on each call. If they change what loads, the next invoke()
   * starts a new process.
   *
   * @param array<string, mixed> $imports
   *   The entries.
   */
  private function useImports(array $imports): void {
    if ($imports === $this->ssrImports) {
      return;
    }
    $this->ssrImports = $imports;
    $this->moduleSeeder?->setSsrImports($imports);
    if ($this->process !== NULL) {
      $this->exchange(static fn(DenoProcess $process) => $process->setSsrImports($imports));
    }
  }

  /**
   * Returns this OS user's base caches, if a directory for them can be used.
   *
   * When the base caches root can't be used, as when open_basedir leaves it
   * out or another OS user made the directory first, base caches go under
   * the runner caches root instead, beside the runner caches, and fewer are
   * kept. Rendering with them beats every island falling back to CSR, but
   * they may not last as long, so the status report says so.
   */
  private function baseCaches(): ?BaseCaches {
    if ($this->baseCaches === NULL) {
      try {
        $this->baseCaches = new BaseCaches(OsUserDirectory::under($this->baseCachesRoot), $this->denoBinary, $this->timeouts, $this->memoryLimitMib);
      }
      catch (\RuntimeException $e) {
        $runner_caches = $this->runnerCachesDirectory();
        if ($runner_caches === NULL) {
          $this->baseCaches = FALSE;
          $this->directoryUnusable($e);
        }
        else {
          $this->baseCaches = new BaseCaches($runner_caches, $this->denoBinary, $this->timeouts, $this->memoryLimitMib, self::FALLBACK_BASE_CACHES_LIMIT);
          $this->baseCachesFallback = $e->getMessage();
          $this->logger->warning('The Deno runner keeps base caches under its runner caches root, since it can\'t use the base caches root: {message}', ['message' => $e->getMessage()]);
        }
      }
    }

    return $this->baseCaches === FALSE ? NULL : $this->baseCaches;
  }

  /**
   * Returns where this OS user's runner caches are made, making it if needed.
   *
   * It's checked once per runner, so once per request.
   *
   * @return \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectory|null
   *   The directory, or NULL when it can't be used.
   */
  private function runnerCachesDirectory(): ?OsUserDirectory {
    if ($this->runnerCaches === NULL) {
      try {
        $this->runnerCaches = OsUserDirectory::under($this->runnerCachesRoot);
      }
      catch (\RuntimeException $e) {
        $this->runnerCaches = FALSE;
        $this->directoryUnusable($e);
      }
    }

    return $this->runnerCaches === FALSE ? NULL : $this->runnerCaches;
  }

  /**
   * Returns this runner's runner cache, making it if needed.
   *
   * If a base is in use, its base cache is linked in, so the runner cache
   * keeps that base cache until reset().
   *
   * @return \Drupal\canvas_ssr\JsRunner\Deno\RunnerCache
   *   The runner cache.
   *
   * @throws \RuntimeException
   *   When it cannot be created, or the base cache can't be linked.
   */
  private function runnerCache(): RunnerCache {
    if ($this->runnerCache === NULL) {
      $runner_caches = $this->runnerCachesDirectory();
      if ($runner_caches === NULL) {
        throw new \RuntimeException('The Deno runner caches directory can\'t be used.');
      }
      $runner_cache = RunnerCache::make($runner_caches);
      $base_id = $this->baseId;
      $base_caches = $base_id === NULL ? NULL : $this->baseCaches();
      if ($base_id !== NULL && $base_caches !== NULL) {
        $runner_cache->linkBase($base_caches, $base_id);
      }
      $this->runnerCache = $runner_cache;
    }

    return $this->runnerCache;
  }

  /**
   * Deletes this runner's runner cache.
   */
  private function removeRunnerCache(): void {
    // The seeder tracks what's in the runner cache, so it goes too.
    $this->moduleSeeder = NULL;
    $this->runnerCache?->remove();
    $this->runnerCache = NULL;
  }

  /**
   * Returns the module seeder, creating it on first use.
   *
   * @return \Drupal\canvas_ssr\JsRunner\Deno\ModuleSeeder
   *   The seeder holding this runner's registrations.
   */
  private function moduleSeeder(): ModuleSeeder {
    if ($this->moduleSeeder === NULL) {
      // Making the runner cache decides which base cache it links to.
      $runner_cache = $this->runnerCache();
      $this->moduleSeeder = new ModuleSeeder(
        $runner_cache->directory,
        ModuleSeeder::ORIGIN,
        $runner_cache->baseManifest()->urls,
      );
      $this->moduleSeeder->setSsrImports($this->ssrImports);
    }

    return $this->moduleSeeder;
  }

  /**
   * Returns the Deno process, starting one if none is running.
   *
   * A new process gets two import maps. The Deno import map, given at
   * startup, is derived from the SSR import map and locates modules. The
   * SSR import map is what import.meta.resolve() reads. Then it gets every
   * binding.
   *
   * @throws \RuntimeException
   *   When the binary cannot be started.
   */
  private function process(): DenoProcess {
    if ($this->process === NULL) {
      $deno_import_map = $this->moduleSeeder()->denoImportMap();
      $runner_cache = $this->runnerCache();
      $this->process = new DenoProcess($this->denoBinary, $runner_cache->directory, $deno_import_map, $runner_cache->directories(), $this->timeouts, $this->memoryLimitMib);
      $this->exchange(function (DenoProcess $process): void {
        // The base's import map was put in force before this Deno process
        // existed.
        $process->setSsrImports($this->ssrImports);
        foreach ($this->bindings as $name => [$callback, $serialize_js, $deserialize_js]) {
          $process->bind($name, $callback, $serialize_js, $deserialize_js);
        }
      });
    }

    return $this->process;
  }

  /**
   * Talks to the Deno process, resetting the runner if the process fails.
   *
   * A Deno process that failed has been killed, and the runner cache may be
   * the reason, so the runner starts over. A script failure is
   * the process's caller's to handle, and leaves the runner as it is.
   *
   * @param \Closure(\Drupal\canvas_ssr\JsRunner\Deno\DenoProcess): T $exchange
   *   What to do with the process.
   *
   * @return T
   *   What $exchange returned.
   *
   * @template T
   */
  private function exchange(\Closure $exchange): mixed {
    try {
      return $exchange($this->process());
    }
    catch (ScriptException $e) {
      throw $e;
    }
    catch (OsUserDirectoryChangedException $e) {
      $this->directoryChanged($e);
    }
    catch (\RuntimeException $e) {
      $this->reset();
      throw $e;
    }
  }

  /**
   * Records and logs a cache directory that can't be used.
   *
   * A missing binary isn't logged: a site may rely on another runner. But
   * with a binary, a directory that can't be used means the host needs
   * fixing, and the message says how.
   *
   * @param \RuntimeException $e
   *   Why it can't be used.
   */
  private function directoryUnusable(\RuntimeException $e): void {
    $message = 'The Deno runner is unavailable, since it can\'t use its cache directory: {message}';
    $this->unavailableReason = \strtr($message, ['{message}' => $e->getMessage()]);
    $this->logger->warning($message, ['message' => $e->getMessage()]);
  }

  /**
   * Stops using an OS user's directory that changed, and says so once.
   *
   * The runner resets, so nothing more is written there or read from there.
   * The runner cache is forgotten, not deleted: the directory it's in may
   * be another OS user's now. A later call checks the directory again and
   * fails the same way, so the runner keeps failing until the next request
   * checks the whole path.
   *
   * It's logged as an error, unlike a directory that can't be used: it's an
   * event, not a standing condition, so how often it happens matters.
   *
   * @param \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectoryChangedException $e
   *   What was thrown.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectoryChangedException
   *   Always, rethrowing $e.
   */
  private function directoryChanged(OsUserDirectoryChangedException $e): never {
    if (!$this->directoryChangeLogged) {
      $this->directoryChangeLogged = TRUE;
      $this->logger->error('SSR stopped using a Deno directory: {message}', ['message' => $e->getMessage()]);
    }
    $this->moduleSeeder = NULL;
    $this->runnerCache = NULL;
    $this->reset();
    throw $e;
  }

  /**
   * Kills the Deno process, and with it the realm.
   *
   * The runner cache and registrations are kept.
   *
   * The next call that needs a Deno process starts one and gives it the
   * bindings and import map again.
   */
  private function stopProcess(): void {
    $this->process?->stop();
    $this->process = NULL;
  }

}
