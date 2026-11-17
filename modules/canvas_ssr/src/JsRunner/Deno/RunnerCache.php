<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

/**
 * One runner's runner cache: the DENO_DIR its Deno processes share.
 *
 * It holds the modules the runner seeded and Deno's caches for them. If a
 * base is in use when it's made, it starts linked to the base's base cache,
 * and keeps that link and that base cache's manifest until it's removed.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner
 */
final class RunnerCache {

  /**
   * The manifest of the base cache linked in, or an empty one.
   */
  private BaseManifest $baseManifest;

  /**
   * The base caches directory of the base cache linked in, if any.
   */
  private ?OsUserDirectory $baseCaches = NULL;

  /**
   * The runner caches that exist, as keys, for removeAfterFatalError().
   *
   * @var array<string, true>
   */
  private static array $made = [];

  /**
   * Whether removeAfterFatalError() is registered as a shutdown function.
   */
  private static bool $removesAtShutdown = FALSE;

  /**
   * Constructs a RunnerCache.
   *
   * @param \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectory $runnerCaches
   *   This OS user's runner caches directory, which holds it.
   * @param string $directory
   *   The runner cache, which exists.
   */
  private function __construct(
    private readonly OsUserDirectory $runnerCaches,
    public readonly string $directory,
  ) {
    $this->baseManifest = BaseManifest::none();
  }

  /**
   * Makes a new runner cache.
   *
   * @param \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectory $runner_caches
   *   This OS user's runner caches directory.
   *
   * @return self
   *   The runner cache, empty.
   *
   * @throws \RuntimeException
   *   When it cannot be created, or the runner caches directory changed.
   */
  public static function make(OsUserDirectory $runner_caches): self {
    $runner_caches->assertIntact();
    $directory = \sprintf('%s/%s', $runner_caches->path, \uniqid('', TRUE));
    if (!@\mkdir($directory, 0700)) {
      throw new \RuntimeException(\sprintf(
        'Failed to create the Deno runner cache "%s". Set the runner\'s runner caches root to a directory the web server may write.',
        $directory,
      ));
    }

    if (!self::$removesAtShutdown) {
      \register_shutdown_function([self::class, 'removeAfterFatalError']);
      self::$removesAtShutdown = TRUE;
    }
    self::$made[$directory] = TRUE;

    return new self($runner_caches, $directory);
  }

  /**
   * Deletes every runner cache left, as PHP shuts down after a fatal error.
   *
   * A runner removes its runner cache when it's destructed, but destructors
   * don't run after a fatal error, and shutdown functions do. After
   * anything else, the runners' destructors are still to come, and a later
   * shutdown function may yet render.
   *
   * @internal
   *   Registered as a shutdown function.
   */
  public static function removeAfterFatalError(): void {
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
    if (((\error_get_last()['type'] ?? 0) & $fatal) === 0) {
      return;
    }
    // First, so no Deno process reads a runner cache as it goes.
    DenoProcess::stopRunning();
    foreach (\array_keys(self::$made) as $directory) {
      OsUserDirectory::removeTree($directory);
    }
    self::$made = [];
  }

  /**
   * Links a base's base cache in, if it's complete.
   *
   * Call it once, on a new runner cache. If linking fails, the runner cache
   * is removed, since the caller was told the base is in use and will
   * invoke its preload modules.
   *
   * @param \Drupal\canvas_ssr\JsRunner\Deno\BaseCaches $base_caches
   *   This OS user's base caches.
   * @param string $base_id
   *   The id of the base in use.
   *
   * @throws \RuntimeException
   *   When the base cache can't be linked.
   */
  public function linkBase(BaseCaches $base_caches, string $base_id): void {
    try {
      $this->assertIntact();
      $manifest = $base_caches->linkInto($base_id, $this->directory);
      if ($manifest !== NULL) {
        $this->baseManifest = $manifest;
        $this->baseCaches = $base_caches->directory;
      }
    }
    catch (OsUserDirectoryChangedException $e) {
      // It's not this OS user's directory any more, so it isn't deleted.
      throw $e;
    }
    catch (\RuntimeException $e) {
      $this->remove();
      throw $e;
    }
  }

  /**
   * Returns the manifest of the base cache linked in.
   *
   * @return \Drupal\canvas_ssr\JsRunner\Deno\BaseManifest
   *   The manifest, or an empty one if no base cache is linked.
   */
  public function baseManifest(): BaseManifest {
    return $this->baseManifest;
  }

  /**
   * Checks the OS user's directories this runner cache uses are unchanged.
   *
   * Those are the runner caches directory, and the base caches directory if
   * a base cache is linked in. Call it before each batch of writes to the
   * runner cache.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectoryChangedException
   *   When one changed.
   */
  public function assertIntact(): void {
    foreach ($this->directories() as $directory) {
      $directory->assertIntact();
    }
  }

  /**
   * Returns the OS user's directories this runner cache uses.
   *
   * @return list<\Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectory>
   *   The runner caches directory, then the base caches directory if a base
   *   cache is linked in.
   */
  public function directories(): array {
    return $this->baseCaches === NULL ? [$this->runnerCaches] : [$this->runnerCaches, $this->baseCaches];
  }

  /**
   * Checks the runner cache, and the base cache linked into it, still exist.
   *
   * @return bool
   *   FALSE if either was deleted.
   */
  public function exists(): bool {
    \clearstatcache();

    return \is_dir($this->directory) && ModuleSeeder::originLinkIntact($this->directory, ModuleSeeder::BASE_ORIGIN);
  }

  /**
   * Deletes the runner cache.
   *
   * Nothing is copied back into the base cache. The link to it is removed,
   * not followed.
   */
  public function remove(): void {
    OsUserDirectory::removeTree($this->directory);
    unset(self::$made[$this->directory]);
  }

}
