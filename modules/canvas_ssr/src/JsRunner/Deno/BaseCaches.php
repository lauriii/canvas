<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

use Drupal\canvas_ssr\Timeouts;

/**
 * This OS user's base caches: one directory per base, for one Deno binary.
 *
 * A base cache holds a base's modules, seeded under their own origin, and
 * Deno's caches after preloading them with the SSR import map of the time.
 * It's built once per base caches directory, under an exclusive lock, in a
 * temporary directory that's moved into place once its manifest is written.
 * It's never written again. A runner cache gets a symlink to the modules
 * and a copy of the caches.
 *
 * Each base cache is named for the base's id, the binary and the runner's
 * JS, since Deno's caches depend on all three. The README covers where base
 * caches are kept, and ADR 10 why a base is built once.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner::useBase()
 *
 * @phpstan-import-type Base from \Drupal\canvas_ssr\JsRunner\RunnerInterface
 */
final class BaseCaches {

  /**
   * How long to sleep between tries for a lock, in microseconds.
   *
   * Another build holds the lock.
   */
  private const LOCK_POLL_US = 20000;

  /**
   * The base cache layout version. Bump it when the layout changes.
   */
  private const FORMAT = '3';

  /**
   * How many base caches pruning keeps by default.
   *
   * The most recently used are kept.
   */
  public const int LIMIT = 100;

  /**
   * How often a base cache's last-used time is refreshed, in seconds.
   *
   * It's refreshed at most this often.
   */
  private const TOUCH_SECONDS = 3600;

  /**
   * The Deno caches each runner cache gets a copy of from the base cache.
   *
   * They're keyed by content or URL, so they're safe to share. Each is an
   * SQLite database, named without its file suffixes.
   *
   * @see self::carryDatabase()
   */
  private const CARRIED_DATABASES = [
    'dep_analysis_cache_v2',
    'v8_code_cache_v2',
    'node_analysis_cache_v2',
  ];

  /**
   * Manifests this PHP process has read, by base cache.
   *
   * A base cache never changes, but it can be deleted, which linkInto()
   * and build() notice.
   *
   * @var array<string, \Drupal\canvas_ssr\JsRunner\Deno\BaseManifest>
   */
  private static array $manifests = [];

  /**
   * Constructs a BaseCaches.
   *
   * @param \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectory $directory
   *   This OS user's base caches directory.
   * @param string $denoBinary
   *   Path to the Deno binary the base caches are for.
   * @param \Drupal\canvas_ssr\Timeouts $timeouts
   *   The runner's time limits.
   * @param int $memoryLimitMib
   *   The most memory a build's Deno process may use, in MiB.
   * @param int $limit
   *   How many base caches pruning keeps.
   */
  public function __construct(
    public readonly OsUserDirectory $directory,
    private readonly string $denoBinary,
    private readonly Timeouts $timeouts,
    private readonly int $memoryLimitMib,
    private readonly int $limit = self::LIMIT,
  ) {}

  /**
   * Returns a base's manifest, if its base cache is complete.
   *
   * @param string $id
   *   The base's id.
   *
   * @return \Drupal\canvas_ssr\JsRunner\Deno\BaseManifest|null
   *   The manifest, or NULL when there's no complete base cache for it.
   */
  public function manifestFor(string $id): ?BaseManifest {
    return self::manifest($this->directoryFor($id));
  }

  /**
   * Builds a base's base cache, unless it's complete.
   *
   * The build runs under an exclusive lock, and is skipped if another
   * process finished it meanwhile. It must finish within a time limit,
   * counted from when it started, or it fails and leaves nothing behind. A
   * process waiting for another's build waits until that build's limit, so
   * no request waits longer than one build may take.
   *
   * @param string $id
   *   The base's id.
   * @param Base $base
   *   The base. Its modules resolve through its import map's entries.
   * @param int $limit_ms
   *   How long the build may take, in wall time.
   * @param bool $wait
   *   Whether to wait for a build another process is running.
   *
   * @return \Drupal\canvas_ssr\JsRunner\Deno\BaseManifest|null
   *   The manifest, or NULL if the base cache still isn't complete.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Deno\BaseCacheBusyException
   *   When another process is building it and $wait is FALSE.
   * @throws \RuntimeException
   *   When the base cache can't be built within the limit, or at all.
   */
  public function ensure(string $id, array $base, int $limit_ms, bool $wait): ?BaseManifest {
    $base_cache = $this->directoryFor($id);
    if (self::manifest($base_cache) === NULL) {
      $this->build($base_cache, $base, self::importsOf($base), $limit_ms, $wait);
    }

    return self::manifest($base_cache);
  }

  /**
   * Returns this OS user's base caches directory.
   *
   * It also holds the record of a build waiting for a retry.
   *
   * @see \Drupal\canvas_ssr\JsRunner\Deno\BaseRetry
   */
  public function path(): string {
    return $this->directory->path;
  }

  /**
   * Returns where the base cache for a base goes.
   *
   * @param string $id
   *   The base's id.
   *
   * @return string
   *   The base cache, which may not exist yet.
   */
  private function directoryFor(string $id): string {
    $binary = $this->denoBinary;
    $identity = [self::FORMAT, $id, $binary, @\filesize($binary), @\filemtime($binary)];
    $files = \glob(__DIR__ . '/js/*.js');
    foreach ($files === FALSE ? [] : $files as $file) {
      $identity[] = $file . ':' . @\filesize($file) . ':' . @\filemtime($file);
    }

    return \sprintf(
      '%s/base-%s',
      $this->directory->path,
      \substr(\hash('sha256', \implode("\0", $identity)), 0, 32),
    );
  }

  /**
   * Returns a base cache's manifest, if the base cache is complete.
   *
   * @param string $base_cache
   *   The base cache.
   *
   * @return \Drupal\canvas_ssr\JsRunner\Deno\BaseManifest|null
   *   The manifest, or NULL when the base cache isn't complete.
   */
  private static function manifest(string $base_cache): ?BaseManifest {
    if (!isset(self::$manifests[$base_cache])) {
      $manifest = BaseManifest::read($base_cache);
      if ($manifest === NULL) {
        return NULL;
      }
      self::$manifests[$base_cache] = $manifest;
    }

    return self::$manifests[$base_cache];
  }

  /**
   * Builds a base cache under a lock.
   *
   * It builds nothing if another process built it meanwhile.
   *
   * @param string $base_cache
   *   Where the base cache goes.
   * @param Base $base
   *   The base.
   * @param array<string, mixed> $imports
   *   The SSR import map's entries.
   * @param int $limit_ms
   *   How long the build may take, in wall time.
   * @param bool $wait
   *   Whether to wait for a build another process is running.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Deno\BaseCacheBusyException
   *   When another process is building it and $wait is FALSE.
   * @throws \RuntimeException
   *   When the lock cannot be taken, the base cache cannot be built in time,
   *   or another process failed to build it while this one waited.
   */
  private function build(string $base_cache, array $base, array $imports, int $limit_ms, bool $wait): void {
    $this->directory->assertIntact();
    $lock = self::lockForBuild($base_cache, $limit_ms, $wait, $waited);
    try {
      // Read again: another process may have built it, or it may have been
      // deleted since this one read it.
      unset(self::$manifests[$base_cache]);
      if (self::manifest($base_cache) === NULL) {
        // The build it waited for failed. Otherwise every process that
        // waited would build it in turn, each for as long as its own limit.
        if ($waited) {
          throw new \RuntimeException(\sprintf(
            'Another process failed to build the Deno base cache "%s".',
            $base_cache,
          ));
        }
        // Recorded in the lock file while it builds, so a process waiting
        // for it knows a build holds the lock, and when its limit runs out.
        $started = \microtime(TRUE);
        \ftruncate($lock, 0);
        \fwrite($lock, \sprintf('%.6F', $started));
        \fflush($lock);
        try {
          $this->buildInPlace($base_cache, $base, $imports, $started + $limit_ms / 1000);
        }
        finally {
          \ftruncate($lock, 0);
        }
      }
    }
    finally {
      self::unlock($lock);
    }
  }

  /**
   * Takes a base cache's lock for building it.
   *
   * @param string $base_cache
   *   The base cache.
   * @param int $limit_ms
   *   How long a build may take.
   * @param bool $wait
   *   Whether to wait for a build another process is running, until that
   *   build's limit runs out.
   * @param bool|null $waited
   *   Set to whether another process's build held it first. Pruning or
   *   linking it holds it too, but only briefly, and records no start.
   *
   * @param-out bool $waited
   *
   * @return resource
   *   The open lock file.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Deno\BaseCacheBusyException
   *   When another process holds it and $wait is FALSE.
   * @throws \RuntimeException
   *   When it can't be taken in time.
   */
  private static function lockForBuild(string $base_cache, int $limit_ms, bool $wait, ?bool &$waited) {
    $waiting_since = \microtime(TRUE);
    $waited = FALSE;
    while (TRUE) {
      $lock = self::lock($base_cache, LOCK_EX | LOCK_NB);
      if ($lock !== NULL) {
        return $lock;
      }
      if (!$wait) {
        throw new BaseCacheBusyException(\sprintf('Another process is building the Deno base cache "%s".', $base_cache));
      }
      // The other build's start, or this wait's if it hasn't recorded one.
      $recorded = (float) @\file_get_contents($base_cache . '.lock');
      if ($recorded > 0) {
        $waited = TRUE;
      }
      $started = $recorded > 0 ? $recorded : $waiting_since;
      if (\microtime(TRUE) >= $started + $limit_ms / 1000) {
        throw new \RuntimeException(\sprintf(
          'Another process didn\'t finish building the Deno base cache "%s" within %d ms.',
          $base_cache,
          $limit_ms,
        ));
      }
      \usleep(self::LOCK_POLL_US);
    }
  }

  /**
   * Links a base's base cache into a runner cache, if it's complete.
   *
   * The runner cache gets a symlink to the base cache's modules and a copy
   * of its Deno databases. Using the base cache marks it used, so pruning
   * keeps it and the OS doesn't age it out.
   *
   * It holds the base cache's lock shared meanwhile, so pruning can't delete
   * it, and doesn't wait for it: only a build that found the base cache
   * incomplete, or pruning, holds it exclusively. A base cache deleted since
   * this process read its manifest isn't linked, and the next request
   * rebuilds it.
   *
   * @param string $id
   *   The base's id.
   * @param string $runner_cache
   *   The runner cache, which has nothing under the base origin yet.
   *
   * @return \Drupal\canvas_ssr\JsRunner\Deno\BaseManifest|null
   *   The base cache's manifest, read along with the link so the two match,
   *   or NULL if there's no complete base cache to link.
   *
   * @throws \RuntimeException
   *   When the base cache can't be linked.
   */
  public function linkInto(string $id, string $runner_cache): ?BaseManifest {
    $base_cache = $this->directoryFor($id);
    $manifest = self::manifest($base_cache);
    if ($manifest === NULL) {
      return NULL;
    }
    // Opening the lock file may create it.
    $this->directory->assertIntact();
    $lock = self::lock($base_cache, LOCK_SH | LOCK_NB);
    if ($lock === NULL) {
      return NULL;
    }
    try {
      $manifest_file = $base_cache . '/' . BaseManifest::FILE;
      \clearstatcache(TRUE, $base_cache);
      \clearstatcache(TRUE, $manifest_file);
      $used = @\filemtime($manifest_file);
      if ($used === FALSE || \is_link($base_cache) || !\is_dir($base_cache)) {
        unset(self::$manifests[$base_cache]);
        return NULL;
      }
      if (\time() - $used > self::TOUCH_SECONDS) {
        self::touch($base_cache);
      }
      ModuleSeeder::linkOrigin($base_cache, $runner_cache, ModuleSeeder::BASE_ORIGIN);
      foreach (self::CARRIED_DATABASES as $name) {
        self::carryDatabase($base_cache . '/' . $name, $runner_cache . '/' . $name);
      }
    }
    finally {
      self::unlock($lock);
    }

    return $manifest;
  }

  /**
   * Returns a base's import map entries.
   *
   * Scopes are left out: SSR finds components through their graph, not
   * through scopes.
   *
   * @param Base $base
   *   The base.
   *
   * @return array<string, mixed>
   *   The entries of its map's "imports".
   */
  private static function importsOf(array $base): array {
    $imports = $base['importMap']['imports'] ?? [];

    return \is_array($imports) ? $imports : [];
  }

  /**
   * Builds a base cache in a temporary directory, then moves it into place.
   *
   * @param string $base_cache
   *   Where the base cache goes.
   * @param Base $base
   *   The base.
   * @param array<string, mixed> $imports
   *   The SSR import map's entries.
   * @param float $deadline
   *   When the build runs out of time, as a microtime.
   *
   * @throws \RuntimeException
   *   When the base cache cannot be written or moved into place, or its
   *   preload modules can't be imported in time.
   */
  private function buildInPlace(string $base_cache, array $base, array $imports, float $deadline): void {
    $temporary = \sprintf('%s.%s.tmp', $base_cache, \uniqid('', TRUE));
    try {
      $seeder = new ModuleSeeder($temporary, ModuleSeeder::BASE_ORIGIN);
      $seeder->setSsrImports($imports);
      foreach ($base['modules'] as $url => $source) {
        $seeder->setSource($url, $source);
      }
      // Prepared even if nothing is seeded, so runner caches can link to it.
      $this->directory->assertIntact();
      $seeder->prepare();
      $seeder->seed();
      $preload = [];
      // The modules to compile are imported too. This process is thrown
      // away, so what they do as they evaluate reaches no render, and their
      // compiled code is in the caches every runner cache copies.
      foreach ([...$base['preload'], ...$base['compile'] ?? []] as $url) {
        try {
          $preload[$seeder->mainModuleUrl($url)] = $url;
        }
        catch (\RuntimeException) {
          // Leave it out. Its renders will fail the same way.
        }
      }
      $unloadable = [];
      foreach ($this->preload($temporary, $seeder->denoImportMap(), $imports, \array_keys($preload), $deadline) as $url) {
        if (isset($preload[$url])) {
          $unloadable[] = $preload[$url];
        }
      }
      foreach (self::CARRIED_DATABASES as $name) {
        self::checkpoint($temporary . '/' . $name);
      }
      // Last, since the manifest marks the base cache complete.
      $this->directory->assertIntact();
      $manifest = new BaseManifest(\array_fill_keys($seeder->seededUrls(), TRUE), $unloadable, $imports);
      if (@\file_put_contents($temporary . '/' . BaseManifest::FILE, $manifest->toJson()) === FALSE) {
        throw new \RuntimeException(\sprintf('Failed to write a Deno base cache\'s manifest in "%s".', $temporary));
      }
      // The caller holds the lock and found no complete base cache, so
      // anything in the way is left over: a stray file, or a base cache
      // deleted part way. Left there, it would fail every build.
      if (@\lstat($base_cache) !== FALSE) {
        OsUserDirectory::removeTree($base_cache);
      }
      if (!@\rename($temporary, $base_cache)) {
        throw new \RuntimeException(\sprintf('Failed to move a Deno base cache into "%s".', $base_cache));
      }
    }
    catch (OsUserDirectoryChangedException $e) {
      // It's not this OS user's directory any more, so nothing in it is
      // deleted.
      $temporary = NULL;
      throw $e;
    }
    finally {
      if ($temporary !== NULL && \is_dir($temporary)) {
        OsUserDirectory::removeTree($temporary);
      }
    }
    $this->prune($base_cache);
  }

  /**
   * Imports a base's preload modules in a Deno process, to fill its caches.
   *
   * A preload module that runs forever, or never settles, as it evaluates
   * would hold a build for as long as it's allowed. So the imports, and the
   * process's exit, must finish by the build's deadline, or the build fails
   * and nothing is kept. Then renders don't wait for it, and it's retried
   * after a backoff.
   *
   * @param string $directory
   *   The base cache being built, as the Deno process's DENO_DIR.
   * @param array<string, string|null> $deno_import_map
   *   The Deno import map the base was seeded for.
   * @param array<string, mixed> $imports
   *   The SSR import map's entries, so modules read the same
   *   import.meta.resolve() as in renders.
   * @param list<string> $urls
   *   The preload modules' cache URLs.
   * @param float $deadline
   *   When the build runs out of time, as a microtime.
   *
   * @return list<string>
   *   The cache URLs of the unloadable ones.
   *
   * @throws \RuntimeException
   *   When the process fails, or doesn't finish in time.
   */
  private function preload(string $directory, array $deno_import_map, array $imports, array $urls, float $deadline): array {
    $process = new DenoProcess($this->denoBinary, $directory, $deno_import_map, [$this->directory], $this->timeouts, $this->memoryLimitMib);
    try {
      $process->setSsrImports($imports, self::remainingMs($deadline, $directory));
      $unloadable = $process->preload($urls, self::remainingMs($deadline, $directory));
      // Deno writes its caches as it exits. One that didn't exit cleanly in
      // time may not have written them all.
      if (!$process->end(self::remainingMs($deadline, $directory))) {
        throw new \RuntimeException(\sprintf('The Deno process building a base cache in "%s" didn\'t exit in time.', $directory));
      }
    }
    finally {
      $process->stop();
    }

    return $unloadable;
  }

  /**
   * Returns how long a build has left.
   *
   * @param float $deadline
   *   When it runs out of time, as a microtime.
   * @param string $directory
   *   What's being built, for the error message.
   *
   * @return int
   *   The time left, in milliseconds.
   *
   * @throws \RuntimeException
   *   When it has run out.
   */
  private static function remainingMs(float $deadline, string $directory): int {
    $remaining = (int) \floor(($deadline - \microtime(TRUE)) * 1000);
    if ($remaining <= 0) {
      throw new \RuntimeException(\sprintf('Building the Deno base cache "%s" ran out of time.', $directory));
    }

    return $remaining;
  }

  /**
   * Keeps the most recently used base caches, and deletes abandoned builds.
   *
   * Runs after a build, which is when an old base cache gets left behind.
   * Age alone deletes nothing, so a base cache in use is never rebuilt for
   * being old. Base caches nobody uses are left to the OS's temp cleanup,
   * until there are more than $limit.
   *
   * @param string $keep
   *   The base cache just built, which the caller holds the lock for.
   */
  private function prune(string $keep): void {
    $used = [];
    $directories = \glob($this->directory->path . '/base-*', GLOB_ONLYDIR);
    foreach ($directories === FALSE ? [] : $directories as $directory) {
      if (!\str_ends_with($directory, '.tmp')) {
        $used[$directory] = $directory === $keep
          ? \PHP_INT_MAX
          : (int) @\filemtime($directory . '/' . BaseManifest::FILE);
      }
      elseif (self::isAbandoned($directory, $keep)) {
        OsUserDirectory::removeTree($directory);
      }
    }
    \arsort($used, SORT_NUMERIC);
    foreach (\array_slice(\array_keys($used), $this->limit) as $directory) {
      // Not while a runner links it in. It's deleted on a later build.
      try {
        $lock = self::lock($directory, LOCK_EX | LOCK_NB);
      }
      catch (\RuntimeException) {
        $lock = NULL;
      }
      if ($lock === NULL) {
        continue;
      }
      OsUserDirectory::removeTree($directory);
      // While still held, so lock() sees it's gone.
      @\unlink($directory . '.lock');
      unset(self::$manifests[$directory]);
      self::unlock($lock);
    }
  }

  /**
   * Locks a base cache, through its lock file.
   *
   * Pruning deletes a lock file while it holds it. A process that opened the
   * file before then would get a lock on a deleted file once pruning lets
   * go, which locks nothing. So the file locked must still be the one at the
   * path.
   *
   * @param string $base_cache
   *   The base cache.
   * @param int<0, 7> $operation
   *   The flock() operation.
   *
   * @return resource|null
   *   The open lock file, or NULL when another process holds it and LOCK_NB
   *   is given.
   *
   * @throws \RuntimeException
   *   When the lock file can't be opened, so nothing could lock it.
   */
  private static function lock(string $base_cache, int $operation) {
    $path = $base_cache . '.lock';
    for ($attempt = 0; $attempt < 3; $attempt++) {
      $lock = @\fopen($path, 'c');
      if ($lock === FALSE) {
        throw new \RuntimeException(\sprintf('Failed to open the Deno base cache lock file "%s".', $path));
      }
      if (!\flock($lock, $operation)) {
        \fclose($lock);
        return NULL;
      }
      $held = \fstat($lock);
      \clearstatcache(TRUE, $path);
      $current = @\stat($path);
      if ($held !== FALSE && $current !== FALSE && $held['dev'] === $current['dev'] && $held['ino'] === $current['ino']) {
        return $lock;
      }
      self::unlock($lock);
    }

    return NULL;
  }

  /**
   * Lets go of a lock lock() took.
   *
   * @param resource $lock
   *   The open lock file.
   */
  private static function unlock($lock): void {
    \flock($lock, LOCK_UN);
    \fclose($lock);
  }

  /**
   * Checks whether a temporary build directory is left from a crash.
   *
   * A build holds its base cache's lock until it has moved or removed its
   * temporary directory. So one whose lock nobody else holds is abandoned.
   *
   * @param string $temporary
   *   The temporary directory, named as buildInPlace() names it.
   * @param string $keep
   *   The base cache whose lock the caller holds.
   *
   * @return bool
   *   TRUE when it may be deleted.
   */
  private static function isAbandoned(string $temporary, string $keep): bool {
    // Base cache names have no dots, and the suffix starts with one.
    $name = \basename($temporary);
    $directory = \dirname($temporary) . '/' . \substr($name, 0, (int) \strpos($name, '.'));
    if ($directory === $keep) {
      // The caller holds this lock, and its own build is in place.
      return TRUE;
    }
    // A build takes the lock before it makes its directory.
    $lock = @\fopen($directory . '.lock', 'r');
    if ($lock === FALSE) {
      return !\is_file($directory . '.lock');
    }
    $free = \flock($lock, LOCK_EX | LOCK_NB);
    if ($free) {
      \flock($lock, LOCK_UN);
    }
    \fclose($lock);

    return $free;
  }

  /**
   * Marks a base cache used.
   *
   * Then pruning keeps it, and the OS doesn't age it out.
   *
   * A base cache's other files are only read, which updates no timestamp on
   * a filesystem mounted with noatime. Cleaners such as systemd-tmpfiles
   * judge each file by its own timestamps, so refreshing only the manifest
   * could leave a base cache that looks complete but is missing modules. So
   * every file and directory is refreshed, and the manifest last.
   *
   * touch() creates what's missing, so this only runs on a base cache that
   * was just found complete, under its lock.
   *
   * @param string $base_cache
   *   The base cache, which the caller holds the lock for.
   */
  private static function touch(string $base_cache): void {
    try {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($base_cache, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($iterator as $path => $_) {
        @\touch((string) $path);
      }
    }
    catch (\UnexpectedValueException) {
      // Unreadable. The manifest still records the use.
    }
    @\touch($base_cache);
    @\touch($base_cache . '.lock');
    @\touch($base_cache . '/' . BaseManifest::FILE);
  }

  /**
   * Merges a database's write-ahead log into it, if pdo_sqlite is available.
   *
   * Deno never closes these databases, even on a clean exit, so all the data
   * stays in the log. Merging it shrinks what each runner cache copies, and
   * removes the risk of copying a log that doesn't match its database.
   *
   * Without pdo_sqlite, the logs are copied as they are.
   *
   * @param string $database
   *   Path to the database. A missing one is not an error.
   */
  private static function checkpoint(string $database): void {
    if (!\is_file($database) || !\class_exists(\PDO::class)
      || !\in_array('sqlite', \PDO::getAvailableDrivers(), TRUE)) {
      return;
    }
    try {
      $handle = new \PDO('sqlite:' . $database);
      $handle->exec('PRAGMA wal_checkpoint(TRUNCATE)');
      // Closing deletes the log and index, so close it now.
      $handle = NULL;
    }
    catch (\PDOException) {
    }
  }

  /**
   * Copies a database along with its write-ahead log, if it has one.
   *
   * A database with a log from another moment looks corrupt to SQLite, so a
   * stale log at the target is removed. The shared-memory index is always
   * removed, since SQLite rebuilds it.
   *
   * @param string $from
   *   Path to the source database. It may not exist.
   * @param string $to
   *   Path to the target database.
   */
  private static function carryDatabase(string $from, string $to): void {
    self::copyFile($from, $to);
    if (\is_file($from . '-wal')) {
      self::copyFile($from . '-wal', $to . '-wal');
    }
    else {
      @\unlink($to . '-wal');
    }
    @\unlink($to . '-shm');
  }

  /**
   * Copies a file over the target, atomically.
   *
   * @param string $from
   *   The source path. It may not exist.
   * @param string $to
   *   The target path.
   */
  private static function copyFile(string $from, string $to): void {
    if (!\is_file($from)) {
      return;
    }
    $temporary = \sprintf('%s.%d.tmp', $to, \getmypid());
    if (@\copy($from, $temporary) && @\rename($temporary, $to)) {
      return;
    }
    @\unlink($temporary);
  }

}
