<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner;

/**
 * Stands in for a runner when none is available.
 *
 * RunnerSelector acts as this when no candidate can run. isAvailable()
 * returns FALSE, so Renderer falls back to CSR.
 *
 * @see \Drupal\canvas_ssr\Renderer::render()
 */
final class UnavailableRunner implements RunnerInterface {

  /**
   * Thrown when a caller skips the availability check.
   */
  private const string MESSAGE = 'No runner is available. Install a module that supplies one, or check isAvailable() before using a runner.';

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function addModule(string $url, string $source): never {
    throw new \LogicException(self::MESSAGE);
  }

  /**
   * {@inheritdoc}
   */
  public function bindFunction(
    string $name,
    callable $callback,
    string $serialize_js,
    string $deserialize_js,
  ): never {
    throw new \LogicException(self::MESSAGE);
  }

  /**
   * {@inheritdoc}
   */
  public function invoke(string $main_module, string $payload): never {
    throw new \LogicException(self::MESSAGE);
  }

  /**
   * {@inheritdoc}
   *
   * Does nothing rather than throw. It's called on failure paths, where an
   * exception here would hide the real cause.
   */
  public function reset(): void {}

  /**
   * {@inheritdoc}
   *
   * It never takes a base, so it never calls $getDefinition.
   */
  public function useBase(string $id, \Closure $getDefinition): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasPendingBase(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPendingBase(string $id, \Closure $getDefinition): ?bool {
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * It stands for no runner at all, so it has nothing to say. RunnerSelector
   * gives its candidates' reasons.
   */
  public function unavailableReason(): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * It enforces nothing, but runs nothing either.
   */
  public function limitations(): array {
    return [];
  }

}
