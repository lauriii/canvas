<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\Entity\PageRegion;
use Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant;

/**
 * @see \Drupal\experience_builder\Entity\PageRegion
 * @see \Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant
 * @see \Drupal\experience_builder\Controller\XbBlockListController
 */
class PageRegionHooks {

  /**
   * Implements hook_form_FORM_ID_alter() for system_theme_settings.
   */
  #[Hook('form_system_theme_settings_alter')]
  public function formSystemThemeSettingsAlter(array &$form, FormStateInterface $form_state): void {
    if (empty($form_state->getBuildInfo()['args'][0])) {
      // Do not alter the "Global settings" tab.
      return;
    }
    $theme = $form_state->getBuildInfo()['args'][0];
    $page_regions = PageRegion::loadForTheme($theme);
    $enabled = !empty($page_regions);
    $form['experience_builder'] = [
      '#type' => 'details',
      '#title' => new TranslatableMarkup('Experience Builder'),
      '#weight' => -1,
      '#open' => \TRUE,
    ];
    $form['experience_builder']['use_xb'] = [
      '#type' => 'checkbox',
      '#title' => new TranslatableMarkup('Use Experience Builder for page templates in this theme.'),
      '#default_value' => $enabled,
    ];
    $possible_page_region_ids = \array_combine(\array_map(fn(string $region_name): string => "{$theme}.{$region_name}", \array_keys(\system_region_list($theme))), \system_region_list($theme));
    $form['experience_builder']['editable'] = [
      '#type' => 'checkboxes',
      '#title' => new TranslatableMarkup('Exposed regions'),
      '#options' => $possible_page_region_ids,
      '#states' => ['visible' => [':input[name="use_xb"]' => ['checked' => \TRUE]]],
      '#default_value' => !empty($page_regions) ? \array_keys($page_regions) : \array_keys($possible_page_region_ids),
    ];
    // The `content` region is a special case.
    // @see \Drupal\experience_builder\Plugin\DisplayVariant\XbPageVariant::MAIN_CONTENT_REGION
    $form['experience_builder']['editable'][$theme . '.' . XbPageVariant::MAIN_CONTENT_REGION] = ['#disabled' => \TRUE];
    $form['experience_builder']['editable']['#description'] = new TranslatableMarkup('Checked regions can be modified via Experience Builder. The <q>Content</q> region contains "the main content" on any route and cannot be modified further.');
    \array_unshift($form['#validate'], [self::class, 'formSystemThemeSettingsValidate']);
    \array_unshift($form['#submit'], [self::class, 'formSystemThemeSettingsSubmit']);
  }

  public static function formSystemThemeSettingsValidate(array &$form, FormStateInterface $form_state): void {
    $enable = $form_state->getValue('use_xb');
    $editable = $form_state->getValue('editable');
    if ($enable && empty(array_filter($editable))) {
      $form_state->setErrorByName('editable', t('At least one region must be enabled for Experience Builder to use Experience Builder for page templates in this theme.'));
    }
  }

  public static function formSystemThemeSettingsSubmit(array &$form, FormStateInterface $form_state): void {
    $theme = $form_state->getBuildInfo()['args'][0];
    $enable = $form_state->getValue('use_xb');
    $editable = $form_state->getValue('editable');
    $existing_page_regions = PageRegion::loadForTheme($theme, TRUE);
    if ($enable) {
      // When enabling: ensure every theme region gets a PageRegion config entity.
      $page_regions_generated_from_block_layout = PageRegion::createFromBlockLayout($theme);
      foreach ($editable as $key => $value) {
        // The `content` region never gets a PageRegion config entity.
        if ($key === $theme . '.' . XbPageVariant::MAIN_CONTENT_REGION) {
          continue;
        }

        // Update existing PageRegion config entity's if it exists: mark editable
        // or not based on the checkbox value.
        if (array_key_exists($key, $existing_page_regions)) {
          $existing_page_regions[$key]->setStatus((bool) $value)->save();
          continue;
        }

        // Otherwise, create a PageRegion config, but only for editable regions.
        if ($value) {
          $page_regions_generated_from_block_layout[$key]->enable()->save();
        }
      }
    }
    else {
      // When disabling: of the PageRegion config entities that exist, disable the
      // ones that are enabled (aka "editable").
      foreach ($existing_page_regions as $region) {
        $region->disable()->save();
      }
    }

    // Avoid polluting the theme settings config entity.
    $form_state->unsetValue('use_xb');
    $form_state->unsetValue('editable');
  }

}
