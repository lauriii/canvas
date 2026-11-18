<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\RenderConverter;

use Drupal\canvas\CanvasClientCapabilities;
use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\canvas_headless\Cache\HeadlessContentFormatCacheContext;
use Drupal\canvas_headless\CanvasContentSerializer;
use Drupal\canvas_headless\CanvasCustomElement;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\custom_elements\CustomElement;
use Drupal\custom_elements\RenderConverter\RenderConverter;
use Drupal\custom_elements\RenderConverter\RenderConverterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Converts Canvas render arrays using separate modern and legacy paths.
 */
final class CanvasRenderConverter implements RenderConverterInterface {

  private const array ASTRO_ISLAND_RUNTIME_PROPS = [
    'canvas_is_preview',
    'canvas_slot_ids',
    'canvas_uuid',
  ];

  /**
   * Interface translations attached by the code components being converted.
   *
   * @var array<string, mixed>
   */
  private array $translations = [];

  public function __construct(
    #[Autowire(service: 'custom_elements.render_converter')]
    private readonly RenderConverter $renderConverter,
    private readonly RendererInterface $renderer,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Converts a tree and collects its code components' interface translations.
   *
   * The translations come from the same attachments as Drupal-rendered pages.
   * Reset them for each conversion so another request cannot inherit them.
   *
   * @return array{\Drupal\custom_elements\CustomElement, array<string, mixed>}
   *   The converted tree and translations for its rendered code components.
   */
  public function convertWithTranslations(array $build): array {
    $this->translations = [];
    try {
      $element = $this->renderConverter->convert($build);
      return [$element, $this->translations];
    }
    finally {
      $this->translations = [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function applies(array $build): bool {
    $request = $this->getContentApiRequest();
    return self::componentMetadata($build) !== NULL
      || isset($build[Marker::RENDER_METADATA])
      || ($build[CanvasContentSerializer::PREVIEW_CONTENT_REGION_RENDER_PROPERTY] ?? FALSE) === TRUE
      || ($build['#type'] ?? NULL) === 'component_container'
      || ($build['#access'] ?? NULL) === FALSE
      // Legacy conversion also owns grouping and opaque markup. Let existing
      // Custom Elements pass through their built-in converter instead.
      || (
        !isset($build['#custom_element'])
        && $request !== NULL
        && !CanvasClientCapabilities::has($request, 'json-render')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function convert(array $build): CustomElement {
    $request = $this->getContentApiRequest();
    $metadata = BubbleableMetadata::createFromRenderArray($build);
    if ($request !== NULL) {
      $metadata->addCacheContexts([HeadlessContentFormatCacheContext::CACHE_CONTEXT_ID]);
    }
    $access = $build['#access'] ?? NULL;
    if ($access instanceof AccessResultInterface) {
      $metadata->addCacheableDependency($access);
    }
    if ($access === FALSE || ($access instanceof AccessResultInterface && !$access->isAllowed())) {
      $element = CustomElement::create('renderless-container');
    }
    elseif (($build[CanvasContentSerializer::PREVIEW_CONTENT_REGION_RENDER_PROPERTY] ?? FALSE) === TRUE) {
      $element = CustomElement::create(CanvasContentSerializer::PREVIEW_CONTENT_REGION_ELEMENT);
      $element->setSlotFromCustomElement('default', $this->renderConverter->convert($build['content'] ?? []));
    }
    else {
      if (self::componentMetadata($build) !== NULL) {
        $translations = $build['#attached']['drupalSettings'][CodeComponentDataProvider::CANVAS_DATA_KEY][CodeComponentDataProvider::V0]['translations'] ?? NULL;
        if (\is_array($translations)) {
          // Preserve plural formula keys, as drupalSettings does.
          $this->translations = NestedArray::mergeDeepArray([$this->translations, $translations], TRUE);
        }
      }
      $element = $request === NULL || CanvasClientCapabilities::has($request, 'json-render')
        ? $this->convertBuild($build)
        : $this->convertLegacyBuild($build);
    }
    $element->addCacheableDependency($metadata);
    return $element;
  }

  /**
   * Builds Canvas identity and structural markers for json-render.
   */
  private function convertBuild(array $build): CustomElement {
    $component = self::componentMetadata($build);
    if ($component !== NULL) {
      $slots = [];
      foreach ($build['#slots'] ?? [] as $name => $slot_build) {
        if (\is_string($name) && \is_array($slot_build)) {
          $slots[$name] = $this->renderConverter->convert($slot_build);
        }
      }
      return CanvasCustomElement::createWithCanvasMetadata([
        'componentId' => $component['component_id'],
        'componentUuid' => $component['component_uuid'],
      ], $component['props'], $slots);
    }

    $marker = $build[Marker::RENDER_METADATA] ?? NULL;
    if (\is_array($marker) && \is_string($marker['marker_id'] ?? NULL) && \is_string($marker['component_uuid'] ?? NULL)) {
      $element = CanvasCustomElement::createWithCanvasMetadata([
        'componentId' => CanvasContentSerializer::MARKER_ELEMENT,
        'componentUuid' => $marker['component_uuid'],
      ], ['markerId' => $marker['marker_id']]);
      unset($build[Marker::RENDER_METADATA], $build['#prefix'], $build['#suffix']);
      return $element->setSlotFromCustomElement('default', $this->renderConverter->convert($build));
    }

    return $this->convertComponentContainer($build);
  }

  /**
   * Builds UUID props and preserves marker comments for the explicit format.
   */
  private function convertLegacyBuild(array $build): CustomElement {
    $component = self::componentMetadata($build);
    if ($component !== NULL) {
      $tag = \strtolower(\str_replace([':', '.', '_'], '-', $component['component_id']));
      $element = CustomElement::create($tag);
      foreach ($component['props'] as $name => $value) {
        $element->setAttribute($name, $value);
      }
      $element->setAttribute('canvasUuid', $component['component_uuid']);
      foreach ($build['#slots'] ?? [] as $name => $slot_build) {
        if (\is_string($name) && \is_array($slot_build)) {
          $element->setSlotFromCustomElement($name, $this->renderConverter->convert($slot_build));
        }
      }
      return $element;
    }

    $marker = $build[Marker::RENDER_METADATA] ?? NULL;
    if (\is_array($marker) && \is_string($marker['marker_id'] ?? NULL) && \is_string($marker['component_uuid'] ?? NULL)) {
      return $this->renderMarkup($build);
    }

    if (($build['#type'] ?? NULL) === 'component_container') {
      return $this->convertComponentContainer($build);
    }
    $child_keys = Element::children($build, TRUE);
    $has_render_properties = isset($build['#markup'])
      || isset($build['#plain_text'])
      || isset($build['#lazy_builder'])
      || isset($build['#pre_render']);
    // Like Custom Elements' fallback, drill through untyped wrappers even
    // when they have pre-render callbacks, so app-owned content stays JSON.
    if (!isset($build['#type']) && !isset($build['#theme']) && ($child_keys !== [] || !$has_render_properties)) {
      $element = CustomElement::create('renderless-container');
      $children = [];
      foreach ($child_keys as $key) {
        $child = $this->renderConverter->convert($build[$key]);
        $element->addCacheableDependency($child);
        if ($child->getTag() !== 'renderless-container' || $child->getSlots() !== []) {
          $children[] = $child;
        }
      }
      if (\count($children) === 1) {
        $children[0]->addCacheableDependency($element);
        return $children[0];
      }
      return $children === [] ? $element : $element->setSlotFromNestedElements('default', $children);
    }
    return $this->renderMarkup($build);
  }

  /**
   * Unwraps known Canvas trees while keeping server safety wrappers intact.
   */
  private function convertComponentContainer(array $build): CustomElement {
    $nested = $build['#component'] ?? NULL;
    if (!\is_array($nested)) {
      return CustomElement::create('renderless-container');
    }
    $content_tree = !isset($nested['#type']) && !isset($nested['#theme']) && Element::children($nested) !== [];
    $content_region = ($nested[CanvasContentSerializer::PREVIEW_CONTENT_REGION_RENDER_PROPERTY] ?? FALSE) === TRUE;
    if (self::componentMetadata($nested) !== NULL || isset($nested[Marker::RENDER_METADATA]) || $content_tree || $content_region) {
      return $this->renderConverter->convert($nested);
    }

    // Render the safety wrapper so Canvas can catch server component failures.
    // Its implementation remains opaque markup rather than frontend components.
    return $this->renderMarkup($build);
  }

  /**
   * Renders server implementations without exposing attached-library nodes.
   */
  private function renderMarkup(array $build): CustomElement {
    $element = CustomElement::create('drupal-markup');
    $markup = (string) $this->renderer->renderInIsolation($build);
    if ($markup !== '') {
      $element->setSlot('default', Markup::create($markup));
    }
    $element->addCacheableDependency(BubbleableMetadata::createFromRenderArray($build));
    return $element;
  }

  /**
   * Gets the active routed content or entity API request.
   */
  private function getContentApiRequest(): ?Request {
    $request = $this->requestStack->getCurrentRequest();
    return $request !== NULL && \is_string($request->attributes->get(CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE))
      ? $request
      : NULL;
  }

  /**
   * Gets component data from external metadata or a standard Astro island.
   *
   * @return array{component_id: string, component_uuid: string, props: array<string, mixed>}|null
   */
  private static function componentMetadata(array $build): ?array {
    $metadata = $build[JsComponent::EXTERNAL_RENDER_METADATA] ?? NULL;
    if (\is_array($metadata)) {
      return \is_string($metadata['component_id'] ?? NULL) && \is_string($metadata['component_uuid'] ?? NULL) && \is_array($metadata['props'] ?? NULL)
        ? $metadata
        : NULL;
    }
    if (($build['#type'] ?? NULL) !== 'astro_island' || !\is_string($build['#machine_name'] ?? NULL) || !\is_string($build['#uuid'] ?? NULL) || !\is_array($build['#props'] ?? NULL)) {
      return NULL;
    }
    return [
      'component_id' => JsComponent::componentIdFromJavascriptComponentId($build['#machine_name']),
      'component_uuid' => $build['#uuid'],
      'props' => \array_diff_key($build['#props'], \array_flip(self::ASTRO_ISLAND_RUNTIME_PROPS)),
    ];
  }

}
