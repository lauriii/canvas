<?php

/**
 * @file
 * Hook implementations that make Redux-integrated field widgets work.
 *
 * @see https://www.drupal.org/project/issues/experience_builder?component=Redux-integrated+field+widgets
 * @see docs/redux-integrated-field-widgets.md
 */

use Drupal\Component\Serialization\Json;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\media_library\MediaLibraryState;

/**
 * Implements hook_library_info_alter().
 */
function experience_builder_library_info_alter(array &$libraries, string $extension): void {
  $route_name = \Drupal::routeMatch()->getRouteName();
  if ($route_name === 'experience_builder.experience_builder') {
    if ($extension === 'core') {
      if (isset($libraries['drupal.ajax']['js']['misc/ajax.js'])) {
        // @todo aggregated JS should still make Drupal.AjaxCommands available
        // to JS that is not part of that aggregation.
        // https://drupal.org/i/3471974
        $libraries['drupal.ajax']['js']['misc/ajax.js']['preprocess'] = FALSE;
      }
    }
  }
  if ($extension === 'experience_builder') {
    // We need to dynamically create a 'transforms' library by compiling a list
    // of all module defined transforms - which are libraries prefixed with
    // xb.transform.
    $discovery = \Drupal::service(LibraryDiscoveryInterface::class);
    \assert($discovery instanceof LibraryDiscoveryInterface);
    $dependencies = [];
    foreach (\array_keys(\Drupal::moduleHandler()->getModuleList()) as $module) {
      if ($module === 'experience_builder') {
        // Avoid an infinite loop ♻️.
        continue;
      }
      $module_transforms = \array_filter(\array_keys($discovery->getLibrariesByExtension($module)), static fn (string $library_name) => \str_starts_with($library_name, 'xb.transform'));
      $dependencies = \array_merge($dependencies, \array_map(static fn (string $library_name) => \sprintf('%s/%s', $module, $library_name), $module_transforms));
    }
    $dependencies[] = 'experience_builder/xb-ui';
    $libraries['transforms'] = [
      'dependencies' => $dependencies,
      'js' => [],
      'css' => [],
    ];
  }
  if ($extension === 'media_library') {
    // Typically, it's safe to assume the base libraries of a theme are present,
    // but we can't do this in Experience Builder. Here, the Media Library
    // dialog renders with the Admin Theme, but is triggered from a page
    // rendered by the xb_stark theme.
    // @see \Drupal\experience_builder\Theme\XBThemeNegotiator
    // This is mitigated by attaching a dynamically built library that contains
    // the default CSS of the admin theme.
    // @see experience_builder_dialog_library_customize()
    $libraries['ui']['dependencies'][] = 'experience_builder/xb.scoped.admin.css';
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
  // @see \Drupal\experience_builder\Form\ComponentInputsForm::buildForm()
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
  $request_stack = \Drupal::service('request_stack');
  $current_route = new CurrentRouteMatch($request_stack);
  $parameters = $current_route->getParameters();
  if ($entity = $parameters->get('entity')) {
    $parameters->set('entity', $entity->id());
  }

  /** @var string $route_name */
  $route_name = $current_route->getRouteName();
  $query = \Drupal::request()->query->all();
  $query['ajax_form'] = TRUE;
  $query['use_admin_theme'] = TRUE;

  // This is the existing AJAX URL with the additional use_admin_theme query
  // argument that is used by XBAdminThemeNegotiator to determine if the admin
  // theme should be used for rendering
  $url = Url::fromRoute($route_name, [...$parameters->all(), ...$query]);
  $form['open_button']['#ajax']['url'] = $url;

  // Add a property to be used by the AjaxCommands.add_css override in
  // ajax.hyperscriptify.js that will identify the CSS as something that should
  // be scoped inside the dialog only.
  $form['open_button']['#ajax']['useAdminTheme'] = TRUE;
  $form['open_button']['#ajax']['scopeSelector'] = '.media-library-widget-modal';
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
  $variables['content']['remove_button']['#attributes']['data-prop-name'] = $variables['element']['#parents'][2];
  $variables['content']['remove_button']['#attributes']['data-component-uuid'] = $variables['element']['#parents'][1];
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

/**
 * Implements hook_field_widget_info_alter().
 */
function experience_builder_field_widget_info_alter(array &$info): void {
  $map = [
    'boolean_checkbox' => [
      'mainProperty' => [
        'list' => FALSE,
      ],
    ],
    'datetime_default' => [
      'mainProperty' => [],
      'dateTime' => [],
    ],
    'email_default' => [
      'mainProperty' => [],
    ],
    'image_image' => [
      'mainProperty' => ['name' => 'fids'],
    ],
    'link_default' => [
      'link' => [],
    ],
    'number' => [
      'mainProperty' => [],
    ],
    'options_select' => [
      // Deliberately empty, as we don't need any transformation here - but - we
      // want transforms to be applied client-side because when options_select is
      // used for an integer, we want to have a cast transform applied.
    ],
    'string_textarea' => [
      'mainProperty' => [],
    ],
    'string_textfield' => [
      'mainProperty' => [],
    ],
  ];
  foreach ($map as $widget_id => $transforms) {
    if (\array_key_exists($widget_id, $info)) {
      $info[$widget_id]['xb']['transforms'] = $transforms;
    }
  }
}
