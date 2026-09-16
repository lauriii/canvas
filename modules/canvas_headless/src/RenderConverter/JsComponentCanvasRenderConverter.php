<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\RenderConverter;

use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\custom_elements\CustomElement;
use Drupal\custom_elements\RenderConverter\CanvasRenderConverter;

/**
 * Preserves Canvas JavaScript components in Custom Elements output.
 */
final class JsComponentCanvasRenderConverter extends CanvasRenderConverter {

  // Render-array flag for the page content region inside page chrome.
  public const string PREVIEW_CONTENT_REGION = '#canvas_headless_preview_content_region';

  /**
   * Props used only by Drupal's Astro island renderer.
   *
   * @var string[]
   */
  private const array ASTRO_ISLAND_RUNTIME_PROPS = [
    'canvas_is_preview',
    'canvas_slot_ids',
    'canvas_uuid',
  ];

  /**
   * {@inheritdoc}
   */
  public function convertRenderArray(array $render_array): CustomElement {
    if (($render_array[self::PREVIEW_CONTENT_REGION] ?? FALSE) === TRUE) {
      // Tell the headless SDK where to place editor markers in page chrome.
      $element = CustomElement::create('canvas-preview-content-region');
      $content = $render_array['content'] ?? [];
      if (\is_array($content)) {
        $element->setSlotFromCustomElement('default', $this->convertRenderArray($content));
      }
      $element->addCacheableDependency(BubbleableMetadata::createFromRenderArray($render_array));
      return $element;
    }

    $metadata = self::getComponentMetadata($render_array);
    if ($metadata === NULL) {
      return parent::convertRenderArray($render_array);
    }

    $tag = \strtolower(\str_replace([':', '.', '_'], '-', $metadata['component_id']));
    $element = CustomElement::create($tag);
    foreach ($metadata['props'] as $name => $value) {
      if (\is_string($name)) {
        $element->setAttribute($name, $value);
      }
    }
    $element->setAttribute('canvasUuid', $metadata['component_uuid']);

    foreach ($render_array['#slots'] ?? [] as $slot_name => $slot_content) {
      if (\is_string($slot_name) && \is_array($slot_content)) {
        $element->setSlotFromCustomElement($slot_name, $this->convertRenderArray($slot_content));
      }
    }

    $element->addCacheableDependency(BubbleableMetadata::createFromRenderArray($render_array));
    return $element;
  }

  /**
   * Gets component data from external metadata or a standard Astro island.
   *
   * @return array{component_id: string, component_uuid: string, props: array<string, mixed>}|null
   *   Component data, or NULL when the render array is not a JS component.
   */
  private static function getComponentMetadata(array $render_array): ?array {
    $metadata = $render_array[JsComponent::EXTERNAL_RENDER_METADATA] ?? NULL;
    if (\is_array($metadata)) {
      $component_id = $metadata['component_id'] ?? NULL;
      $component_uuid = $metadata['component_uuid'] ?? NULL;
      $props = $metadata['props'] ?? NULL;
      return \is_string($component_id) && \is_string($component_uuid) && \is_array($props)
        ? [
          'component_id' => $component_id,
          'component_uuid' => $component_uuid,
          'props' => $props,
        ]
        : NULL;
    }

    if (($render_array['#type'] ?? NULL) !== 'astro_island') {
      return NULL;
    }

    $machine_name = $render_array['#machine_name'] ?? NULL;
    $component_uuid = $render_array['#uuid'] ?? NULL;
    $props = $render_array['#props'] ?? NULL;
    if (!\is_string($machine_name) || !\is_string($component_uuid) || !\is_array($props)) {
      return NULL;
    }

    return [
      'component_id' => JsComponent::componentIdFromJavascriptComponentId($machine_name),
      'component_uuid' => $component_uuid,
      'props' => \array_diff_key($props, \array_flip(self::ASTRO_ISLAND_RUNTIME_PROPS)),
    ];
  }

}
