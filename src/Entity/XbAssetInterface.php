<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * @see \Drupal\experience_builder\AssetManager
 * @internal This interface must be implemented by any Experience Builder config
 *   entity that manages assets in the public file system.
 */
interface XbAssetInterface extends ConfigEntityInterface {

  public function hasCss(): bool;

  public function hasJs(): bool;

  public function getJs(): string;

  public function getCss(): string;

  public function getJsPath(): string;

  public function getCssPath(): string;

}
