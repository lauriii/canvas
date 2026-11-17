<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas_ssr\JsRunner\RunnerInterface;

/**
 * Passes every call on to the runner it wraps, and counts resets.
 *
 * Renderer gets it in place of the guard, so a test can see a render
 * reset the runner without knowing which runner was selected. It can also
 * fail an invoke() with a runner failure.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\RendererTest
 */
final class ResetCountingRunner implements RunnerInterface {

  /**
   * How many times it's been reset.
   *
   * Static, so a test can reach it without the container.
   */
  public static int $resets = 0;

  /**
   * What the next invoke() throws instead of invoking, if anything.
   */
  public static ?\Throwable $invokeFailure = NULL;

  /**
   * How many invokes throw it. It's 1 again once they have.
   */
  public static int $invokeFailureTimes = 1;

  public function __construct(
    private readonly RunnerInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->inner->isAvailable();
  }

  /**
   * {@inheritdoc}
   */
  public function addModule(string $url, string $source): void {
    $this->inner->addModule($url, $source);
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
    $this->inner->bindFunction($name, $callback, $serialize_js, $deserialize_js);
  }

  /**
   * {@inheritdoc}
   */
  public function invoke(string $main_module, string $payload): string {
    if (self::$invokeFailure !== NULL) {
      $failure = self::$invokeFailure;
      if (--self::$invokeFailureTimes < 1) {
        self::$invokeFailure = NULL;
        self::$invokeFailureTimes = 1;
      }
      throw $failure;
    }

    return $this->inner->invoke($main_module, $payload);
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    self::$resets++;
    $this->inner->reset();
  }

  /**
   * {@inheritdoc}
   */
  public function useBase(string $id, \Closure $getDefinition): bool|array {
    return $this->inner->useBase($id, $getDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public function hasPendingBase(): bool {
    return $this->inner->hasPendingBase();
  }

  /**
   * {@inheritdoc}
   */
  public function buildPendingBase(string $id, \Closure $getDefinition): array|bool|null {
    return $this->inner->buildPendingBase($id, $getDefinition);
  }

  /**
   * {@inheritdoc}
   */
  public function unavailableReason(): ?string {
    return $this->inner->unavailableReason();
  }

  /**
   * {@inheritdoc}
   */
  public function limitations(): array {
    return $this->inner->limitations();
  }

}
