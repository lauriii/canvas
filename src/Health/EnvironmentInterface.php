<?php

declare(strict_types=1);

namespace Drupal\canvas\Health;

/**
 * Provides the environment that Canvas validation results depend on.
 *
 * Extracted so tests can substitute a stub: Environment is final and cannot
 * be configured to simulate a production environment in kernel tests.
 *
 * @internal
 * @see \Drupal\canvas\Health\Environment
 */
interface EnvironmentInterface {

  public function getFingerprint(): string;

  public function getCanvasSchemaVersion(): int;

  /**
   * @return string[]
   */
  public function getAppliedCanvasPostUpdates(): array;

  /**
   * @return string[]
   */
  public function getPendingCanvasPostUpdates(): array;

  public function getInstalledExtensionCount(): int;

  /**
   * Installed extensions with no release version (development checkouts).
   *
   * Cached results affected by their constraints may be stale. Edit such an
   * extension's code, then re-run with `--no-cache`.
   *
   * @return string[]
   */
  public function getDevExtensions(): array;

}
