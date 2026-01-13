<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;

/**
 * Controller for handling view mode display forms with content templates.
 */
final class ViewModeDisplayController {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FormBuilderInterface $formBuilder,
  ) {
  }

  /**
   * Renders the view mode display form or redirects to the Canvas editor page.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   * @param string $view_mode_name
   *   The view mode name.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match object.
   *
   * @return array<string, mixed>|\Drupal\Core\Cache\CacheableResponseInterface
   *   A render array for the form or a redirect response.
   */
  public function __invoke(string $entity_type_id, string $bundle, string $view_mode_name, RouteMatchInterface $route_match): array|CacheableResponseInterface {
    $template = ContentTemplate::load("$entity_type_id.$bundle.$view_mode_name");

    // Check if a ContentTemplate exists for this view mode and entity type.
    if ($template instanceof ConfigEntityInterface && $template->status()) {
      // Redirect to the content template preview page.
      $url = Url::fromUri("base:canvas/template/$entity_type_id/$bundle/$view_mode_name");
      $response = new LocalRedirectResponse($url->toString());
      $response->addCacheableDependency($template);
      return $response;
    }

    // Build the entity view display form.
    $form_object = $this->entityTypeManager
      ->getFormObject('entity_view_display', 'edit');

    $entity = $form_object->getEntityFromRouteMatch($route_match, 'entity_view_display');
    $form_object->setEntity($entity);

    return $this->formBuilder->getForm($form_object);
  }

}
