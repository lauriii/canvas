<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner;

/**
 * Picks the runner for a render, and passes every call to it.
 *
 * Runner modules tag their runners canvas_ssr.runner. Candidates come in
 * priority order, and the first that reports itself available wins. The
 * choice lasts until reset(), so a render never registers modules on one
 * runner and invokes another, and is made again after, so a site isn't kept
 * on a runner that stopped working. With no runner available, this acts as
 * UnavailableRunner.
 *
 * RunnerGuard wraps this. It sees every reset, so what it remembers of the
 * runner never outlives the choice.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerGuard
 */
final class RunnerSelector implements RunnerInterface {

  /**
   * The selected runner, until reset().
   */
  private ?RunnerInterface $selected = NULL;

  /**
   * What this acts as when no candidate can run.
   */
  private readonly UnavailableRunner $unavailable;

  /**
   * Constructs a RunnerSelector.
   *
   * @param iterable<\Drupal\canvas_ssr\JsRunner\RunnerInterface> $candidates
   *   The runners tagged canvas_ssr.runner, highest priority first.
   */
  public function __construct(
    private readonly iterable $candidates,
  ) {
    $this->unavailable = new UnavailableRunner();
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->select() !== $this->unavailable;
  }

  /**
   * {@inheritdoc}
   */
  public function addModule(string $url, string $source): void {
    $this->select()->addModule($url, $source);
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
    $this->select()->bindFunction($name, $callback, $serialize_js, $deserialize_js);
  }

  /**
   * {@inheritdoc}
   */
  public function invoke(string $main_module, string $payload): string {
    return $this->select()->invoke($main_module, $payload);
  }

  /**
   * {@inheritdoc}
   *
   * Also drops the choice, so the next call chooses again.
   */
  public function reset(): void {
    $this->selected?->reset();
    $this->selected = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function useBase(string $id, \Closure $getDefinition): bool|array {
    return $this->select()->useBase($id, $getDefinition);
  }

  /**
   * {@inheritdoc}
   *
   * Asks the selected runner. With none selected, as on a page cache hit, it
   * asks every candidate rather than select one, since checking whether
   * they're available costs ten times as much as this should. Only the
   * runner buildPendingBase() selects builds anything.
   */
  public function hasPendingBase(): bool {
    if ($this->selected !== NULL) {
      return $this->selected->hasPendingBase();
    }
    foreach ($this->candidates as $candidate) {
      if ($candidate->hasPendingBase()) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPendingBase(string $id, \Closure $getDefinition): array|bool|null {
    return $this->select()->buildPendingBase($id, $getDefinition);
  }

  /**
   * {@inheritdoc}
   *
   * With no runner available, it's what each candidate says, highest
   * priority first, so the status report explains every one.
   */
  public function unavailableReason(): ?string {
    if ($this->select() !== $this->unavailable) {
      return NULL;
    }
    $reasons = [];
    foreach ($this->candidates as $candidate) {
      $reason = $candidate->unavailableReason();
      if ($reason !== NULL && $reason !== '') {
        $reasons[] = $reason;
      }
    }

    return $reasons === [] ? NULL : \implode(' ', $reasons);
  }

  /**
   * {@inheritdoc}
   *
   * The selected runner's. With none available, there are none.
   */
  public function limitations(): array {
    return $this->select()->limitations();
  }

  /**
   * Returns the selected runner, selecting one if needed.
   *
   * Public so callers can find out which runner is in use. It's not on the
   * interface.
   *
   * @return \Drupal\canvas_ssr\JsRunner\RunnerInterface
   *   The selected runner, or the unavailable stand-in when none can run.
   */
  public function select(): RunnerInterface {
    if ($this->selected !== NULL) {
      return $this->selected;
    }

    foreach ($this->candidates as $candidate) {
      if (!$candidate->isAvailable()) {
        continue;
      }
      $this->selected = $candidate;

      return $candidate;
    }

    // Not kept, so a later call can still find a runner that becomes
    // available.
    return $this->unavailable;
  }

}
