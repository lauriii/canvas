<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Entity\PageRegion;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * @see \Drupal\canvas\Entity\PageRegion
 * @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant
 * @see \Drupal\canvas\Controller\CanvasBlockListController
 */
class PageRegionHooks {

  /**
   * Implements hook_form_FORM_ID_alter() for system_theme_settings.
   */
  #[Hook('form_system_theme_settings_alter')]
  public static function formSystemThemeSettingsAlter(array &$form, FormStateInterface $form_state): void {
    if (empty($form_state->getBuildInfo()['args'][0])) {
      // Do not alter the "Global settings" tab.
      return;
    }
    $theme = $form_state->getBuildInfo()['args'][0];
    $page_regions = PageRegion::loadForTheme($theme);
    $enabled = !empty($page_regions);
    $form['canvas'] = [
      '#type' => 'details',
      '#title' => new TranslatableMarkup('Drupal Canvas'),
      '#weight' => -1,
      '#open' => \TRUE,
    ];
    $form['canvas']['use_canvas'] = [
      '#type' => 'checkbox',
      '#title' => new TranslatableMarkup('Use Drupal Canvas for page templates in this theme.'),
      '#default_value' => $enabled,
    ];
    \array_unshift($form['#submit'], [self::class, 'formSystemThemeSettingsSubmit']);
  }

  public static function formSystemThemeSettingsSubmit(array &$form, FormStateInterface $form_state): void {
    $theme = $form_state->getBuildInfo()['args'][0];
    $enable = $form_state->getValue('use_canvas');
    $existing_page_regions = PageRegion::loadForTheme($theme, TRUE);
    if ($enable) {
      // When enabling: ensure every theme region (other than `content`, a
      // special case that never gets a PageRegion config entity) gets a
      // PageRegion config entity.
      // @see \Drupal\canvas\Plugin\DisplayVariant\CanvasPageVariant::MAIN_CONTENT_REGION
      $page_regions_generated_from_block_layout = PageRegion::createFromBlockLayout($theme);
      foreach (\array_keys(\system_region_list($theme)) as $region_name) {
        $key = "{$theme}.{$region_name}";
        if ($key === $theme . '.' . CanvasPageVariant::MAIN_CONTENT_REGION) {
          continue;
        }
        if (\array_key_exists($key, $existing_page_regions)) {
          $existing_page_regions[$key]->setStatus(TRUE)->save();
          continue;
        }
        $page_regions_generated_from_block_layout[$key]->enable()->save();
      }

      // Rendering happens through the theme's page variant: convert the
      // regions into one (an existing variant is reused, preserving edits)
      // and select it as the site default when nothing else is.
      $regions_by_name = [];
      foreach (PageRegion::loadForTheme($theme, TRUE) as $region) {
        $regions_by_name[$region->get('region')] = $region;
      }
      $variant = \canvas_page_variant_from_page_regions($theme, $regions_by_name);
      if ($variant instanceof PageVariant && $theme === \Drupal::config('system.theme')->get('default')) {
        $settings = \Drupal::configFactory()->getEditable('canvas.settings');
        if ($settings->get(PageVariant::DEFAULT_SETTING) === NULL) {
          // A reused variant may have been disabled since; a disabled site
          // default would bypass SiteDefaultPageVariantEnabled here and then
          // fail every validated save of the variant. Enabling Canvas for the
          // theme means rendering through its variant again, so re-enable it.
          if (!$variant->status()) {
            $variant->enable()->save();
          }
          $settings->set(PageVariant::DEFAULT_SETTING, $variant->id())->save();
        }
      }
    }
    else {
      // When disabling: of the PageRegion config entities that exist, disable
      // the ones that are enabled (aka "editable").
      foreach ($existing_page_regions as $region) {
        $region->disable()->save();
      }

      // Return rendering to core's block layout when this theme's variant is
      // the site default. The variant itself is kept: re-enabling restores it.
      $variant_id = 'theme_' . \preg_replace('/[^a-z0-9_]/', '_', (string) $theme);
      $settings = \Drupal::configFactory()->getEditable('canvas.settings');
      if ($settings->get(PageVariant::DEFAULT_SETTING) === $variant_id) {
        $settings->set(PageVariant::DEFAULT_SETTING, NULL)->save();
      }
    }

    // Avoid polluting the theme settings config entity.
    $form_state->unsetValue('use_canvas');
  }

}
