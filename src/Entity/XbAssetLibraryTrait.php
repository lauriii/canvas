<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Component\Utility\Crypt;

/**
 * @internal
 */
trait XbAssetLibraryTrait {

  /**
   * The CSS configuration for the XB config entity.
   *
   * @var array{original?: string, compiled?: string}|null
   */
  protected ?array $css;

  /**
   * The JavaScript configuration for the XB config entity.
   *
   * @var array{original?: string, compiled?: string}|null
   */
  protected ?array $js;

  public function hasCss(): bool {
    return trim($this->getCss()) !== '';
  }

  public function hasJs(): bool {
    return trim($this->getJs()) !== '';
  }

  public function getJs(): string {
    return $this->js['compiled'] ?? '';
  }

  public function getCss(): string {
    return $this->css['compiled'] ?? '';
  }

  public function getJsPath(): string {
    $hash = Crypt::hmacBase64($this->getJs(), $this->uuid());
    return self::ASSETS_DIRECTORY . $hash . '.js';
  }

  public function getCssPath(): string {
    $hash = Crypt::hmacBase64($this->getCss(), $this->uuid());
    return self::ASSETS_DIRECTORY . $hash . '.css';
  }

}
