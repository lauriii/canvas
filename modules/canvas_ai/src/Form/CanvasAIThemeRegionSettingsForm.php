<?php

declare(strict_types=1);

namespace Drupal\canvas_ai\Form;

use Drupal\canvas\Entity\PageVariant;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure the page variant descriptions for AI content generation.
 */
final class CanvasAIThemeRegionSettingsForm extends ConfigFormBase {

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
    $variants = $this->getEnabledPageVariants();
    $form['#tree'] = TRUE;

    if (empty($variants)) {
      $form['message'] = [
        '#type' => 'markup',
        '#markup' => $this->t('No page variants exist yet. Create a page variant before describing how it should be used.'),
      ];
      return $form;
    }

    $form['message'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>The following page variants can be used by the <strong>Canvas Template Builder Agent</strong> to build the layout for a complete page (for example, building a homepage for a pizza shop).</p><p>Use this form to describe how each variant should be used.</p>'),
    ];

    $descriptions = $config->get('variant_descriptions') ?? [];

    foreach ($variants as $variant) {
      $variant_id = $variant->id();
      $form[$variant_id] = [
        '#type' => 'details',
        '#title' => $variant->label(),
        '#open' => TRUE,
      ];
      $form[$variant_id]['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Description'),
        '#description' => $this->t('Provide a description for what kind of content should be placed in this page variant.'),
        // Fall back to the variant's own description when none is configured yet.
        '#default_value' => $descriptions[$variant_id]['description'] ?? (string) $variant->get('description'),
        '#rows' => 7,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $variants = $this->getEnabledPageVariants();

    $descriptions = [];
    foreach ($variants as $variant) {
      $variant_id = $variant->id();
      $descriptions[$variant_id] = [
        'name' => (string) $variant->label(),
        'description' => $form_state->getValue([$variant_id, 'description']),
      ];
    }

    // @todo The Canvas Template Builder Agent still reads theme-region-keyed descriptions in CanvasAiPageBuilderHelper::getAvailableRegions(); wire it to these page variant descriptions.
    $this->config('canvas_ai.theme_region.settings')
      ->set('variant_descriptions', $descriptions)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Gets the enabled page variants, sorted alphabetically by label.
   *
   * @return \Drupal\canvas\Entity\PageVariant[]
   *   An array of enabled PageVariant entities.
   */
  protected function getEnabledPageVariants(): array {
    $variants = PageVariant::loadMultiple();
    $variants = array_filter($variants, fn($variant) => $variant->status());
    usort($variants, fn($a, $b) => \strnatcasecmp((string) $a->label(), (string) $b->label()));

    return $variants;
  }

}
