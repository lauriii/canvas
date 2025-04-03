<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Site\Settings;

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
    $hash = Crypt::hmacBase64($this->getJs(), Settings::getHashSalt());
    return self::ASSETS_DIRECTORY . $hash . '.js';
  }

  public function getCssPath(): string {
    $hash = Crypt::hmacBase64($this->getCss(), Settings::getHashSalt());
    return self::ASSETS_DIRECTORY . $hash . '.css';
  }

  public function forAutoSavePreview(array $data): static {
    $draft_entity = clone $this;
    \assert($this instanceof XbHttpApiEligibleConfigEntityInterface);
    // Note we don't perform any validation here. The primary intent of the
    // denormalized entity here is to render a preview. We don't want validation
    // of other aspects of the config entity to prevent the preview from
    // displaying. This makes it important for code that performs rendering
    // during previewing to be very defensive.
    $draft_entity->updateFromClientSide($data);
    return $draft_entity;
  }

}
