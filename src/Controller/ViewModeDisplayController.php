<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\HtmlEntityFormController;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for handling view mode display forms with content templates.
 */
final class ViewModeDisplayController {

  public function __construct(
    private readonly HtmlEntityFormController $entityFormController,
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
  public function __invoke(string $entity_type_id, string $bundle, string $view_mode_name, Request $request, RouteMatchInterface $route_match): array|CacheableResponseInterface {
    $template = ContentTemplate::load("$entity_type_id.$bundle.$view_mode_name");

    // Check if a ContentTemplate exists for this view mode and entity type.
    if ($template instanceof ConfigEntityInterface && $template->status()) {
      // Redirect to the content template preview page.
      $url = Url::fromUri("base:canvas/template/$entity_type_id/$bundle/$view_mode_name");
      $response = new LocalRedirectResponse($url->toString());
      $response->addCacheableDependency($template);
      return $response;
    }
    // Fallback to the standard view mode display form.
    return $this->entityFormController->getContentResult($request, $route_match);
  }

}
