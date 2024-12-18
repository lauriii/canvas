<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Determines the theme to be used for specific Experience Builder (XB) routes.
 *
 * This theme negotiator uses the `xb_stark` theme for Experience Builder routes
 * serving forms that are intended to be rendered using React, to guarantee
 * predictable markup. Otherwise the Redux integration is likely to break.
 *
 * This also achieves an intentional side effect: nothing of Drupal themes
 * is visible in the component props form or entity fields forms displayed in
 * Experience Builder: `stark` defines no templates, and hence relies on all
 * default templates only.
 *
 * @see themes/engines/semi_coupled/README.md
 * @see ui/src/components/form/twig-to-jsx-component-map.js
 * @see ui/src/components/form/inputBehaviors.tsx
 */
final class XBThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * The routes that this theme negotiator applies to.
   *
   * @var string[]
   */
  private static $routes = [
    'experience_builder.api.component_inputs_form',
    'experience_builder.api.entity_form',
  ];

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    $route_name = $route_match->getRouteName() ?? '';
    return in_array($route_name, self::$routes, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    return 'xb_stark';
  }

}
