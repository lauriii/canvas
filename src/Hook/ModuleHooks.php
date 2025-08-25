<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\experience_builder\Form\FormIdPreRender;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotEqualTo;
use Symfony\Component\Validator\Constraints\Unique;

class ModuleHooks {

  const PAGE_DATA_FORM_ID = 'page_data_form';

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RequestStack $requestStack,
  ) {
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
      // @see `type: experience_builder.page_region.*`
      $definitions['NotEqualTo'] = [
        'label' => 'Not equal to',
        'class' => NotEqualTo::class,
        'type' => ['string'],
        'provider' => 'core',
        'id' => 'NotEqualTo',
      ];
    }
    if (!isset($definitions['Unique'])) {
      // @see `type: experience_builder.folder.*`
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
    // Adds `track_navigation` library to all pages, to allow XB's "Back" link to know which URL to go back to.
    $page['#attached']['library'][] = 'experience_builder/track_navigation';
  }

  /**
   * Implements hook_form_alter().
   *
   * For the "page data" tab aka the content entity form.
   *
   * @see \Drupal\experience_builder\Controller\EntityFormController
   */
  #[Hook('form_alter', order: Order::Last)]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $route_name = $this->routeMatch->getRouteName();
    $form_object = $form_state->getFormObject();
    if ($route_name === 'experience_builder.api.form.content_entity' && $form_object instanceof EntityForm) {
      // Hide submit buttons on the entity form accessed via the XB app.
      $form['actions']['#access'] = \FALSE;
      // Add form ID to elements.
      $form['#pre_render'][] = [FormIdPreRender::class, 'addFormId'];
      $form['#attributes']['data-form-id'] = self::PAGE_DATA_FORM_ID;
      if ($this->requestStack->getCurrentRequest()
          ?->get(AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER) !== \NULL) {
        // Add the data-ajax flag and manually add the form ID as pre render
        // callbacks aren't fired during AJAX rendering because the whole form is
        // not rendered, just the returned elements.
        FormIdPreRender::addAjaxAttribute($form, self::PAGE_DATA_FORM_ID);
      }

      // Remove the revision related fields from the form. These will be handled
      // in future outside of this form.
      unset($form['revision_information']);
      unset($form['revision_log']);
      unset($form['revision']);
    }
  }

}
