<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the CanvasPage entity forms.
 *
 * Overrides the default form to add SEO settings and other customizations.
 */
final class CanvasPageForm extends ContentEntityForm {

  /**
   * The language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);
    $instance->languageManager = $container->get('language_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    // Add simple SEO settings group.
    $form = parent::form($form, $form_state);
    $group = 'seo_settings';
    $form[$group] = [
      '#type' => 'details',
      '#group' => 'advanced',
      '#weight' => -10,
      '#title' => $this->t('SEO settings'),
      '#tree' => TRUE,
      // Not keeping it open messes up media library ajax
      // when rendering the form in Canvas UI.
      // @todo remove this once https://www.drupal.org/project/canvas/issues/3501626 lands.
      '#open' => TRUE,
    ];

    // Move SEO related base fields to this group.
    $form[$group]['image'] = $form['image'];
    $form[$group]['image']['#weight'] = 20;
    // TRICKY: it seems there's a Drupal core bug wrt #group, long-term fix TBD.
    // @see https://git.drupalcode.org/project/canvas/-/merge_requests/501#note_448716
    unset($form['image']);
    $form[$group]['description'] = $form['description'];
    $form[$group]['description']['#weight'] = 10;
    unset($form['description']);

    // Hide most metatags fields until further decision is made which ones to
    // show and how. The fields are confusing and numerous.
    if (isset($form['metatags']['widget'][0])) {
      $form['metatags']['widget'][0]['#type'] = 'container';
      $form['metatags']['widget'][0]['basic']['#type'] = 'container';
      $form['metatags']['widget'][0]['basic']['description']['#access'] = FALSE;
      $form['metatags']['widget'][0]['basic']['abstract']['#access'] = FALSE;
      $form['metatags']['widget'][0]['basic']['keywords']['#access'] = FALSE;
      $form['metatags']['widget'][0]['preamble']['#access'] = FALSE;
      $form['metatags']['widget'][0]['tokens']['#access'] = FALSE;
      $form['metatags']['widget'][0]['image_help']['#access'] = FALSE;
      $form['metatags']['widget'][0]['intro_text']['#access'] = FALSE;
      $form['metatags']['widget'][0]['advanced']['#access'] = FALSE;
    }

    if (isset($form['metatags']['widget'][0]['basic']['title'])) {
      // Move the SEO title field only into the custom SEO settings group.
      $form['metatags']['widget'][0]['basic']['title']['#group'] = $group;
      // Hide the description to avoid showing the unnecessary help text.
      unset($form['metatags']['widget'][0]['basic']['title']['#description']);
    }

    // Add the search result preview.
    $form[$group]['serp_preview'] = [
      '#type' => 'inline_template',
      '#template' => '<drupal-canvas-serp-preview></drupal-canvas-serp-preview>',
      '#weight' => -20,
    ];

    $this->groupPageTemplateSelection($form);
    $this->addTransliterationSettings($form);
    $this->customizePathField($form);

    if (isset($form['langcode'])) {
      $form['langcode']['#access'] = FALSE;
    }

    return $form;
  }

  /**
   * Groups the page template selection into a collapsed section.
   *
   * The selection is rarely changed, so it takes no space until expanded;
   * expanding it offers the selection plus a shortcut to edit the template.
   */
  private function groupPageTemplateSelection(array &$form): void {
    if (!isset($form['page_variant'])) {
      return;
    }
    $group = 'page_template';
    $form[$group] = [
      '#type' => 'details',
      '#title' => $this->t('Page template'),
      '#open' => FALSE,
      // Below the URL alias field, whose widget weight ends up at 30.
      '#weight' => 35,
    ];
    $form[$group]['page_variant'] = $form['page_variant'];
    // The section title already names the field.
    $form[$group]['page_variant']['widget']['#title_display'] = 'invisible';
    // The selection comes first, the edit shortcut below it.
    $form[$group]['page_variant']['#weight'] = 0;
    // The "Site default" option makes the field description redundant here.
    unset($form[$group]['page_variant']['widget']['#description']);
    // @see \Drupal\canvas\Entity\CanvasPageForm::form() for why fields move
    // into the group instead of using #group.
    unset($form['page_variant']);

    // A shortcut to edit the template that currently renders this page, for the
    // users who are allowed to edit it. The template editor route is guarded by
    // the same permission, so without it the shortcut only leads to a 403.
    $entity = $this->getEntity();
    \assert($entity instanceof Page);
    $selected = $entity->get('page_variant')->value
      ?? $this->config('canvas.settings')->get(PageVariant::DEFAULT_SETTING);
    if (\is_string($selected)
      && $this->currentUser()->hasPermission(PageVariant::ADMIN_PERMISSION)
      && PageVariant::load($selected) !== NULL) {
      $form[$group]['edit_template'] = [
        '#type' => 'link',
        '#title' => $this->t('Edit template'),
        // TRICKY: an unrouted base: URI, because /canvas/* is an SPA route
        // that Drupal's router would collapse to /canvas.
        '#url' => Url::fromUri('base:canvas/editor/page_variant/' . $selected),
        '#attributes' => [
          'class' => ['canvas-page-template-edit'],
          'data-testid' => 'canvas-page-template-edit',
        ],
        '#weight' => 10,
      ];
    }
  }

  /**
   * Adds transliteration language settings to the form.
   *
   * @param array $form
   *   The form array to modify.
   */
  private function addTransliterationSettings(array &$form): void {
    $current_language = $this->languageManager->getCurrentLanguage();
    $langcode = $current_language->getId();
    $form['#attached']['drupalSettings']['langcode'] = $langcode;

    // Get language overrides from the transliteration service.
    $overrides = $this->getTransliterationLanguageOverrides($langcode);
    $form['#attached']['drupalSettings']['transliteration_language_overrides'] = [$langcode => $overrides];
  }

  /**
   * Customizes the path field appearance and position.
   *
   * Moves the path field out of advanced settings and positions it after the
   * title.
   *
   * @param array $form
   *   The form array to modify.
   */
  private static function customizePathField(array &$form): void {
    // Remove the details wrapper from the path widget to make it a direct form
    // element.
    if (isset($form['path']['widget'][0]['#type']) && $form['path']['widget'][0]['#type'] === 'details') {
      $form['path']['widget'][0]['#type'] = 'container';
      // Remove unnecessary attributes from the container.
      unset($form['path']['widget'][0]['#open']);
      unset($form['path']['widget'][0]['#title']);
    }

    // Remove path field from the advanced group to position it in the main
    // form.
    unset($form['path']['#group']);

    // Also unset group at the widget level, which is where Path module may add
    // it.
    unset($form['path']['widget'][0]['#group']);

    // Set a low positive weight to place it just after the title field.
    $form['path']['#weight'] = 5;
  }

  /**
   * Gets transliteration language overrides for a language.
   *
   * This is duplicating
   * \Drupal\Core\Render\Element\MachineName::getTransliterationLanguageOverrides
   * because it's not available as an API.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   The language overrides.
   *
   * @see \Drupal\Core\Transliteration\PhpTransliteration::readLanguageOverrides()
   * @see \Drupal\Core\Render\Element\MachineName::getTransliterationLanguageOverrides
   */
  private function getTransliterationLanguageOverrides(string $langcode): array {
    $overrides = &drupal_static(__CLASS__ . '_' . __METHOD__, []);

    if (isset($overrides[$langcode])) {
      return $overrides[$langcode];
    }
    // This is where the data files are stored.
    $data_directory = DRUPAL_ROOT . '/core/lib/Drupal/Component/Transliteration/data';
    $file = $data_directory . '/' . preg_replace('/[^a-zA-Z\-]/', '', $langcode) . '.php';

    $overrides[$langcode] = [];
    if (is_file($file)) {
      include $file;
    }

    $this->moduleHandler->alter('transliteration_overrides', $overrides[$langcode], $langcode);

    return [$langcode => $overrides[$langcode]];
  }

}
