<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Extension;

use Drupal\experience_builder\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\experience_builder\Routing\ParametrizedImageStyleConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Defines a Twig extension to support Experience Builder.
 *
 * This:
 * 1. adds metadata to output as HTML comments
 * 2. provides a `toSrcSet` Twig filter
 */
final class XbTwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getNodeVisitors(): array {
    return [
      new XbPropVisitor(),
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
    ];
  }

  /**
   * Twig filter to convert `alternateWidths` query parameter to `srcset`.
   *
   * @param string $src
   *   An img.src attribute.
   * @param int $intrinsicImageWidth
   *   The intrinsic width of the image in $src.
   *
   * @return null|string
   *   A `srcset` string, or NULL if none could be generated.
   */
  public static function toSrcSet(string $src, int $intrinsicImageWidth): ?string {
    $parts = parse_url($src);
    if (empty($parts['query'])) {
      return NULL;
    }

    parse_str($parts['query'], $query);
    // If no `alternateWidths`, return empty string.
    if (empty($query[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM])) {
      return NULL;
    }

    // We only expect 1 `alternateWidths` query parameter.
    \assert(is_string($query[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM]));
    $template = urldecode($query[ImageItemOverride::ALT_WIDTHS_QUERY_PARAM]);

    \assert(str_contains($template, '{width}'), "Expected '{width}' in template not found");

    // Filter widths smaller than or equal will be included to avoid generating
    // upscaled images.
    // @todo Read this from third-party settings: https://drupal.org/i/3533563
    $widths = array_filter(ParametrizedImageStyleConverter::ALLOWED_WIDTHS, static fn($w) => $w < $intrinsicImageWidth);

    $srcset = array_map(static fn($w) => str_replace('{width}', (string) $w, $template) . " {$w}w", $widths);
    return implode(', ', $srcset);
  }

}
