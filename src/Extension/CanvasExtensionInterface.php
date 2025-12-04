<?php

declare(strict_types=1);

namespace Drupal\canvas\Extension;

/**
 * @internal
 *
 * Interface for canvas_extension plugins.
 */
interface CanvasExtensionInterface {

  /**
   * Returns the extension id.
   */
  public function id(): string;

  /**
   * Returns the translated extension name.
   */
  public function label(): string;

  /**
   * Returns the translated extension description.
   */
  public function getDescription(): string;

  /**
   * Returns the extension icon URL.
   */
  public function getIcon(): string;

  /**
   * Returns the extension URL.
   */
  public function getUrl(): string;

  /**
   * Returns the extension type.
   */
  public function getType(): CanvasExtensionTypeEnum;

  /**
   * Returns the Canvas Extension API version targeted by this extension.
   */
  public function getApiVersion(): string;

  /**
   * Returns the required permissions for this extension.
   */
  public function getPermissions(): array;

}
