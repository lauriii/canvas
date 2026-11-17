<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas_ssr\JsRunner\RunnerInterface;

/**
 * A runner that records what it's given, and renders nothing.
 *
 * It's the top candidate, so the guard gives it what a real runner would
 * get: modules under their module URLs, the import map and the base. It
 * records sources without the hooks ModuleEvaluationHooks adds, so a test
 * compares them with what it created.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\JsModules\BaseModulesTest
 *
 * @phpstan-import-type Base from \Drupal\canvas_ssr\JsRunner\RunnerInterface
 */
final class SpyRunner implements RunnerInterface {

  /**
   * Registered modules' sources, keyed by module URL.
   *
   * Static, so a test can reach it without the container.
   *
   * @var array<string, string>
   */
  public static array $modules = [];

  /**
   * Main modules invoked, in order.
   *
   * @var list<string>
   */
  public static array $invoked = [];

  /**
   * Libraries modules invoked, in order.
   *
   * The renderer invokes one before a main module, when the realm may not
   * have evaluated the libraries the island's components import.
   *
   * @var list<string>
   */
  public static array $librariesInvoked = [];

  /**
   * The last import map set, or NULL.
   *
   * @var array<string, mixed>|null
   */
  public static ?array $importMap = NULL;

  /**
   * The definition of the base in use, or NULL.
   *
   * @var Base|null
   */
  public static ?array $base = NULL;

  /**
   * The id of the base in use, or NULL.
   */
  public static ?string $baseId = NULL;

  /**
   * How many times it called useBase()'s $getDefinition.
   */
  public static int $getDefinitionCalls = 0;

  /**
   * How many times it's been reset.
   */
  public static int $resets = 0;

  /**
   * Whether useBase() takes the base.
   *
   * If not, every island falls back to CSR.
   */
  public static bool $takesBase = TRUE;

  /**
   * Whether it says a base it didn't take is being built.
   */
  public static bool $buildsBase = TRUE;

  /**
   * What invoke() returns.
   *
   * By default, a main module's result for a render that threw. Renderer
   * treats that as a failed render, so the island falls back to CSR
   * without the runner being reset.
   */
  public static string $output = self::THREW;

  /**
   * A main module's result for a render that threw.
   */
  private const string THREW = '{"error":"The spy runner renders nothing."}';

  /**
   * The unloadable preload modules useBase() returns.
   *
   * @var list<string>
   */
  public static array $unloadable = [];

  /**
   * Forgets what it saw, and goes back to its defaults.
   */
  public static function clear(): void {
    self::$modules = [];
    self::$invoked = [];
    self::$librariesInvoked = [];
    self::$importMap = NULL;
    self::$base = NULL;
    self::$baseId = NULL;
    self::$getDefinitionCalls = 0;
    self::$resets = 0;
    self::$takesBase = TRUE;
    self::$buildsBase = TRUE;
    self::$output = self::THREW;
    self::$unloadable = [];
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function addModule(string $url, string $source): void {
    self::$modules[$url] = self::withoutHooks($source);
  }

  /**
   * Strips the hooks ModuleEvaluationHooks added to a source.
   *
   * @param string $source
   *   The source.
   *
   * @return string
   *   The source as it was.
   */
  public static function withoutHooks(string $source): string {
    $source = \preg_replace('/^(#![^\n]*\n)?const __canvasSsrEvaluation = __canvasSsrBeginEvaluation\((?:true)?\);/', '$1', $source) ?? $source;

    return \preg_replace('/\nimport \{ beginEvaluation as __canvasSsrBeginEvaluation, .*$/s', '', $source) ?? $source;
  }

  /**
   * {@inheritdoc}
   */
  public function bindFunction(
    string $name,
    callable $callback,
    string $serialize_js,
    string $deserialize_js,
  ): void {}

  /**
   * {@inheritdoc}
   */
  public function invoke(string $main_module, string $payload): string {
    if (\str_contains($main_module, '/libraries/')) {
      self::$librariesInvoked[] = $main_module;
      return '';
    }
    self::$invoked[] = $main_module;

    return self::$output;
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    self::$resets++;
  }

  /**
   * {@inheritdoc}
   */
  public function useBase(string $id, \Closure $getDefinition): bool|array {
    if (!self::$takesBase || $id !== self::$baseId) {
      self::$getDefinitionCalls++;
      [self::$baseId, $base] = $getDefinition();
      $base['modules'] = \array_map(self::withoutHooks(...), $base['modules']);
      self::$base = $base;
      self::$importMap = $base['importMap'];
    }
    if (!self::$takesBase) {
      return FALSE;
    }

    return self::$unloadable === [] ? TRUE : self::$unloadable;
  }

  /**
   * {@inheritdoc}
   */
  public function hasPendingBase(): bool {
    return !self::$takesBase && self::$buildsBase;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPendingBase(string $id, \Closure $getDefinition): ?bool {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function unavailableReason(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function limitations(): array {
    return [];
  }

}
