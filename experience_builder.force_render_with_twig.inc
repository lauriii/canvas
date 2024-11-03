<?php

/**
 * @file
 * Hook implementations used to identify templates that should be Twig rendered.
 *
 * @see themes/xb_stark/templates/process_as_regular_twig
 */

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

function experience_builder_theme_suggestions_form_element(array $variables): array {
  $suggestions = [];
  if (isset($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library')) {
    $suggestions[] = 'form_element__media_library';
  }
  return $suggestions;
}

function experience_builder_theme_suggestions_form(array $variables): array {
  $suggestions = [];
  $ml_view = isset($variables['element']['#id']) && str_contains($variables['element']['#id'], 'views-exposed-form-media-library-widget');
  $ml_form = !empty($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library');
  if ($ml_view || $ml_form) {
    $suggestions[] = 'form__media_library';
  }
  return $suggestions;
}

function experience_builder_theme_suggestions_select(array $variables): array {
  $suggestions = [];
  $ml_view = isset($variables['element']['#id']) && str_contains($variables['element']['#id'], 'views-exposed-form-media-library-widget');
  $ml_form = !empty($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library');
  if ($ml_view || $ml_form) {
    $suggestions[] = 'select__media_library';
  }
  return $suggestions;
}

/**
 * Implements hook_theme_suggestions_form_element_label().
 */
function experience_builder_theme_suggestions_form_element_label(array $variables): array {
  $suggestions = [];
  if (isset($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library')) {
    $suggestions[] = 'form_element_label__media_library';
  }
  return $suggestions;
}

function experience_builder_theme_suggestions_input(array $variables): array {
  $suggestions = [];
  $ml_ajax = isset($variables['element']['#ajax']['wrapper']) && $variables['element']['#ajax']['wrapper'] === 'media-library-wrapper';
  $ml_form = isset($variables['element']['#form_id']) && str_contains($variables['element']['#form_id'], 'media_library');
  if ($ml_ajax || $ml_form) {
    $suggestions[] = 'input__media_library';
  }
  return $suggestions;
}

/**
 * Implements hook_preprocess_form_element().
 */
function experience_builder_preprocess_form_element(array &$variables): void {
  if (!isset($variables['label'])) {
    return;
  }

  if (isset($variables['element']['#form_id'])) {
    $variables['label']['#form_id'] = $variables['element']['#form_id'];
  }
}

/**
 * Implements hook_form_alter().
 *
 * @see experience_builder_form_alter()
 */
function experience_builder_force_render_with_twig_form_alter(array &$form, FormStateInterface $form_state, string $form_id): void {
  $ml_widget = isset($form['#id']) && str_contains($form['#id'], 'views-exposed-form-media-library-widget');
  $ml_form = str_contains($form_id, 'media');
  if ($ml_widget || $ml_form) {
    experience_builder_mark_media_library($form, 'media_library');
  }
}

/**
 * Attaches form id to all form elements.
 *
 * @param array $form
 *   The form or form element which children should have form id attached.
 * @param string $form_id
 *   The form id attached to form elements.
 */
function experience_builder_mark_media_library(array &$form, string $form_id): void {
  foreach (Element::children($form) as $child) {
    if (!isset($form[$child]['#form_id'])) {
      $form[$child]['#form_id'] = $form_id;
    }
    experience_builder_mark_media_library($form[$child], $form_id);
  }
}
