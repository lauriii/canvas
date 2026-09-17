<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

/**
 * Determines the intrinsic dimensions of an SVG image.
 *
 * Drupal's image toolkits only support raster images, so neither the image
 * factory nor ImageItem::preSave() can determine the dimensions of an SVG
 * image. Without dimensions, a browser
 * sizes an SVG image to the width of its containing block, which is rarely what
 * the author intended.
 *
 * @see https://www.w3.org/TR/SVG2/coords.html#ViewBoxAttribute
 * @see https://www.w3.org/TR/css-sizing-3/#intrinsic-sizes
 * @internal
 */
final class SvgHelper {

  /**
   * Extracts the intrinsic dimensions from the root `<svg>` element.
   *
   * Two sources are used, in order of precedence:
   * 1. the `width` and `height` attributes, but only if both are expressed in
   *    pixels — a percentage (`width="100%"`) or a font-relative unit
   *    (`width="10em"`) conveys no intrinsic size
   * 2. the third and fourth value of the `viewBox` attribute, which are its
   *    width and height (the first two are its origin: `min-x` and `min-y`)
   *
   * @param string $svg
   *   The contents of an SVG file.
   *
   * @return array{width: int, height: int}|null
   *   The intrinsic dimensions in pixels, or NULL if the SVG image does not
   *   convey them (or is not parseable).
   */
  public static function getIntrinsicDimensions(string $svg): ?array {
    $use_internal_errors = \libxml_use_internal_errors(TRUE);
    try {
      $element = \simplexml_load_string($svg, options: \LIBXML_NONET);
    }
    finally {
      \libxml_clear_errors();
      \libxml_use_internal_errors($use_internal_errors);
    }
    if ($element === FALSE) {
      return NULL;
    }

    // Attribute names are case-sensitive per the SVG specification, but not all
    // SVG images in the wild respect that, in particular for `viewBox`.
    $attributes = [];
    foreach ($element->attributes() ?? [] as $name => $value) {
      $attributes[\mb_strtolower($name)] = (string) $value;
    }

    // 1. The `width` and `height` attributes, if both are expressed in pixels.
    $width = self::parsePixels($attributes['width'] ?? NULL);
    $height = self::parsePixels($attributes['height'] ?? NULL);
    if ($width !== NULL && $height !== NULL) {
      return ['width' => $width, 'height' => $height];
    }

    // 2. The width and height in the `viewBox` attribute: `min-x min-y width
    // height`, separated by whitespace and/or commas.
    // @see https://www.w3.org/TR/SVG2/coords.html#ViewBoxAttribute
    $view_box = \preg_split('/[\s,]+/', \trim($attributes['viewbox'] ?? ''), flags: \PREG_SPLIT_NO_EMPTY);
    if (!\is_array($view_box) || \count($view_box) !== 4) {
      return NULL;
    }
    $width = self::parsePixels($view_box[2]);
    $height = self::parsePixels($view_box[3]);
    return $width !== NULL && $height !== NULL
      ? ['width' => $width, 'height' => $height]
      : NULL;
  }

  /**
   * Parses a positive length in pixels: a number, optionally suffixed `px`.
   *
   * Any other unit (`%`, `em`, `pt`, …) depends on the rendering context and
   * hence conveys no intrinsic size.
   *
   * @return int|null
   *   The number of pixels, rounded, or NULL.
   */
  private static function parsePixels(?string $value): ?int {
    if ($value === NULL || !\preg_match('/^\s*(\d*\.?\d+)(?:px)?\s*$/', $value, $matches)) {
      return NULL;
    }
    $pixels = (int) \round((float) $matches[1]);
    return $pixels > 0 ? $pixels : NULL;
  }

}
