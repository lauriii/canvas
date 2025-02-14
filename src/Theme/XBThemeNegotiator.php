<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Determines the theme to be used for specific Experience Builder (XB) routes.
 *
 * This theme negotiator uses the `xb_stark` theme for Experience Builder routes
 * serving forms that are intended to be rendered using React, to guarantee
 * predictable markup. Otherwise the Redux integration is likely to break.
 *
 * This also achieves an intentional side effect: nothing of Drupal themes
 * is visible in the component inputs form or entity fields forms displayed in
 * Experience Builder: `stark` defines no templates, and hence relies on all
 * default templates only.
 *
 * @see themes/engines/semi_coupled/README.md
 * @see ui/src/components/form/twig-to-jsx-component-map.js
 * @see ui/src/components/form/inputBehaviors.tsx
 */
final class XBThemeNegotiator implements ThemeNegotiatorInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    $route_name = $route_match->getRouteName() ?? '';
    $use_admin_theme = (bool) $this->requestStack->getCurrentRequest()?->query->has('use_admin_theme');
    $use_xb_stark = str_starts_with($route_name, 'experience_builder.api.form.');
    return $use_xb_stark || $use_admin_theme;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    $triggering_element_value = $this->requestStack->getCurrentRequest()?->get('_triggering_element_value');
    $still_in_media_library = $triggering_element_value !== (string) $this->t('Insert selected');

    if ($this->requestStack->getCurrentRequest()?->query->has('use_admin_theme') && $still_in_media_library) {
      return $this->configFactory->get('system.theme')->get('admin');
    }
    $this->requestStack->getCurrentRequest()?->query->remove('use_admin_theme');
    return 'xb_stark';
  }

}
