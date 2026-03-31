<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\canvas\Form\ComponentInstanceForm;
use Drupal\media_library\MediaLibraryState;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @file
 * Hook implementations that make Redux-integrated field widgets work.
 *
 * @see https://www.drupal.org/project/issues/canvas?component=Redux-integrated+field+widgets
 * @see docs/redux-integrated-field-widgets.md
 */
class ReduxIntegratedFieldWidgetsHooks implements TrustedCallbackInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
    private readonly RequestStack $requestStack,
    private readonly ThemeManagerInterface $themeManager,
  ) {
  }

  /**
   * Implements hook_library_info_alter().
   */
  #[Hook('library_info_alter')]
  public function transformsLibraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'canvas') {
      // We need to dynamically create a 'transforms' library by compiling a
      // list of all module defined transforms - which are libraries prefixed
      // with `canvas.transform`.
      $dependencies = [];
      foreach (\array_keys($this->moduleHandler->getModuleList()) as $module) {
        if ($module === 'canvas') {
          // Avoid an infinite loop ♻️.
          continue;
        }
        $module_transforms = \array_filter(\array_keys($this->libraryDiscovery->getLibrariesByExtension($module)), static fn(string $library_name) => \str_starts_with($library_name, 'canvas.transform'));
        $dependencies = \array_merge($dependencies, \array_map(static fn(string $library_name) => \sprintf('%s/%s', $module, $library_name), $module_transforms));
      }
      $dependencies[] = 'canvas/canvas-ui';
      $libraries['transforms'] = [
        'dependencies' => $dependencies,
        'js' => [],
        'css' => [],
      ];
    }
    if ($extension === 'media_library') {
      // Typically, it's safe to assume the base libraries of a theme are
      // present, but we can't do this in Drupal Canvas. Here, the Media Library
      // dialog renders with the Admin Theme, but is triggered from a page
      // rendered by the canvas_stark theme.
      // @see \Drupal\canvas\Theme\CanvasThemeNegotiator
      // This is mitigated by attaching a dynamically built library that
      // contains the default CSS of the admin theme.
      // @see \Drupal\canvas\Hook\LibraryHooks::customizeDialogLibrary()
      $libraries['ui']['dependencies'][] = 'canvas/canvas.scoped.admin.css';
    }
  }

  /**
   * Implements hook_field_widget_single_element_WIDGET_TYPE_form_alter().
   *
   * @see \Drupal\canvas\MediaLibraryCanvasPropOpener
   */
  #[Hook('field_widget_single_element_media_library_widget_form_alter')]
  public function fieldWidgetSingleElementMediaLibraryWidgetFormAlter(array &$form, FormStateInterface $form_state, array $context): void {
    if ($this->themeManager->getActiveTheme()->getName() === 'canvas_stark') {
      // The following configures the open button to trigger a dialog rendered
      // by the admin theme.
      $request_stack = $this->requestStack;
      $current_route = new CurrentRouteMatch($request_stack);
      $parameters = $current_route->getRawParameters();
      /** @var string $route_name */
      $route_name = $current_route->getRouteName();
      $query = $request_stack->getCurrentRequest()?->query->all() ?? [];
      $query['ajax_form'] = \TRUE;
      $query['use_admin_theme'] = \TRUE;
      // This is the existing AJAX URL with the additional use_admin_theme query
      // argument that is used by CanvasAdminThemeNegotiator to determine if the
      // admin theme should be used for rendering
      $url = Url::fromRoute($route_name, [
        ...$parameters->all(),
        ...$query,
      ]);
      $form['open_button']['#ajax']['url'] = $url;
      $form['open_button']['#attributes']['data-canvas-media-library-open-button'] = 'true';
      // Add a property to be used by the AjaxCommands.add_css override in
      // ajax.hyperscriptify.js that will identify the CSS as something that
      // should be scoped inside the dialog only.
      $form['open_button']['#ajax']['useAdminTheme'] = \TRUE;
      $form['open_button']['#ajax']['scopeSelector'] = '.media-library-widget-modal';
      $form['open_button']['#ajax']['selectorsToSkip'] = Json::encode([
        '.media-library-widget-modal',
        '.media-library-wrapper',
        '.ui-dialog',
      ]);

      // Most hidden fields are read only. Add an attribute that allows it to be
      // updated and tracked in Redux form state.
      $selections = $form['selection'] ?? [];
      $is_multiple = $context['items']->getFieldDefinition()->getFieldStorageDefinition()->isMultiple();
      foreach (Element::children($selections) as $key) {
        if (isset($form['selection'][$key]['target_id'])) {
          $form['selection'][$key]['target_id']['#attributes']['data-track-hidden-value'] = 'true';
        }
        if (isset($form['selection'][$key]['weight'])) {
          $form['selection'][$key]['weight']['#attributes']['data-track-hidden-value'] = 'true';
          $form['selection'][$key]['weight']['#attributes']['data-canvas-media-weight'] = 'true';
        }
        $form['selection'][$key]['remove_button']['#attributes']['data-canvas-media-remove-button'] = 'true';
        $form['selection'][$key]['#attributes']['data-is-multiple'] = $is_multiple ? 'true' : 'false';
      }

    }
    // Use a Canvas-specific media library opener, because the default opener
    // assumes the media library is opened for a field widget of a field
    // instance on the host entity type. That is not true for Canvas's "static
    // prop sources".
    // @see \Drupal\canvas\PropSource\StaticPropSource
    // @see \Drupal\canvas\Form\ComponentInstanceForm::buildForm()
    if ($form_state->get('is_canvas_static_prop_source') !== \TRUE) {
      return;
    }
    // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget::formElement()
    \assert(\array_key_exists('open_button', $form));
    \assert(\array_key_exists('#media_library_state', $form['open_button']));
    $old = $form['open_button']['#media_library_state'];
    \assert($old instanceof MediaLibraryState);
    $form['open_button']['#media_library_state'] = MediaLibraryState::create('canvas.media_library.opener', $old->getAllowedTypeIds(), $old->getSelectedTypeId(), $old->getAvailableSlots(), [
      // This single opener parameter is necessary.
      // @see \Drupal\canvas\MediaLibraryCanvasPropOpener::getSelectionResponse()
      'field_widget_id' => $old->getOpenerParameters()['field_widget_id'],
    ]);
  }

  #[Hook('field_widget_single_element_link_default_form_alter')]
  public function fieldWidgetSingleElementLinkDefaultWidgetFormAlter(array &$form, FormStateInterface $form_state, array $context): void {
    if ($this->themeManager->getActiveTheme()->getName() === 'canvas_stark') {
      // We want the link widget to have less information, so we need to revert
      // the description mangling happening at
      // \Drupal\link\Plugin\Field\FieldWidget\LinkWidget::formElement.
      if (!empty($form['#description'])) {
        $form['uri']['#description'] = $form['#description'];
      }
      else {
        unset($form['uri']['#description']);
      }
    }
  }

  #[Hook('field_widget_single_element_path_form_alter')]
  public function fieldWidgetSingleElementPathFormAlter(array &$form, FormStateInterface $form_state, array $context): void {
    $current_route = new CurrentRouteMatch($this->requestStack);
    $route_name = $current_route->getRouteName();
    $is_entity_form = $route_name === 'canvas.api.form.content_entity';

    if ($this->themeManager->getActiveTheme()->getName() === 'canvas_stark' && $is_entity_form) {
      // Remove the description from the path alias field to have less
      // information.
      if (isset($form['alias']['#description'])) {
        unset($form['alias']['#description']);
      }
    }
  }

  /**
   * Implements hook_field_widget_complete_form_alter().
   *
   * Marks elements within multivalue forms to enable specialized rendering.
   * This allows the DrupalInputMultivalueForm component to handle inputs
   * specifically within multivalue widgets.
   *
   * For media library widgets in the component instance form, we also set
   * #component_prop_name so that the DefaultImagePreview React component can
   * identify which prop to manage. This enables the "Remove default"
   * functionality for optional image props.
   *
   * @see themes/canvas_stark/templates/media_library/fieldset--media-library-widget.html.twig
   * @see ui/src/components/form/components/DefaultImagePreview.tsx
   * @see \Drupal\canvas\Form\ComponentInstanceForm
   */
  #[Hook('field_widget_complete_form_alter')]
  public function fieldWidgetCompleteFormAlter(array &$widget, FormStateInterface $form_state, array $context): void {
    // Provide additional context to be used by
    // canvas_theme_suggestions_alter().
    $widget_type = $context['widget']->getPluginId();
    // Set #widget-type so themeSuggestionsAlter() can add widget-specific
    // theme suggestions (e.g. fieldset__widget_media_library_widget).
    $widget['#widget-type'] = $widget_type;
    if (isset($widget['widget']) && \is_array($widget["widget"])) {
      $widget["widget"]['#widget-type'] = $widget_type;
      foreach (Element::children($widget['widget']) as $key) {
        $widget['widget'][$key]['#widget-type'] = $widget_type;
        foreach (Element::children($widget['widget'][$key]) as $child_key) {
          $widget['widget'][$key][$child_key]['#widget-type'] = $widget_type;
        }
      }
    }

    // For media library widgets in ComponentInstanceForm, propagate
    // #component_prop_name so DefaultImagePreview can identify the prop,
    // show the default image preview, and enable "Remove default" for
    // optional image props.
    $form_object = $form_state->getFormObject();
    $is_component_instance_form = $form_object !== NULL && $form_object->getFormId() === ComponentInstanceForm::FORM_ID;
    if ($widget_type === 'media_library_widget' && $is_component_instance_form) {
      $field_name = $context['items']->getFieldDefinition()->getName();
      $widget['#component_prop_name'] = $field_name;

      if (isset($widget['widget'])) {
        $widget['widget']['#component_prop_name'] = $field_name;
        foreach (Element::children($widget['widget']) as $key) {
          $widget['widget'][$key]['#component_prop_name'] = $field_name;
        }
      }
    }

    if ($this->moduleHandler->moduleExists('canvas_dev_mode')) {
      // Check if this is a multivalue field.
      $field_definition = $context['items']->getFieldDefinition();
      $is_multiple = $field_definition->getFieldStorageDefinition()->isMultiple();

      if ($is_multiple && $this->themeManager->getActiveTheme()->getName() === 'canvas_stark') {
        // Get the field label to add to all input elements.
        $field_label = $field_definition->getLabel();
        // Mark all input elements within multivalue widgets and add field
        // title.
        $this->markMultivalueFormElements($widget, $field_label);
      }
    }
  }

  /**
   * Recursively marks form elements as part of a multivalue form.
   *
   * @param array &$element
   *   The form element to process.
   * @param string $field_label
   *   The field label to add to input elements.
   */
  private function markMultivalueFormElements(array &$element, string $field_label): void {
    foreach (Element::children($element) as $key) {
      // Mark input elements.
      if (isset($element[$key]['#type']) &&
          \in_array($element[$key]['#type'], ['textfield', 'number', 'url', 'entity_autocomplete'], TRUE)) {
        $element[$key]['#is_multivalue_form'] = TRUE;
        $element[$key]['#attributes']['data-field-label'] = $field_label;
        // Hide the sub-field label for url and entity_autocomplete types so
        // that labels like "URL" are not shown in the multivalue table rows.
        if (\in_array($element[$key]['#type'], ['url', 'entity_autocomplete'], TRUE)) {
          $element[$key]['#title_display'] = 'invisible';
        }
      }

      // Skip recursion into datetime elements since they are handled as a unit
      // by the DrupalDatetimeMultivalueForm component.
      if (isset($element[$key]['#type']) && $element[$key]['#type'] === 'datetime') {
        $element[$key]['#multivalue_field_label'] = $field_label;
        continue;
      }

      // Recursively process child elements.
      if (\is_array($element[$key])) {
        $this->markMultivalueFormElements($element[$key], $field_label);
      }
    }
  }

  /**
   * Implements hook_field_widget_info_alter().
   */
  #[Hook('field_widget_info_alter')]
  public function fieldWidgetInfoAlter(array &$info): void {
    $map = [
      'boolean_checkbox' => ['mainProperty' => []],
      'datetime_default' => ['mainProperty' => [], 'dateTime' => []],
      'daterange_default' => ['dateRange' => []],
      'email_default' => ['mainProperty' => []],
      'file_generic' => ['mainProperty' => ['name' => 'fids']],
      'image_image' => ['mainProperty' => ['name' => 'fids']],
      'link_default' => ['link' => []],
      'number' => ['mainProperty' => []],
      'options_select' => [],
      'string_textarea' => ['mainProperty' => []],
      'string_textfield' => ['mainProperty' => []],
      'text_textfield' => [
        'mainProperty' => ['name' => 'value'],
      ],
      'text_textarea' => [
        'mainProperty' => ['name' => 'value'],
      ],
      'text_textarea_with_summary' => [
        'mainProperty' => ['name' => 'value'],
      ],
    ];
    foreach ($map as $widget_id => $transforms) {
      if (\array_key_exists($widget_id, $info)) {
        $info[$widget_id]['canvas']['transforms'] = $transforms;
      }
    }
  }

  /**
   * Implements hook_field_widget_info_alter().
   */
  #[Hook('field_widget_info_alter', module: 'media_library')]
  public function mediaLibraryFieldWidgetInfoAlter(array &$info): void {
    $info['media_library_widget']['canvas'] = [
      'transforms' => [
        'mediaSelection' => [],
        'mainProperty' => ['name' => 'target_id'],
      ],
    ];
  }

  #[Hook('element_info_alter', order: new OrderAfter(['editor']))]
  public function elementInfoAlter(array &$info): void {
    if (isset($info['text_format'])) {
      $info['text_format']['#process'][] = [ReduxIntegratedFieldWidgetsHooks::class, 'processTextFormat'];
      $info['text_format']['#pre_render'][] = [ReduxIntegratedFieldWidgetsHooks::class, 'preRenderTextFormat'];
    }
  }

  /**
   * Further processes a text format element.
   *
   * Runs after TextFormat::processFormat().
   *
   * @see \Drupal\filter\Element\TextFormat::processFormat()
   */
  public static function processTextFormat(array $element, FormStateInterface $form_state, array &$form): array {
    $form_id = $form['form_id']['#value'] ?? NULL;

    // If we aren't in the component instance form, remove text formats that are
    // exclusive to that form.
    // @see \Drupal\canvas\Hook\ShapeMatchingHooks::filterFormatAccess()
    if ($form_id !== ComponentInstanceForm::FORM_ID) {
      // @see config/install/filter.format.canvas_html_block.yml
      unset($element['format']['format']['#options']['canvas_html_block']);
      // @see config/install/filter.format.canvas_html_inline.yml
      unset($element['format']['format']['#options']['canvas_html_inline']);
    }
    return $element;
  }

  public static function preRenderTextFormat(array $element): array {
    // Only proceed if this is a Canvas page data or component instance form.
    // This restructures the render array to simplify integration of the
    // CKEditor5 React component.
    if (isset($element['#attributes']['data-form-id']) && \in_array($element['#attributes']['data-form-id'], [ComponentInstanceForm::FORM_ID, ModuleHooks::PAGE_DATA_FORM_ID], TRUE)) {
      $element['value']['#attributes']['data-form-id'] = $element['#attributes']['data-form-id'];
      // The data-editor-for attribute triggers a vanilla JS initialization of
      // CKEditor5. Rename the attribute so we can instead use a React-specific
      // version.
      if (isset($element['format']['editor']['#attributes']['data-editor-for'])) {
        // Rename data-editor-for for instances where one format is available.
        $element['format']['editor']['#attributes']['data-canvas-editor-for'] = $element['format']['editor']['#attributes']['data-editor-for'];
        unset($element['format']['editor']['#attributes']['data-editor-for']);
      }

      if (isset($element['format']['format']['#attributes']['data-editor-for'])) {
        // Rename data-editor-for for instances where multiple formats are
        // available.
        $element['format']['format']['#attributes']['data-canvas-editor-for'] = $element['format']['format']['#attributes']['data-editor-for'];
        unset($element['format']['format']['#attributes']['data-editor-for']);
        // If multiple formats are available, there will be a select element.
        // Serialize the select attributes so they can be applied in React as
        // part of a Formatted Text component and not an isolated select.
        // Include the #name and #id render array properties as name and id
        // attributes.
        \assert(\is_iterable($element['format']['format']['#attributes']));
        $element['value']['#attributes']['data-canvas-format-select-attributes'] = Json::encode([...$element['format']['format']['#attributes'], 'name' => $element['format']['format']['#name'], 'id' => $element['format']['format']['#id']]);
        if (isset($element['format']['format']['#options'])) {
          // Serialize the list of available text formats to pass via attribute.
          $element['value']['#attributes']['data-canvas-available-formats'] = Json::encode($element['format']['format']['#options']);
        }
      }

      // Remove the format selector render array. The necessary information is
      // passed via attributes and handled centrally in the
      // DrupalFormattedTextArea component.
      unset($element['format']['format']);

      if (isset($element['#format'])) {
        // Make the currently selected format known to the textarea.
        $element['value']['#attributes']['data-canvas-text-format'] = $element['#format'];
      }

      // Remove the help text container when in Drupal Canvas.
      // @todo Remove after https://www.drupal.org/i/3505370 has landed.
      unset($element['format']['help']);
    }
    return $element;
  }

  public static function trustedCallbacks() {
    return ['preRenderTextFormat'];
  }

}
