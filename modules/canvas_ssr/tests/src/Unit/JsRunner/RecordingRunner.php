<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner;

use Drupal\canvas_ssr\JsRunner\Exception\ScriptException;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;

/**
 * A runner that records its calls and does nothing else.
 *
 * RunnerSelectorTest and RunnerGuardTest mostly check which of several
 * runners got called, and in what order. A list of strings says that more
 * clearly than mocks.
 *
 * @phpstan-import-type Base from \Drupal\canvas_ssr\JsRunner\RunnerInterface
 */
final class RecordingRunner implements RunnerInterface {

  /**
   * The calls made, in order.
   *
   * @var array<int, string>
   */
  public array $calls = [];

  /**
   * The bases it keeps, by id.
   *
   * @var array<string, Base>
   */
  public array $bases = [];

  /**
   * When set, invoke() throws this, prefixed to the main module's URL.
   */
  public ?string $failWith = NULL;

  /**
   * Whether that failure is a script failure, thrown as a ScriptException.
   */
  public bool $failInScript = FALSE;

  /**
   * When set, invoke() calls this before it returns.
   *
   * A bound callback would be called the same way.
   *
   * @var (\Closure(): void)|null
   */
  public ?\Closure $duringInvoke = NULL;

  /**
   * The unloadable preload modules useBase() returns.
   *
   * @var list<string>
   */
  public array $unloadable = [];

  /**
   * Whether it keeps what it builds from each base.
   */
  public bool $keepsBase = TRUE;

  /**
   * When set, what useBase() returns, whatever it has.
   *
   * It's TRUE, FALSE or a list of module URLs. A test may set an empty
   * list, which the interface doesn't allow, to see what its caller makes
   * of one, so the type isn't narrowed here.
   */
  public mixed $baseResult = NULL;

  /**
   * What buildPendingBase() returns. Unless NULL, hasPendingBase() is TRUE.
   *
   * @var array{since: int, due: int, error: string}|bool|null
   */
  public array|bool|null $pendingBase = NULL;

  /**
   * Why it's unavailable, when it is.
   */
  public ?string $unavailableReason = NULL;

  /**
   * What limitations() returns.
   *
   * @var list<string>
   */
  public array $limitations = [];

  /**
   * Constructs a RecordingRunner.
   *
   * @param bool $available
   *   What isAvailable() returns. Public so a test can change it midway.
   */
  public function __construct(public bool $available = TRUE) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->available;
  }

  /**
   * {@inheritdoc}
   */
  public function addModule(string $url, string $source): void {
    $this->calls[] = 'addModule ' . $url;
  }

  /**
   * {@inheritdoc}
   */
  public function bindFunction(
    string $name,
    callable $callback,
    string $serialize_js,
    string $deserialize_js,
  ): void {
    $this->calls[] = 'bindFunction ' . $name;
  }

  /**
   * {@inheritdoc}
   */
  public function invoke(string $main_module, string $payload): string {
    $this->calls[] = 'invoke ' . $main_module;
    if ($this->duringInvoke !== NULL) {
      ($this->duringInvoke)();
    }
    if ($this->failWith !== NULL) {
      throw $this->failInScript
        ? new ScriptException($this->failWith . $main_module)
        : new \RuntimeException($this->failWith . $main_module);
    }

    return 'invoked ' . $main_module;
  }

  /**
   * {@inheritdoc}
   */
  public function useBase(string $id, \Closure $getDefinition): bool|array {
    $this->calls[] = 'useBase ' . $id;
    if (!isset($this->bases[$id])) {
      [$id, $base] = $getDefinition();
      $this->calls[] = 'getDefinition ' . $id;
      if ($this->keepsBase) {
        $this->bases[$id] = $base;
      }
    }
    if ($this->baseResult !== NULL) {
      return $this->baseResult;
    }
    if (!isset($this->bases[$id])) {
      return FALSE;
    }

    return $this->unloadable === [] ? TRUE : $this->unloadable;
  }

  /**
   * {@inheritdoc}
   */
  public function hasPendingBase(): bool {
    $this->calls[] = 'hasPendingBase';

    return $this->pendingBase !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPendingBase(string $id, \Closure $getDefinition): array|bool|null {
    $this->calls[] = 'buildPendingBase ' . $id;

    return $this->pendingBase;
  }

  /**
   * {@inheritdoc}
   */
  public function unavailableReason(): ?string {
    return $this->available ? NULL : $this->unavailableReason;
  }

  /**
   * {@inheritdoc}
   */
  public function limitations(): array {
    return $this->limitations;
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    $this->calls[] = 'reset';
  }

}
