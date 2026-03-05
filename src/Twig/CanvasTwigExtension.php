<?php

declare(strict_types=1);

namespace Drupal\canvas\Twig;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\canvas\Entity\ParametrizedImageStyle;
use Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\canvas\Routing\ParametrizedImageStyleConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Defines a Twig extension to support Drupal Canvas.
 *
 * This:
 * 1. adds metadata to output as HTML comments
 * 2. provides a `toSrcSet` Twig filter
 */
final class CanvasTwigExtension extends AbstractExtension {

  /**
   * Constructs a new CanvasTwigExtension object.
   *
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager service.
   * @param \Drupal\Core\Image\ImageFactory $imageFactory
   *   The image factory service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly ImageFactory $imageFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getNodeVisitors(): array {
    return [
      new CanvasPropVisitor(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters(): array {
    return [
      new TwigFilter(
        'toSrcSet',
        [$this, 'toSrcSet'],
      ),
      new TwigFilter(
        'getWidth',
        [$this, 'getWidth'],
      ),
      new TwigFilter(
        'getHeight',
        [$this, 'getHeight'],
      ),
      new TwigFilter(
        'jsx_attributes',
        [$this, 'jsxAttributes'],
      ),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction(
        'slot',
        [$this, 'slot'],
        ['is_safe' => ['html']],
      ),
    ];
  }

  /**
   * Generates `srcset` from URLs with ?alternateWidths and stream wrapper URIs.
   *
   * @param string $src
   *   An img.src attribute.
   * @param int|null $intrinsicImageWidth
   *   The intrinsic width of the image in $src.
   *
   * @return null|string
   *   A `srcset` string, or NULL if none could be generated.
   */
  public function toSrcSet(string $src, ?int $intrinsicImageWidth = NULL): ?string {
    $template = NULL;

    // URLs with alternateWidths query parameter.
    $query = parse_url($src, PHP_URL_QUERY);
    if ($query) {
      parse_str($query, $params);
      if (!empty($params[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM])) {
        // We only expect 1 `alternateWidths` query parameter.
        \assert(\is_string($params[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM]));
        $template = urldecode($params[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM]);
      }
    }
    // Stream wrappers.
    elseif ($this->streamWrapperManager->isValidUri($src)) {
      $template = ParametrizedImageStyle::load('canvas_parametrized_width')?->buildUrlTemplate($src);
      if (\is_string($template)) {
        $template = $this->fileUrlGenerator->transformRelative($template);
      }
      // Respect the specified width, if any, but ensure that it's never bigger
      // than the actual image width.
      $actual_intrinsic_image_width = $this->getWidth($src);
      $intrinsicImageWidth = $intrinsicImageWidth === NULL
        ? $actual_intrinsic_image_width
        : min($intrinsicImageWidth, $actual_intrinsic_image_width);
      if (\is_null($intrinsicImageWidth)) {
        $intrinsicImageWidth = $this->getWidth($src);
      }
    }

    if (empty($template) || empty($intrinsicImageWidth)) {
      return NULL;
    }

    \assert(str_contains($template, '{width}'), "Expected '{width}' in template not found");

    // Filter widths greater than the intrinsic width to avoid generating
    // upscaled images. We still create a srcset candidate when the width is the
    // same so we can do other things to it like convert it to a more optimized
    // format.
    // @todo Read this from third-party settings: https://drupal.org/i/3533563
    $widths = array_filter(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, static fn($w) => $w <= $intrinsicImageWidth);

    $srcset = \array_map(static fn($w) => str_replace('{width}', (string) $w, $template) . " {$w}w", $widths);
    return implode(', ', $srcset);
  }

  /**
   * Gets the width of an image from a given source path or URL.
   *
   * @param string $src
   *   The image source path, URL, or stream wrapper URI.
   *
   * @return int|null
   *   The width of the image in pixels, or NULL if the source is invalid
   *   or the image cannot be processed.
   */
  public function getWidth(string $src): ?int {
    if (UrlHelper::isValid($src) || $this->streamWrapperManager->isValidUri($src)) {
      $image = $this->imageFactory->get(ltrim($src, "/"));
      if ($image->isValid()) {
        return $image->getWidth();
      }
    }
    return NULL;
  }

  /**
   * Gets the height of an image from a given source path or URL.
   *
   * @param string $src
   *   The image source path, URL, or stream wrapper URI.
   *
   * @return int|null
   *   The height of the image in pixels, or NULL if the source is invalid
   *   or the image cannot be processed.
   */
  public function getHeight(string $src): ?int {
    if (UrlHelper::isValid($src) || $this->streamWrapperManager->isValidUri($src)) {
      $image = $this->imageFactory->get(ltrim($src, "/"));
      if ($image->isValid()) {
        return $image->getHeight();
      }
    }
    return NULL;
  }

  /**
   * Converts an Attribute object to a JSON-encoded array.
   *
   * For Attribute objects and arrays, this filter converts them to JSON.
   * For all other types (strings, numbers, etc.), the value is returned as-is.
   *
   * @param mixed $attribute
   *   The attributes object, array, or other value.
   *
   * @return mixed
   *   JSON-encoded array for compound types, or the original value for other
   *   types.
   */
  public function jsxAttributes(mixed $attribute): mixed {
    if ($attribute instanceof Attribute || \is_object($attribute) && method_exists($attribute, 'toArray')) {
      return Json::encode($attribute->toArray());
    }
    if (\is_array($attribute) || \is_object($attribute)) {
      return Json::encode($attribute);
    }

    // Return the value unchanged for non-compound types.
    return $attribute;
  }

  /**
   * Wraps an element to make it available as an element prop in JSX.
   *
   * This is used for passing HTML elements from Twig templates to its
   * corresponding JSX component. This is necessary as elements can't be
   * passed as attribute values without them being converted to strings.
   *
   * For example, if your twig template has a variable fooElement, which is an
   * HTML element - not a string - you can send
   * it to your JSX component as a slot like this:
   * @code
   * {{ slot('foo', fooElement) }}
   * @endcode
   *
   * The JSX component will receive the element as a `foo` prop.
   *
   * Behind the scenes, this function generates a custom element with a slot
   * attribute, leveraging the Web Components slots API.
   * See https://developer.mozilla.org/en-US/docs/Web/API/Web_components/Using_templates_and_slots
   *
   * @param string $slot_name
   *   The name of the slot.
   * @param mixed $content
   *   The content to render in the slot.
   *
   * @return \Drupal\Component\Render\MarkupInterface|null
   *   The rendered slot markup, or NULL if content is empty.
   */
  public function slot(string $slot_name, mixed $content): ?MarkupInterface {
    // If content is empty (empty string, NULL, empty array), return NULL.
    if ($content === NULL || $content === '' || (\is_array($content) && $content === [])) {
      return NULL;
    }

    // Convert content to string based on its type.
    if ($content instanceof MarkupInterface || \is_scalar($content)) {
      // MarkupInterface objects and scalars can be cast directly.
      $rendered_content = (string) $content;
    }
    elseif (\is_array($content)) {
      // Arrays are treated as render arrays.
      $rendered_content = (string) $this->renderer->render($content);
    }
    else {
      // For other objects, attempt string conversion.
      $rendered_content = (string) $content;
    }

    // If rendered content is still empty, return NULL.
    if ($rendered_content === '') {
      return NULL;
    }

    // Generate the element-prop markup and return MarkupInterface.
    // There is nothing significant about the <element-prop> tag name, any tag
    // not part of the HTML spec would work. The important part is the slot
    // attribute, which allows the contents to be passed as an element to JSX.
    // (as opposed to if they were passed via an attribute, which would cast
    // them to a string).
    $markup = \sprintf(
      '<element-prop slot="%s">%s</element-prop>',
      htmlspecialchars($slot_name, ENT_QUOTES, 'UTF-8'),
      $rendered_content,
    );

    $safe_markup = Markup::create($markup);
    /** @var \Drupal\Component\Render\MarkupInterface $safe_markup */
    return $safe_markup;
  }

}
