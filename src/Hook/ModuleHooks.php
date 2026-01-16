<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Access\CanvasUiAccessCheck;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\canvas\Form\FormIdPreRender;
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
  public function theme() : array {
    return [
      // We override this template, as it makes Canvas' preview in the "editor
      // frame" and the live version of the field inconsistent if the
      // field.html.twig template is applied.
      'field__component_tree' => [
        'base hook' => 'field',
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
   * Implements hook_validation_constraint_alter().
   */
  #[Hook('validation_constraint_alter')]
  public function validationConstraintAlter(array &$definitions): void {
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
   * Implements hook_form_alter().
   *
   * For the "page data" tab aka the content entity form.
   *
   * @see \Drupal\canvas\Controller\EntityFormController
   */
  #[Hook('form_alter', order: Order::Last)]
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
  public function toolbar(): array {
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
