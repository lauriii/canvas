<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner;

use Drupal\canvas_ssr\JsRunner\Exception\RunnerBusyException;
use Drupal\canvas_ssr\JsRunner\Exception\ScriptException;
use Drupal\Core\DestructableInterface;

/**
 * Guards the runner, and spares it calls it doesn't need.
 *
 * It wraps RunnerSelector, and every caller reaches the runner through it.
 *
 * Registering a module under anything but a module URL throws here, so no
 * runner has to handle it.
 *
 * A runner that fails other than with a ScriptException may have dropped
 * what it was given, so this resets too. The next render then gives it
 * everything again, rather than skipping what it no longer has.
 *
 * Nothing reaches the runner while it invokes. A bound callback can render
 * another island, as a same-site fetch whose subrequest builds a page with
 * islands on it can, and that render's calls throw a RunnerBusyException
 * instead, so it falls back to CSR. A reset it asks for waits until the
 * invoke returns. So no runner has to handle a call from inside a callback,
 * and the realm only ever renders one island at a time. Callbacks also run
 * in a Fiber of their own, so none can suspend mid-invoke.
 *
 * It resets at the end of each request, too. Where PHP keeps its services
 * from one request to the next, as in a worker mode, the realm would
 * otherwise carry what one request's islands left in it, maybe another
 * user's data, into the next. Elsewhere, the runner would be destructed
 * then anyway.
 *
 * What it spares the runner is repeats: every island registers its whole
 * graph and asks for the base, so it remembers what the runner has, until
 * a reset. It sees every reset and every failure, which is why that's
 * here: it never remembers what the runner no longer has. A reset also
 * makes RunnerSelector choose again, so what it remembers is always of the
 * runner in use.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface
 * @see \Drupal\canvas_ssr\JsRunner\RunnerSelector
 * @see \Drupal\canvas_ssr\Renderer
 */
final class RunnerGuard implements RunnerInterface, DestructableInterface {

  /**
   * Sources already registered on the runner, keyed by URL.
   *
   * Every island registers its whole graph, so most registrations on a page
   * are repeats. Skipping them here saves handing them to the runner again.
   *
   * Comparing whole sources is cheaper than hashing them: PHP compares the
   * same string by pointer, and others with memcmp.
   *
   * @var array<string, string>
   *
   * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::addModule()
   */
  private array $registered = [];

  /**
   * The id of the base in use on the runner, or NULL.
   *
   * @see self::useBase()
   */
  private ?string $baseId = NULL;

  /**
   * What the runner said for the base in use.
   *
   * TRUE, or its unloadable preload modules.
   *
   * @var true|non-empty-list<string>
   */
  private bool|array $baseResult = TRUE;

  /**
   * What the last $getDefinition threw, or NULL.
   *
   * @see self::useBase()
   */
  private ?\Throwable $getDefinitionFailure = NULL;

  /**
   * Whether the runner is invoking.
   */
  private bool $invoking = FALSE;

  /**
   * Whether a reset was asked for while invoking, to do once it returns.
   */
  private bool $resetDeferred = FALSE;

  /**
   * Constructs a RunnerGuard.
   *
   * @param \Drupal\canvas_ssr\JsRunner\RunnerInterface $runner
   *   The runner it guards: RunnerSelector, outside tests.
   */
  public function __construct(
    private readonly RunnerInterface $runner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->runner->isAvailable();
  }

  /**
   * {@inheritdoc}
   *
   * Forwards each source once, until reset().
   *
   * @throws \InvalidArgumentException
   *   If the URL isn't a module URL.
   */
  public function addModule(string $url, string $source): void {
    ModuleUrl::assert($url);
    $this->refuseWhileInvoking();
    if (($this->registered[$url] ?? NULL) !== $source) {
      $this->forward(fn() => $this->runner->addModule($url, $source));
      $this->registered[$url] = $source;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Callbacks run in a Fiber of their own when called inside a Fiber.
   *
   * @see self::runInOwnFiber()
   */
  public function bindFunction(
    string $name,
    callable $callback,
    string $serialize_js,
    string $deserialize_js,
  ): void {
    $this->refuseWhileInvoking();
    $this->forward(fn() => $this->runner->bindFunction(
      $name,
      static fn(string $argument): string => self::runInOwnFiber($callback, $argument),
      $serialize_js,
      $deserialize_js,
    ));
  }

  /**
   * Runs a callback to completion, so it can't suspend the caller's Fiber.
   *
   * Drupal renders placeholders in Fibers, and loading an entity or alias
   * suspends one so its loads batch with the others'. A callback runs in
   * the middle of invoke(), so suspending there would let another Fiber
   * invoke the runner while this call was still inside it. Resuming right
   * away keeps the call whole, at the cost of that batching.
   *
   * Outside a Fiber, nothing suspends, so the callback runs directly.
   *
   * @param callable(string): string $callback
   *   The bound callback.
   * @param string $argument
   *   The serialized call.
   *
   * @return string
   *   What the callback returned.
   */
  private static function runInOwnFiber(callable $callback, string $argument): string {
    if (\Fiber::getCurrent() === NULL) {
      return $callback($argument);
    }
    $fiber = new \Fiber($callback);
    $fiber->start($argument);
    while (!$fiber->isTerminated()) {
      $fiber->resume();
    }

    return $fiber->getReturn();
  }

  /**
   * {@inheritdoc}
   */
  public function invoke(string $main_module, string $payload): string {
    $this->refuseWhileInvoking();
    $this->invoking = TRUE;
    try {
      return $this->forward(fn() => $this->runner->invoke($main_module, $payload));
    }
    finally {
      $this->invoking = FALSE;
      if ($this->resetDeferred) {
        $this->resetDeferred = FALSE;
        $this->reset();
      }
    }
  }

  /**
   * Whether the runner is invoking, so anything rendering now is inside it.
   */
  public function isInvoking(): bool {
    return $this->invoking;
  }

  /**
   * Throws if the runner is invoking.
   *
   * @throws \Drupal\canvas_ssr\JsRunner\Exception\RunnerBusyException
   *   While it is.
   */
  private function refuseWhileInvoking(): void {
    if ($this->invoking) {
      throw new RunnerBusyException('The runner is busy with an invoke, so an island rendered from inside it falls back to CSR.');
    }
  }

  /**
   * Forwards a call to the runner, resetting if the runner failed.
   *
   * @param callable(): T $call
   *   The call.
   *
   * @return T
   *   What it returned.
   *
   * @template T
   */
  private function forward(callable $call): mixed {
    try {
      return $call();
    }
    catch (ScriptException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      $this->reset();
      throw $e;
    }
  }

  /**
   * Resets the runner at the end of a request.
   *
   * Run after kernel.terminate's subscribers, so a base left to build after
   * the response is built first.
   */
  public function destruct(): void {
    $this->reset();
  }

  /**
   * {@inheritdoc}
   *
   * Forgets what the runner had. While invoking, it waits until the invoke
   * returns, so the outer render keeps its realm.
   */
  public function reset(): void {
    if ($this->invoking) {
      $this->resetDeferred = TRUE;
      return;
    }
    $this->runner->reset();
    $this->baseId = NULL;
    $this->baseResult = TRUE;
    $this->registered = [];
  }

  /**
   * {@inheritdoc}
   *
   * Asks the runner once per id in use, until reset(). If
   * $getDefinition was called, it remembers the id it returned, since that's
   * the one the caller reads next. A throw from $getDefinition is the
   * caller's problem, so it doesn't reset the runner; nor does a
   * ScriptException. While invoking, only the base in use is answered.
   */
  public function useBase(string $id, \Closure $getDefinition): bool|array {
    if ($id === $this->baseId) {
      return $this->baseResult;
    }
    $this->refuseWhileInvoking();
    $used = $id;
    $this->getDefinitionFailure = NULL;
    $get_and_remember = function () use ($getDefinition, &$used): array {
      try {
        $got = $getDefinition();
      }
      catch (\Throwable $e) {
        $this->getDefinitionFailure = $e;
        throw $e;
      }
      $used = $got[0];

      return $got;
    };
    try {
      $result = $this->runner->useBase($id, $get_and_remember);
    }
    catch (\Throwable $e) {
      if ($e !== $this->getDefinitionFailure && !$e instanceof ScriptException) {
        $this->reset();
      }
      throw $e;
    }
    if ($result === FALSE) {
      $this->baseId = NULL;

      return FALSE;
    }
    $this->baseId = $used;
    // An empty list would read as FALSE.
    $this->baseResult = $result === [] ? TRUE : $result;

    return $this->baseResult;
  }

  /**
   * {@inheritdoc}
   */
  public function hasPendingBase(): bool {
    return $this->runner->hasPendingBase();
  }

  /**
   * {@inheritdoc}
   *
   * The base in use stays as it was, so this changes nothing remembered.
   */
  public function buildPendingBase(string $id, \Closure $getDefinition): array|bool|null {
    $this->refuseWhileInvoking();

    return $this->forward(fn() => $this->runner->buildPendingBase($id, $getDefinition));
  }

  /**
   * {@inheritdoc}
   */
  public function unavailableReason(): ?string {
    return $this->runner->unavailableReason();
  }

  /**
   * {@inheritdoc}
   */
  public function limitations(): array {
    return $this->runner->limitations();
  }

}
