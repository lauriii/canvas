<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\canvas_headless\JsonRender\CanvasJsonRenderNormalizer;
use Drupal\canvas_headless\RenderConverter\CanvasRenderConverter;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\custom_elements\CustomElementNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Serializes Canvas render arrays into negotiated content output.
 *
 * Custom Elements serializes legacy-compatible trees directly. Canvas's format
 * normalizer owns json-render identity and preview semantics.
 */
final class CanvasContentSerializer {

  public const string FORMAT_JSON_RENDER = 'json-render';

  public const string FORMAT_LEGACY = 'legacy';

  public const string MARKER_ELEMENT = 'canvas-marker';

  public const string PREVIEW_CONTENT_REGION_ELEMENT = 'canvas-preview-content-region';

  public const string SLOT_PLACEHOLDER_ELEMENT = 'canvas-slot-placeholder';

  /**
   * Render-array flag for the page content region inside page chrome.
   */
  public const string PREVIEW_CONTENT_REGION_RENDER_PROPERTY = '#canvas_headless_preview_content_region';

  public function __construct(
    private readonly CanvasRenderConverter $canvasRenderConverter,
    #[Autowire(service: 'custom_elements.normalizer')]
    private readonly CustomElementNormalizer $normalizer,
  ) {}

  /**
   * Builds the requested wire-format content array without JSON encoding.
   *
   * @return array{?array, array<string, mixed>}
   *   The content document and its code components' interface translations.
   */
  public function serialize(
    array $build,
    string $format,
    bool $preview,
    CacheableMetadata $cacheability,
  ): array {
    if (!\in_array($format, [self::FORMAT_JSON_RENDER, self::FORMAT_LEGACY], TRUE)) {
      throw new \InvalidArgumentException("Unsupported Canvas content document format: $format");
    }
    $metadata = BubbleableMetadata::createFromRenderArray($build);
    [$element, $translations] = $this->canvasRenderConverter->convertWithTranslations($build);
    $content = $this->normalizer->normalize($element, context: [
      'json_format' => $format === self::FORMAT_JSON_RENDER ? CanvasJsonRenderNormalizer::FORMAT : 'explicit',
      'preview' => $preview,
      'cache_metadata' => $metadata,
    ]);
    \assert(\is_array($content));
    if ($format === self::FORMAT_LEGACY) {
      // Preserve the endpoint's legacy empty and multiple-root response shapes.
      if ($content === [] || $content === ['element' => 'drupal-markup']) {
        $content = NULL;
      }
      elseif (\array_is_list($content)) {
        $content = \count($content) === 1
          ? $content[0]
          : ['element' => 'renderless-container', 'slots' => ['default' => $content]];
      }
    }
    $cacheability->addCacheableDependency($metadata);
    \assert(\is_array($content) || $content === NULL);
    return [$content, $translations];
  }

}
