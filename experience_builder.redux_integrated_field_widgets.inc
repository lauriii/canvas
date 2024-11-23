<?php

/**
 * @file
 * Hook implementations that make Redux-integrated field widgets work.
 *
 * @see https://www.drupal.org/project/issues/experience_builder?component=Redux-integrated+field+widgets
 * @see docs/redux-integrated-field-widgets.md
 */

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media_library\MediaLibraryState;

/**
 * Implements hook_library_info_alter().
 */
function experience_builder_library_info_alter(array &$libraries, string $extension): void {
  $route_name = \Drupal::routeMatch()->getRouteName();
  if ($route_name === 'experience_builder.experience_builder') {
    if ($extension === 'olivero') {
      // Olivero's `drupal.message` depends on `olivero/global-styling` to ensure
      // a particular ordering only. This causes JS to be loaded in the incorrect
      // order.
      // @see https://git.drupalcode.org/project/experience_builder/-/merge_requests/154#note_356130
      // @see https://www.drupal.org/node/2987444#mr3609-note158377
      // @todo Remove this alter hook implementation once https://www.drupal.org/node/1945262 updates `olivero/drupal.message`.
      unset($libraries['drupal.message']['js']);
      // To be valid it needs either js, css, drupalSettings, or dependencies.
      // Referencing a library that already exists to keep it legit but inert.
      $libraries['drupal.message']['dependencies'] = ['core/drupal'];
    }
    if ($extension === 'core') {
      if (isset($libraries['drupal.ajax']['js']['misc/ajax.js'])) {
        // @todo aggregated JS should still make Drupal.AjaxCommands available
        // to JS that is not part of that aggregation.
        // https://drupal.org/i/3471974
        $libraries['drupal.ajax']['js']['misc/ajax.js']['preprocess'] = FALSE;
      }
    }
  }
}

/**
 * Implements hook_field_widget_single_element_WIDGET_TYPE_form_alter().
 *
 * @see \Drupal\experience_builder\MediaLibraryXbPropOpener
 */
function experience_builder_field_widget_single_element_media_library_widget_form_alter(array &$form, FormStateInterface $form_state, array $context): void {
  // Use an XB-specific media library opener, because the default opener assumes
  // the media library is opened for a field widget of a field instance on the
  // host entity type. That is not true for XB's "static prop sources".
  // @see \Drupal\experience_builder\PropSource\StaticPropSource
  // @see \Drupal\experience_builder\Form\ComponentPropsForm::buildForm()
  if ($form_state->get('is_xb_static_prop_source') !== TRUE) {
    return;
  }

  // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget::formElement()
  assert(array_key_exists('open_button', $form));
  assert(array_key_exists('#media_library_state', $form['open_button']));
  $old = $form['open_button']['#media_library_state'];
  assert($old instanceof MediaLibraryState);
  $form['open_button']['#media_library_state'] = MediaLibraryState::create(
    'experience_builder.media_library.opener',
    $old->getAllowedTypeIds(),
    $old->getSelectedTypeId(),
    $old->getAvailableSlots(),
    [
      // This single opener parameter is necessary.
      // @see \Drupal\experience_builder\MediaLibraryXbPropOpener::getSelectionResponse()
      'field_widget_id' => $old->getOpenerParameters()['field_widget_id'],
    ]
  );
}

/**
 * Implements hook_preprocess_media_library_item__widget().
 *
 * Retrieves the typed data contained by the selected Media entity (if it uses
 * an `image` media source), and makes it available to the XB UI as a
 * `data-media-file` attribute.
 *
 * @see media_library_storage_prop_shape_alter()
 * @todo Revisit in https://www.drupal.org/project/experience_builder/issues/3463842
 */
function experience_builder_preprocess_media_library_item__widget(array &$variables): void {
  $media_id = $variables['element']['remove_button']['#media_id'] ?? NULL;
  $string_split = 'media-library-remove-button-';
  $offset = strpos($variables['element']['remove_button']['#name'], $string_split) + strlen($string_split);
  $associated_field_name = substr($variables['element']['remove_button']['#name'], $offset);
  $variables['content']['remove_button']['#attributes']['data-media-field-name'] = $associated_field_name;
  if ($media_id) {
    $media = Media::load($media_id);
    if (is_null($media)) {
      return;
    }
    $field = $media->get('field_media_' . $media->bundle());
    $fid = $field->getValue()[0]['target_id'];
    $media_file = File::load($fid);
    $value = $field->getValue()[0];
    if (is_null($media_file) || is_null($value)) {
      return;
    }
    // For now, this only supports image media.
    // @see media_library_storage_prop_shape_alter()
    if ($media->bundle() !== 'image') {
      return;
    }
    $prepared = array_intersect_key([
      ...$value,
      // @todo Figure out how to remove these hard-coded assumptions in https://www.drupal.org/project/experience_builder/issues/3463842 — this is assuming `type: string, format: uri-reference` (or more specifically: `$ref: json-schema-definitions://experience_builder.module/image-uri`)
      'src' => $media_file->createFileUrl(),
    ], ['src' => '', 'alt' => '', 'width' => '', 'height' => '']);

    $variables["content"]["remove_button"]["#attributes"]['data-media-file'] = Json::encode($prepared);
  }
}
