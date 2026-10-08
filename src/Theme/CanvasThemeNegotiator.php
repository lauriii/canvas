<?php

declare(strict_types=1);

namespace Drupal\canvas\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Determines the theme to be used for specific Drupal Canvas (Canvas) routes.
 *
 * This theme negotiator uses the `canvas_stark` theme for Drupal Canvas routes
 * serving forms that are intended to be rendered using React, to guarantee
 * predictable markup. Otherwise the Redux integration is likely to break.
 *
 * This also achieves an intentional side effect: nothing of Drupal themes
 * is visible in the component instance form or entity fields forms displayed in
 * Drupal Canvas: `stark` defines no templates, and hence relies on all
 * default templates only.
 *
 * @see ui/src/components/form/twig-to-jsx-component-map.js
 * @see ui/src/components/form/withRHF.tsx
 */
final class CanvasThemeNegotiator implements ThemeNegotiatorInterface {

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
    // `canvas.boot.*` serves the editor application shell. It is claimed here
    // for the same reason the form routes are: without it the shell falls back
    // to the site's default theme, and that front-end theme's
    // `libraries-override` then governs the editor's admin UI. A theme that
    // switches off `system/base`'s `css/components/js.module.css` — a
    // reasonable thing for a site template to do — takes `.js-hide` with it,
    // and every control core hides with that class renders visibly inside the
    // component instance form. The forms are already built by `canvas_stark`;
    // building the document around them with it too is what makes the side
    // effect described above actually hold.
    $use_canvas_stark = str_starts_with($route_name, 'canvas.api.form.') || str_starts_with($route_name, 'canvas.boot.');
    return $use_canvas_stark || $use_admin_theme;
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    $triggering_element_value = $this->requestStack->getCurrentRequest()?->request->get('_triggering_element_value');
    $still_in_media_library = $triggering_element_value !== (string) $this->t('Insert selected');

    if ($this->requestStack->getCurrentRequest()?->query->has('use_admin_theme') && $still_in_media_library) {
      // If the admin theme is not configured, use the default theme.
      $theme_config = $this->configFactory->get('system.theme');
      $admin = (string) $theme_config->get('admin');
      $admin_theme_name = $admin !== '' ? $admin : $theme_config->get('default');
      return $admin_theme_name;
    }
    $this->requestStack->getCurrentRequest()?->query->remove('use_admin_theme');
    return 'canvas_stark';
  }

}
