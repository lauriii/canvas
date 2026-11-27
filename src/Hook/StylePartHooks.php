<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Template\Attribute;

/**
 * Gives every render of an SDC with named parts its part attributes.
 *
 * Canvas fills `{part}_attributes` with the part's styles. Anywhere else (a
 * theme including the component, an older Canvas version of it), the
 * attributes are empty, so templates can print them without a fallback:
 * `<h1{{ heading_attributes.addClass('x') }}>`.
 *
 * @see docs/adr/0033-style-parts.md
 * @see \Drupal\canvas\ComponentSource\ComponentSourceBase::applyStyles()
 */
final class StylePartHooks implements TrustedCallbackInterface {

  /**
   * Implements hook_element_info_alter().
   */
  #[Hook('element_info_alter')]
  public function elementInfoAlter(array &$info): void {
    if (isset($info['component'])) {
      // First, so the props are set before core builds the template.
      \array_unshift($info['component']['#pre_render'], [self::class, 'preRenderParts']);
    }
  }

  /**
   * Adds empty attributes for each declared part that has none.
   */
  public static function preRenderParts(array $element): array {
    $id = $element['#component'] ?? NULL;
    if (!\is_string($id)) {
      return $element;
    }
    // Cached plugin definitions: no extra work for components without parts.
    $definition = \Drupal::service('plugin.manager.sdc')->getDefinition($id, FALSE);
    $parts = $definition['thirdPartySettings']['canvas']['style_parts'] ?? NULL;
    if (\is_array($parts)) {
      foreach (\array_keys($parts) as $part) {
        $element['#props'][$part . '_attributes'] ??= new Attribute();
      }
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderParts'];
  }

}
