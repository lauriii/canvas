<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\media_library\MediaLibraryState;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @file
 * Hook implementations that make Redux-integrated field widgets work.
 *
 * @see https://www.drupal.org/project/issues/experience_builder?component=Redux-integrated+field+widgets
 * @see docs/redux-integrated-field-widgets.md
 */
class ReduxIntegratedFieldWidgetsHooks {

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
    if ($extension === 'experience_builder') {
      // We need to dynamically create a 'transforms' library by compiling a list
      // of all module defined transforms - which are libraries prefixed with
      // xb.transform.
      $dependencies = [];
      foreach (\array_keys($this->moduleHandler->getModuleList()) as $module) {
        if ($module === 'experience_builder') {
          // Avoid an infinite loop ♻️.
          continue;
        }
        $module_transforms = \array_filter(\array_keys($this->libraryDiscovery->getLibrariesByExtension($module)), static fn(string $library_name) => \str_starts_with($library_name, 'xb.transform'));
        $dependencies = \array_merge($dependencies, \array_map(static fn(string $library_name) => \sprintf('%s/%s', $module, $library_name), $module_transforms));
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
      // @see \Drupal\experience_builder\Hook\LibraryHooks::customizeDialogLibrary()
      $libraries['ui']['dependencies'][] = 'experience_builder/xb.scoped.admin.css';
    }
  }

  /**
   * Implements hook_field_widget_single_element_WIDGET_TYPE_form_alter().
   *
   * @see \Drupal\experience_builder\MediaLibraryXbPropOpener
   */
  #[Hook('field_widget_single_element_media_library_widget_form_alter')]
  public function fieldWidgetSingleElementMediaLibraryWidgetFormAlter(array &$form, FormStateInterface $form_state, array $context): void {
    if ($this->themeManager->getActiveTheme()->getName() === 'xb_stark') {
      // The following configures the open button to trigger a dialog rendered by
      // the admin theme.
      $request_stack = $this->requestStack;
      $current_route = new CurrentRouteMatch($request_stack);
      $parameters = $current_route->getParameters();
      if ($entity = $parameters->get('entity')) {
        $parameters->set('entity', $entity->id());
      }
      /** @var string $route_name */
      $route_name = $current_route->getRouteName();
      $query = $request_stack->getCurrentRequest()?->query->all() ?? [];
      $query['ajax_form'] = \TRUE;
      $query['use_admin_theme'] = \TRUE;
      // This is the existing AJAX URL with the additional use_admin_theme query
      // argument that is used by XBAdminThemeNegotiator to determine if the admin
      // theme should be used for rendering
      $url = Url::fromRoute($route_name, [
        ...$parameters->all(),
        ...$query,
      ]);
      $form['open_button']['#ajax']['url'] = $url;
      // Add a property to be used by the AjaxCommands.add_css override in
      // ajax.hyperscriptify.js that will identify the CSS as something that should
      // be scoped inside the dialog only.
      $form['open_button']['#ajax']['useAdminTheme'] = \TRUE;
      $form['open_button']['#ajax']['scopeSelector'] = '.media-library-widget-modal';
      $form['open_button']['#ajax']['selectorsToSkip'] = Json::encode([
        '.media-library-widget-modal',
        '.media-library-wrapper',
        '.ui-dialog',
      ]);
    }
    // Use an XB-specific media library opener, because the default opener assumes
    // the media library is opened for a field widget of a field instance on the
    // host entity type. That is not true for XB's "static prop sources".
    // @see \Drupal\experience_builder\PropSource\StaticPropSource
    // @see \Drupal\experience_builder\Form\ComponentInputsForm::buildForm()
    if ($form_state->get('is_xb_static_prop_source') !== \TRUE) {
      return;
    }
    // @see \Drupal\media_library\Plugin\Field\FieldWidget\MediaLibraryWidget::formElement()
    \assert(\array_key_exists('open_button', $form));
    \assert(\array_key_exists('#media_library_state', $form['open_button']));
    $old = $form['open_button']['#media_library_state'];
    \assert($old instanceof MediaLibraryState);
    $form['open_button']['#media_library_state'] = MediaLibraryState::create('experience_builder.media_library.opener', $old->getAllowedTypeIds(), $old->getSelectedTypeId(), $old->getAvailableSlots(), [
      // This single opener parameter is necessary.
      // @see \Drupal\experience_builder\MediaLibraryXbPropOpener::getSelectionResponse()
      'field_widget_id' => $old->getOpenerParameters()['field_widget_id'],
    ]);
  }

  /**
   * Implements hook_field_widget_info_alter().
   */
  #[Hook('field_widget_info_alter')]
  public function fieldWidgetInfoAlter(array &$info): void {
    $map = [
      'boolean_checkbox' => ['mainProperty' => ['list' => \FALSE]],
      'datetime_default' => ['mainProperty' => [], 'dateTime' => []],
      'email_default' => ['mainProperty' => []],
      'image_image' => ['mainProperty' => ['name' => 'fids']],
      'link_default' => ['link' => []],
      'number' => ['mainProperty' => []],
      'options_select' => [],
      'string_textarea' => ['mainProperty' => []],
      'string_textfield' => ['mainProperty' => []],
      'text_textfield' => [
        'mainProperty' => ['name' => 'value'],
        'richText' => [],
      ],
      'text_textarea' => [
        'mainProperty' => ['name' => 'value'],
        'richText' => [],
      ],
      'text_textarea_with_summary' => [
        'mainProperty' => ['name' => 'value'],
        'richText' => [],
      ],
    ];
    foreach ($map as $widget_id => $transforms) {
      if (\array_key_exists($widget_id, $info)) {
        $info[$widget_id]['xb']['transforms'] = $transforms;
      }
    }
  }

  /**
   * Implements hook_field_widget_info_alter().
   */
  #[Hook('field_widget_info_alter', module: 'media_library')]
  public function mediaLibraryFieldWidgetInfoAlter(array &$info): void {
    $info['media_library_widget']['xb'] = [
      'transforms' => [
        'mediaSelection' => [],
        'mainProperty' => ['name' => 'target_id'],
      ],
    ];
  }

}
