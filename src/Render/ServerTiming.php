<?php

declare(strict_types=1);

namespace Drupal\canvas\Render;

/**
 * Collects per-stage timings for the Canvas layout and render endpoints.
 *
 * Stages are recorded by the controller (and a few render-path collaborators)
 * and emitted as a Server-Timing response header, so that preview latency can
 * be attributed to bootstrap, auto-save work, conversion, rendering, and
 * attachment processing.
 *
 * @see \Drupal\canvas\EventSubscriber\ServerTimingResponseSubscriber
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Server-Timing
 *
 * @internal
 */
final class ServerTiming {

  /**
   * Recorded durations in milliseconds, keyed by metric name.
   *
   * @var array<string, float>
   */
  private array $metrics = [];

  /**
   * Running stopwatch start times, keyed by metric name.
   *
   * @var array<string, float>
   */
  private array $running = [];

  public function start(string $metric): void {
    $this->running[$metric] = \microtime(TRUE);
  }

  public function stop(string $metric): void {
    if (isset($this->running[$metric])) {
      $this->record($metric, (\microtime(TRUE) - $this->running[$metric]) * 1000);
      unset($this->running[$metric]);
    }
  }

  /**
   * Times a callable and returns its result, recording the given metric.
   *
   * @template T
   *
   * @param string $metric
   *   The metric name.
   * @param callable(): T $callable
   *   The work to time.
   *
   * @return T
   *   The callable's return value.
   */
  public function time(string $metric, callable $callable): mixed {
    $this->start($metric);
    try {
      return $callable();
    }
    finally {
      $this->stop($metric);
    }
  }

  public function record(string $metric, float $milliseconds): void {
    $this->metrics[$metric] = ($this->metrics[$metric] ?? 0) + $milliseconds;
  }

  /**
   * Records time elapsed since the start of the request as `bootstrap`.
   */
  public function recordBootstrap(float $requestTimeFloat): void {
    $this->record('bootstrap', (\microtime(TRUE) - $requestTimeFloat) * 1000);
  }

  public function hasMetrics(): bool {
    return $this->metrics !== [];
  }

  /**
   * Builds the Server-Timing header value.
   */
  public function getHeaderValue(): string {
    return \implode(', ', \array_map(
      static fn (string $metric, float $ms): string => \sprintf('%s;dur=%.1f', $metric, $ms),
      \array_keys($this->metrics),
      $this->metrics,
    ));
  }

}
