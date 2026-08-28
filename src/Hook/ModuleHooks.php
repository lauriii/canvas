<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Access\CanvasUiAccessCheck;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Form\FormIdPreRender;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotEqualTo;
use Symfony\Component\Validator\Constraints\Unique;

class ModuleHooks {

  use StringTranslationTrait;

  const PAGE_DATA_FORM_ID = 'page_data_form';

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly AccountInterface $currentUser,
    private readonly CanvasUiAccessCheck $canvasUiAccessCheck,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public static function theme() : array {
    return [
      // We override this template, as it makes Canvas' preview in the "editor
      // frame" and the live version of the field inconsistent if the
      // field.html.twig template is applied.
      'field__component_tree' => [
        'base hook' => 'field',
      ],
      // Entities rendered by a Canvas ContentTemplate must not use their own
      // template (such as `node.html.twig`), but they must still be themed, so
      // that preprocessing runs for them.
      // @see \Drupal\canvas\EntityHandlers\ContentTemplateAwareViewBuilder::getBuildDefaults()
      'canvas_entity_with_content_template' => [
        'render element' => 'elements',
      ],
      'canvas_cta' => [
        'variables' => [
          'icon' => NULL,
          'title' => NULL,
          'description' => NULL,
          'url' => NULL,
          'link_title' => NULL,
        ],
      ],
    ];
  }

  /**
   * Implements hook_preprocess_HOOK().
   *
   * Populates `content` with the entity as rendered by its content template,
   * the same way `template_preprocess_node()` does for `node.html.twig`.
   */
  #[Hook('preprocess_canvas_entity_with_content_template')]
  public static function preprocessCanvasEntityWithContentTemplate(array &$variables): void {
    $variables['content'] = [];
    foreach (Element::children($variables['elements']) as $key) {
      $variables['content'][$key] = $variables['elements'][$key];
    }
  }

  /**
   * Implements hook_validation_constraint_alter().
   */
  #[Hook('validation_constraint_alter')]
  public static function validationConstraintAlter(array &$definitions): void {
    // Add the Symfony validation constraints that Drupal core does not add in
    // \Drupal\Core\Validation\ConstraintManager::registerDefinitions() for
    // unknown reasons. Do it defensively, to not break when this changes.
    if (!isset($definitions['NotEqualTo'])) {
      // @see `type: canvas.page_region.*`
      $definitions['NotEqualTo'] = [
        'label' => 'Not equal to',
        'class' => NotEqualTo::class,
        'type' => ['string'],
        'provider' => 'core',
        'id' => 'NotEqualTo',
      ];
    }
    if (!isset($definitions['Unique'])) {
      // @see `type: canvas.folder.*`
      $definitions['Unique'] = [
        'label' => 'Unique',
        'class' => Unique::class,
        'type' => ['sequence'],
        'provider' => 'core',
        'id' => 'Unique',
      ];
    }
  }

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$page): void {
    $can_access_canvas_ui = $this->canvasUiAccessCheck->access($this->currentUser);

    $access_cacheability = CacheableMetadata::createFromObject($can_access_canvas_ui);
    $access_cacheability->applyTo($page);

    if ($can_access_canvas_ui->isAllowed()) {
      // Adds `track_navigation` library to all pages, to allow Canvas's "Back"
      // link to know which URL to go back to.
      $page['#attached']['library'][] = 'canvas/track_navigation';
    }
  }

  /**
   * Implements hook_form_language_content_settings_form_alter().
   *
   * Disables and unchecks the "Show language selector" option for Canvas pages
   * in the content language settings admin form. Canvas pages must always be
   * created in the site's default language; translated content is managed
   * through the translation workflow.
   */
  #[Hook('form_language_content_settings_form_alter', order: Order::Last)]
  public static function formLanguageContentSettingsFormAlter(array &$form, FormStateInterface $form_state): void {
    if (isset($form['settings'][Page::ENTITY_TYPE_ID])) {
      $form['settings'][Page::ENTITY_TYPE_ID]['#after_build'][] = [
        static::class,
        'afterBuildCanvasPageLanguageSettings',
      ];
      $form['#validate'][] = [
        static::class,
        'validateCanvasPageLanguageSettings',
      ];
    }
  }

  /**
   * After-build callback that adjusts the Canvas language settings form.
   *
   * Runs after the language_configuration element's #process callbacks have
   * built the settings for Canvas pages, so the element is guaranteed
   * to exist.
   */
  public static function afterBuildCanvasPageLanguageSettings(array $element, FormStateInterface $form_state): array {
    $bundle = Page::ENTITY_TYPE_ID;
    $translation_tip = '';

    if (isset($element[$bundle]['settings']['language']['langcode'])) {
      // Force default language of Canvas entities to the site's default
      // language, and disable changing that. Keep this visible to help explain
      // to the user what workflow they can expect. If content translation is
      // also enabled, also add a tip about installing TMGMT or
      // canvas_translate to enable translation as this workflow is different
      // from other entities.
      if (\Drupal::moduleHandler()->moduleExists('content_translation')) {
        $translation_tip .= ' ' . t("Page translations may be created by translating component input values.");
        if (!\Drupal::moduleHandler()->moduleExists('tmgmt') && !\Drupal::moduleHandler()->moduleExists('canvas_translate')) {
          $translation_tip .= ' ' . t('To translate Canvas pages, install either the <a href=":canvas_translate_url">Canvas Translate Extension</a> for a simpler solution or <a href=":tmgmt_url">Translation Management Tool</a> for more advanced use cases.', [
            ':tmgmt_url' => 'https://www.drupal.org/project/tmgmt',
            ':canvas_translate_url' => 'https://www.drupal.org/project/canvas_translate',
          ]);
        }
      }
      $element[$bundle]['settings']['language']['langcode']['#value'] = 'site_default';
      $element[$bundle]['settings']['language']['langcode']['#attributes']['disabled'] = TRUE;
      $element[$bundle]['settings']['language']['langcode']['#description'] = t(
        "All Canvas pages must be created in the site's default language. Creating pages in other languages is not supported."
      ) . $translation_tip;
    }
    // Hide the option and force the value to make sure the UI to change
    // entity language is not available for Canvas pages.
    if (isset($element[$bundle]['settings']['language']['language_alterable'])) {
      $element[$bundle]['settings']['language']['language_alterable']['#value'] = 0;
      $element[$bundle]['settings']['language']['language_alterable']['#access'] = FALSE;
    }
    // Unlike other content entities, Canvas is not integrated with core
    // content translation, so remove configuration for that.
    if (isset($element[$bundle]['settings']['content_translation']['untranslatable_fields_hide'])) {
      $element[$bundle]['settings']['content_translation']['untranslatable_fields_hide']['#value'] = 0;
      $element[$bundle]['settings']['content_translation']['untranslatable_fields_hide']['#access'] = FALSE;
    }

    // Change the label of the Components field to make it clear that the input
    // values of the component instances are what is translatable, not the set
    // of component instances nor their relative position themselves. Remove
    // the Components field from the columns section to simplify the form.
    if (isset($element[$bundle]['fields']['components'])) {
      $element[$bundle]['fields']['components']['#label'] = t('Component input values');
    }
    if (isset($element[$bundle]['columns']['components'])) {
      unset($element[$bundle]['columns']['components']);
    }

    return $element;
  }

  /**
   * Forces Canvas Pages to use symmetrical translations for `components` field.
   *
   * Ensures that the Canvas Page entity type's `components` base field always
   * sets `third_party_settings.content_translation.translation_sync` to be
   * symmetrically translated.
   *
   * @see \Drupal\canvas\ContentTranslation\ComponentTreeFieldSymmetricalTranslationSynchronizer::ensureSymmetricalCanvasPageComponents()
   */
  public static function validateCanvasPageLanguageSettings(array &$form, FormStateInterface $form_state): void {
    $bundle = Page::ENTITY_TYPE_ID;
    $components_columns = $form_state->getValue([
      'settings',
      $bundle,
      $bundle,
      'columns',
      'components',
    ]);
    // Normally absent: afterBuildCanvasPageLanguageSettings() removes the
    // `components` column checkboxes from the form, so this handler forces the
    // symmetrical combination rather than reading user input.
    if (!\is_array($components_columns)) {
      $components_columns = [];
    }
    // Note: `'0'`, not `0`, to comply with the config schema: core's
    // `translation_sync` third party setting is a sequence of strings.
    $components_columns['inputs'] = 'inputs';
    $components_columns['tree'] = '0';
    $form_state->setValue([
      'settings',
      $bundle,
      $bundle,
      'columns',
      'components',
    ], $components_columns);
  }

  /**
   * Implements hook_form_alter().
   *
   * For the "page data" tab aka the content entity form.
   *
   * @see \Drupal\canvas\Controller\EntityFormController
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $route_name = $this->routeMatch->getRouteName();
    $form_object = $form_state->getFormObject();
    if ($route_name === 'canvas.api.form.content_entity' && $form_object instanceof EntityForm) {
      // Hide submit buttons on the entity form accessed via the Canvas app.
      $form['actions']['#access'] = \FALSE;
      // Add form ID to elements.
      $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];
      $form['#attributes']['data-form-id'] = self::PAGE_DATA_FORM_ID;
      $request = $this->requestStack->getCurrentRequest();
      $is_ajax = $request?->request->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER) ?? $request?->query->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER);
      if ($is_ajax !== NULL) {
        // Add the data-ajax flag and manually add the form ID as pre render
        // callbacks aren't fired during AJAX rendering because the whole form
        // is not rendered, just the returned elements.
        FormIdPreRender::addAjaxAttribute($form, self::PAGE_DATA_FORM_ID);
      }

      // Remove the revision related fields from the form. These will be handled
      // in future outside of this form.
      unset($form['revision_information']);
      unset($form['revision_log']);
      unset($form['revision']);
    }
  }

  /**
   * Implements hook_toolbar_alter().
   */
  #[Hook('toolbar')]
  public static function toolbar(): array {
    $items = [];
    $items['canvas'] = [
      '#type' => 'toolbar_item',
      'tab' => [
        '#type' => 'link',
        '#title' => new TranslatableMarkup('Drupal Canvas'),
        '#url' => Url::fromRoute('canvas.boot.empty'),
        '#attributes' => [
          'title' => new TranslatableMarkup('Drupal Canvas'),
          'class' => ['toolbar-icon', 'toolbar-icon-edit'],
        ],
      ],
      '#weight' => 5,
    ];
    return $items;
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   */
  #[Hook('menu_links_discovered_alter', order: new OrderAfter(['navigation']))]
  public function menuLinksDiscoveredAlter(array &$links): void {
    if (isset($links['navigation.content'])) {
      $links['navigation.content']['title'] = $this->t('CMS');
      $links['navigation.content']['options']['icon']['icon_id'] = 'database';
    }
  }

}
