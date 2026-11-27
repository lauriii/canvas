<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\custom_elements\CustomElement;

/**
 * Keeps Canvas identity and authored prop and slot names in a Custom Element.
 *
 * @internal
 */
final class CanvasCustomElement extends CustomElement {

  /**
   * @var array{componentId: string, componentUuid: string}
   */
  private array $canvasMetadata;

  /**
   * Creates a Custom Element with Canvas identity metadata.
   *
   * Stores the exact Canvas component ID and instance UUID outside application
   * props. Preserves prop and slot names without applying HTML-name
   * normalization.
   *
   * @param array{componentId: string, componentUuid: string} $canvasMetadata
   * @param array<string, mixed> $props
   * @param array<string, \Drupal\custom_elements\CustomElement> $slots
   */
  public static function createWithCanvasMetadata(
    array $canvasMetadata,
    array $props = [],
    array $slots = [],
  ): self {
    $element = parent::create(\strtolower(\str_replace([':', '.', '_'], '-', $canvasMetadata['componentId'])));
    $element->canvasMetadata = $canvasMetadata;
    // Preserve authored prop names without HTML attribute normalization.
    $element->setAttributes($props);
    // Custom Elements' slot setters normalize names for HTML. Canvas's
    // json-render format must keep the names declared by the component.
    $slot_entries = [];
    foreach ($slots as $name => $content) {
      $slot_entries[$name] = [['key' => $name, 'weight' => 0, 'content' => $content]];
    }
    $element->setSlots($slot_entries);
    return $element;
  }

  /**
   * The style selection and its resolved attributes, when requested.
   *
   * @var array{styles: array<string, string>, attributes: array{class?: string, style?: array<string, string>}}|null
   *
   * @see \Drupal\canvas\Style\ComponentStyles
   */
  private ?array $styles = NULL;

  /**
   * Sets the style selection and resolved attributes for json-render output.
   *
   * @param array<string, string> $styles
   * @param array{class?: string, style?: array<string, string>} $attributes
   */
  public function setStyles(array $styles, array $attributes): self {
    $this->styles = $styles === [] ? NULL : ['styles' => $styles, 'attributes' => $attributes];
    return $this;
  }

  /**
   * @return array{styles: array<string, string>, attributes: array{class?: string, style?: array<string, string>}}|null
   */
  public function getStyles(): ?array {
    return $this->styles;
  }

  /**
   * Gets Canvas identity metadata.
   *
   * @return array{componentId: string, componentUuid: string}
   */
  public function getCanvasMetadata(): array {
    return $this->canvasMetadata;
  }

}
