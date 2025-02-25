<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

/**
 * @see \Drupal\experience_builder\AssetManager
 * @internal This interface must be implemented by any Experience Builder config
 *   entity that manages assets in the public file system.
 */
interface XbAssetInterface extends XbHttpApiEligibleConfigEntityInterface {

  public function hasCss(): bool;

  public function hasJs(): bool;

  public function getJs(): string;

  public function getCss(): string;

  public function getJsPath(): string;

  public function getCssPath(): string;

  /**
   * Creates an instance for previewing with auto-save data.
   *
   * Note that no validation is performed and the caller is responsible for
   * handling any side effects of the config entity possibly being in an invalid
   * state.
   *
   * @param array $data
   *   Auto-save data.
   *
   * @return static
   *   New instance with given values.
   */
  public function forAutoSavePreview(array $data): static;

}
