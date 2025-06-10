<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

interface VersionedConfigEntityInterface extends ConfigEntityInterface {

  public const string ACTIVE_VERSION = 'active';

  public function getActiveVersion(): string;

  public function getLoadedVersion(): string;

  public function isLoadedVersionActiveVersion(): bool;

  public function loadVersion(string $version): static;

  public function createVersion(string $version): static;

  public function deleteVersion(string $version): static;

  public function deleteVersionIfExists(string $version): static;

  public function resetToActiveVersion(): static;

  /**
   * @return array<string>
   */
  public function getVersions(): array;

}
