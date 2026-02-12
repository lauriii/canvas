<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Form;

use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\NestedArray;
use Drupal\canvas\Entity\PageRegion;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure the theme regions for AI content generation.
 */
final class CanvasAIThemeRegionSettingsForm extends ConfigFormBase {

  /**
   * The default site theme.
   *
   * @var string
   */
  private readonly string $defaultTheme;

  public function __construct(ThemeHandlerInterface $themeHandler) {
    $this->defaultTheme = $themeHandler->getDefault();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('theme_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'canvas_ai_theme_region_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['canvas_ai.theme_region.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('canvas_ai.theme_region.settings');
    $active_regions = $this->getEnabledRegionsForDefaultSiteTheme();
    $form['#tree'] = TRUE;

    if (empty($active_regions)) {
      $theme_settings_url = Url::fromRoute('system.theme_settings_theme', ['theme' => $this->defaultTheme]);

      $form['message'] = [
        '#type' => 'markup',
        '#markup' => $this->t('No page regions are enabled in your theme. Visit the <a href=":url">theme settings</a> to enable regions.', [
          ':url' => $theme_settings_url->toString(),
        ]),
      ];
      return $form;
    }

    $form['message'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>The following page regions are available in your theme and can be used by the <strong>Canvas Template Builder Agent</strong> to build the layout for a complete page (for example, building a homepage for a pizza shop).</p><p>Use this form to describe how each region should be used.</p>'),
    ];

    $descriptions = $config->get('region_descriptions') ?? [];

    foreach ($active_regions as $region) {
      $region_id = $this->getRegionId($region);
      $form[$this->defaultTheme][$region_id] = [
        '#type' => 'details',
        '#title' => $region->label(),
        '#open' => TRUE,
      ];
      $form[$this->defaultTheme][$region_id]['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#description' => $this->t('Provide a description for what kind of content should be placed in this region.'),
        '#default_value' => NestedArray::getValue($descriptions, [$this->defaultTheme, $region_id, 'description']) ?: '',
        '#placeholder' => $this->t("Example: If your theme has two footer regions enabled (footer_top and footer_bottom), you can add instructions like:\n\nFooter Top: \"This region should contain the site name, site logo, and footer navigation links, wrapped in a container component with the 'margin' prop set to 'large'. Use button components with the 'type' prop set to 'link' for navigation links.\"\n\nFooter Bottom: \"This region should contain social media icons using the social media component and a copyright notice using the paragraph component, wrapped in a container component with the 'margin' prop set to 'large'.\""),
        '#rows' => 7,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $active_regions = $this->getEnabledRegionsForDefaultSiteTheme();

    $descriptions = $this->config('canvas_ai.theme_region.settings')->get('region_descriptions') ?? [];
    foreach ($active_regions as $region) {
      $region_id = $this->getRegionId($region);
      $region_name = $region->label();
      NestedArray::setValue($descriptions, [$this->defaultTheme, $region_id], [
        'name' => $region_name,
        'description' => $form_state->getValue([$this->defaultTheme, $region_id, 'description']),
      ]);
    }

    $this->config('canvas_ai.theme_region.settings')
      ->set('region_descriptions', $descriptions)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get the enabled theme regions for the default site theme.
   *
   * @return \Drupal\canvas\Entity\PageRegion[]
   *   An array of enabled PageRegion entities, sorted alphabetically by label.
   */
  protected function getEnabledRegionsForDefaultSiteTheme(): array {
    $regions = PageRegion::loadForTheme($this->defaultTheme);
    $regions = array_filter($regions, fn($region) => $region->status());
    // Sort regions alphabetically by label.
    usort($regions, fn($a, $b) => \strnatcasecmp((string) $a->label(), (string) $b->label()));

    return $regions;
  }

  /**
   * Get region ID.
   *
   * @param \Drupal\canvas\Entity\PageRegion $region
   *   The page region.
   *
   * @return string
   *   The region ID.
   */
  protected function getRegionId(PageRegion $region): string {
    return $region->get('region');
  }

}
