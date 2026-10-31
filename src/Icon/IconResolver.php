<?php

declare(strict_types=1);

namespace Drupal\canvas\Icon;

use Drupal\Component\Utility\Html;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\Icon\IconDefinitionInterface;
use Drupal\Core\Theme\Icon\Plugin\IconPackManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves stored icon ids into values code components can render directly.
 *
 * The stored value of an icon prop is the core Icon API's full icon id
 * (`pack_id:icon_id`). At preview and render time it is resolved through the
 * pack's extractor into either inline SVG markup or an asset URL, so component
 * authors never embed or manage SVG sources by hand.
 *
 * @internal
 */
final class IconResolver {

  public function __construct(
    private readonly IconPackManagerInterface $iconPackManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Resolves an icon id into a renderable value.
   *
   * @param string $icon_full_id
   *   The full icon id (`pack_id:icon_id`).
   *
   * @return array{id: string, svg?: string, url?: string}|null
   *   An array with the icon id plus either inline `svg` markup or a `url`,
   *   or NULL when the icon cannot be resolved. Resolution failures are
   *   logged; the caller is expected to render nothing, matching the failure
   *   mode core accepts for its own icon render element.
   */
  public function resolve(string $icon_full_id): ?array {
    $icon = $this->iconPackManager->getIcon($icon_full_id);
    if ($icon === NULL) {
      $this->logger->warning('The icon %id could not be resolved to any icon in the installed icon packs.', ['%id' => $icon_full_id]);
      return NULL;
    }

    // The `svg` extractor (and compatible contrib extractors) provide the SVG
    // contents, allowing inline markup that inherits the surrounding text
    // color.
    $content = $icon->getData('content');
    if ($content !== NULL && ($markup = (string) $content) !== '') {
      $attributes = $icon->getData('attributes');
      $attributes = $attributes instanceof Attribute ? clone $attributes : new Attribute();
      if (!$attributes->hasAttribute('xmlns')) {
        $attributes->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
      }
      return [
        'id' => $icon->getId(),
        'svg' => '<svg' . $attributes . '>' . $markup . '</svg>',
      ];
    }

    // The `svg_sprite` extractor: reference the symbol in the sprite file.
    if ($this->getExtractorId($icon) === 'svg_sprite' && $icon->getSource() !== NULL) {
      return [
        'id' => $icon->getId(),
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><use href="' . Html::escape($icon->getSource() . '#' . $icon->getIconId()) . '"/></svg>',
      ];
    }

    // The `path` extractor (and any other extractor exposing a source): the
    // icon is an asset URL.
    if ($icon->getSource() !== NULL) {
      return [
        'id' => $icon->getId(),
        'url' => $icon->getSource(),
      ];
    }

    $this->logger->warning('The icon %id was found in the %pack icon pack, but could not be resolved to inline SVG or a URL.', [
      '%id' => $icon_full_id,
      '%pack' => $icon->getPackId(),
    ]);
    return NULL;
  }

  private function getExtractorId(IconDefinitionInterface $icon): ?string {
    $definition = $this->iconPackManager->getDefinitions()[$icon->getPackId()] ?? NULL;
    return $definition['extractor'] ?? NULL;
  }

}
