<?php

declare(strict_types=1);

namespace Drupal\canvas\Icon;

// cspell:ignore NOENT mport

/**
 * Validates uploaded SVG files before they are stored as icon assets.
 *
 * This is a trust boundary: icon SVGs are later inlined into pages, so
 * anything that could execute script, reference external resources, or abuse
 * the XML parser must be rejected. Rejection (rather than stripping) keeps the
 * stored file byte-identical to what was reviewed.
 *
 * @internal
 */
final class SvgSanitizer {

  /**
   * Element names that are never allowed in an icon SVG.
   */
  private const array FORBIDDEN_ELEMENTS = [
    'script',
    'foreignobject',
    'iframe',
    'embed',
    'object',
  ];

  /**
   * Animation elements that may retarget attributes such as `href`.
   */
  private const array ANIMATION_ELEMENTS = [
    'animate',
    'set',
  ];

  /**
   * Attribute names whose values must be local fragment references.
   */
  private const array REFERENCE_ATTRIBUTES = [
    'href',
    'src',
  ];

  /**
   * Validates SVG file contents.
   *
   * @param string $svg_content
   *   The raw SVG file contents.
   *
   * @return list<string>
   *   Human-readable reasons why the SVG was rejected; safe when empty.
   */
  public static function validate(string $svg_content): array {
    $reasons = [];

    $document = new \DOMDocument();
    $previous_use_errors = \libxml_use_internal_errors(TRUE);
    try {
      // LIBXML_NONET forbids network access during parsing. Entities are not
      // substituted (LIBXML_NOENT is deliberately not passed), and external
      // entity loading is disabled by default since PHP 8.
      $loaded = $document->loadXML($svg_content, LIBXML_NONET);
    }
    finally {
      \libxml_clear_errors();
      \libxml_use_internal_errors($previous_use_errors);
    }
    if ($loaded === FALSE || $document->documentElement === NULL) {
      return ['The file is not well-formed XML.'];
    }

    if (\strtolower($document->documentElement->localName ?? '') !== 'svg') {
      $reasons[] = 'The root element must be <svg>.';
    }

    // Any DOCTYPE (including an internal DTD subset) enables entity tricks
    // such as XXE and billion laughs, and is never needed for icons.
    if ($document->doctype !== NULL) {
      $reasons[] = 'The file must not contain a DOCTYPE or internal DTD subset.';
    }

    $xpath = new \DOMXPath($document);

    // Processing instructions (e.g. `<?php`, `<?xml-stylesheet`) can execute
    // code or pull in external resources.
    $processing_instructions = $xpath->query('//processing-instruction()');
    if ($processing_instructions !== FALSE && $processing_instructions->length > 0) {
      $reasons[] = 'The file must not contain processing instructions.';
    }

    $elements = $xpath->query('//*');
    foreach ($elements === FALSE ? [] : $elements as $element) {
      \assert($element instanceof \DOMElement);
      $tag = \strtolower($element->localName ?? '');

      if (\in_array($tag, self::FORBIDDEN_ELEMENTS, TRUE)) {
        $reasons[] = \sprintf('The file must not contain <%s> elements.', $tag);
      }

      // Animation elements are allowed, unless they retarget a reference
      // attribute, which would bypass the static `href` checks below.
      if (\in_array($tag, self::ANIMATION_ELEMENTS, TRUE)) {
        $attribute_name = $element->getAttribute('attributeName');
        if (\preg_match('/href/i', $attribute_name)) {
          $reasons[] = \sprintf('The file must not contain <%s> elements that target href attributes.', $tag);
        }
      }

      if ($tag === 'style') {
        $reasons = [...$reasons, ...self::validateCss($element->textContent, '<style> element')];
      }

      foreach (\iterator_to_array($element->attributes) as $attribute) {
        \assert($attribute instanceof \DOMAttr);
        $reasons = [...$reasons, ...self::validateAttribute($attribute)];
      }
    }

    return \array_values(\array_unique($reasons));
  }

  /**
   * Validates a single attribute.
   *
   * @return list<string>
   *   Rejection reasons for this attribute.
   */
  private static function validateAttribute(\DOMAttr $attribute): array {
    $reasons = [];
    $name = \strtolower($attribute->localName ?? '');
    $value = (string) $attribute->value;

    // Event handler attributes (onload, onclick, …) execute script.
    if (\str_starts_with($name, 'on')) {
      $reasons[] = \sprintf('The file must not contain event handler attributes such as "%s".', $name);
    }

    // Normalize the value before scanning for dangerous URLs: the XML parser
    // already decoded character references, so only whitespace and control
    // characters can still mask them.
    $normalized_value = \strtolower((string) \preg_replace('/[\x00-\x20]/', '', $value));
    if (\str_contains($normalized_value, 'javascript:')) {
      $reasons[] = 'The file must not contain "javascript:" URLs.';
    }
    if (\str_contains($normalized_value, 'data:text/html')) {
      $reasons[] = 'The file must not contain "data:text/html" URLs.';
    }

    // References (href, xlink:href, src) may only point at local fragments;
    // external references are forbidden. localName is `href` for both `href`
    // and `xlink:href`.
    if (\in_array($name, self::REFERENCE_ATTRIBUTES, TRUE)) {
      $trimmed_value = \trim($value);
      if ($trimmed_value !== '' && !\str_starts_with($trimmed_value, '#')) {
        $reasons[] = \sprintf('The "%s" attribute must reference a local fragment (starting with "#").', $name);
      }
    }

    if ($name === 'style') {
      $reasons = [...$reasons, ...self::validateCss($value, 'style attribute')];
    }

    return $reasons;
  }

  /**
   * Validates inline CSS from a `<style>` element or `style` attribute.
   *
   * @return list<string>
   *   Rejection reasons for this CSS.
   */
  private static function validateCss(string $css, string $location): array {
    $reasons = [];

    // Browsers decode CSS escape sequences (`\69 mport` → `import`) before
    // interpreting the stylesheet, so decode them here too — otherwise
    // escapes would smuggle `@import` or external `url()` past the checks
    // below. Hex escapes consume one optional trailing whitespace character;
    // any remaining escaped character stands for itself.
    $css = (string) \preg_replace_callback(
      '/\\\\([0-9a-fA-F]{1,6})\s?/',
      static function (array $matches): string {
        $decoded = \mb_chr((int) \hexdec($matches[1]));
        return $decoded === FALSE ? '' : $decoded;
      },
      $css,
    );
    $css = (string) \preg_replace('/\\\\(.)/s', '$1', $css);

    if (\preg_match('/@import/i', $css)) {
      $reasons[] = \sprintf('The %s must not contain "@import".', $location);
    }
    if (\preg_match('/expression\s*\(/i', $css)) {
      $reasons[] = \sprintf('The %s must not contain "expression(".', $location);
    }

    // `url(…)` may only reference local fragments such as gradients/filters.
    if (\preg_match_all('/url\s*\(([^)]*)\)/i', $css, $matches)) {
      foreach ($matches[1] as $url) {
        $url = \trim(\trim($url), '"\'');
        $url = \trim($url);
        if ($url === '' || !\str_starts_with($url, '#')) {
          $reasons[] = \sprintf('The %s must only use url() with local fragment references (starting with "#").', $location);
        }
      }
    }

    return $reasons;
  }

}
