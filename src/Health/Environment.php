<?php

declare(strict_types=1);

namespace Drupal\canvas\Health;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Update\UpdateHookRegistry;
use Drupal\Core\Update\UpdateRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fingerprints the environment that Canvas validation results depend on.
 *
 * Tracks every installed extension's version.
 *
 * @internal
 * @see docs/adr/0017-data-health-validation-check-based-coverage-with-environment-fingerprinted-incremental-results.md
 */
final class Environment implements EnvironmentInterface {

  /**
   * Fingerprint value for extensions with no release version.
   *
   * @see ::getDevExtensions()
   */
  public const string DEV_VERSION = 'dev';

  private ?string $fingerprint = NULL;

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly UpdateHookRegistry $updateHookRegistry,
    #[Autowire(service: 'update.post_update_registry')]
    private readonly UpdateRegistry $postUpdateRegistry,
  ) {}

  public function getFingerprint(): string {
    if ($this->fingerprint === NULL) {
      $applied = $this->getAppliedCanvasPostUpdates();
      $pending = $this->getPendingCanvasPostUpdates();
      $versions_hash = \hash('xxh64', \serialize([
        'extensions' => $this->getInstalledExtensionVersions(),
      ]));
      $this->fingerprint = \sprintf('%d:%d:%d:%s',
        $this->getCanvasSchemaVersion(),
        \count($applied),
        \count($pending),
        $versions_hash,
      );
    }
    return $this->fingerprint;
  }

  public function getCanvasSchemaVersion(): int {
    return $this->updateHookRegistry->getInstalledVersion('canvas');
  }

  public function getAppliedCanvasPostUpdates(): array {
    $applied = \array_values(\array_diff($this->getAllCanvasPostUpdates(), $this->getPendingCanvasPostUpdates()));
    \sort($applied);
    return $applied;
  }

  public function getPendingCanvasPostUpdates(): array {
    $functions = \array_filter($this->postUpdateRegistry->getPendingUpdateFunctions(), '\is_string');
    $pending = \array_values(\array_filter($functions, static fn (string $function): bool => \str_starts_with($function, 'canvas_post_update_')));
    \sort($pending);
    return $pending;
  }

  private function getAllCanvasPostUpdates(): array {
    return \array_values(\array_filter($this->postUpdateRegistry->getUpdateFunctions('canvas'), '\is_string'));
  }

  public function getInstalledExtensionCount(): int {
    return \count($this->getInstalledExtensionVersions());
  }

  /**
   * @return array<string, string>
   */
  private function getInstalledExtensionVersions(): array {
    $versions = [];
    foreach ([$this->moduleExtensionList, $this->themeExtensionList] as $extension_list) {
      foreach ($extension_list->getAllInstalledInfo() as $name => $info) {
        $versions[$name] = $info['version'] ?? self::DEV_VERSION;
      }
    }
    \ksort($versions);
    return $versions;
  }

  public function getDevExtensions(): array {
    return \array_keys(\array_filter(
      $this->getInstalledExtensionVersions(),
      static fn (string $version): bool => $version === self::DEV_VERSION,
    ));
  }

}
