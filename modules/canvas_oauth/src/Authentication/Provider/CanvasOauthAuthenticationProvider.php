<?php

declare(strict_types=1);

namespace Drupal\canvas_oauth\Authentication\Provider;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\simple_oauth\Authentication\Provider\SimpleOauthAuthenticationProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * OAuth2 authentication provider for Canvas API routes.
 *
 * Conditionally delegates the authentication to the Simple OAuth module's
 * OAuth2 authentication provider.
 *
 * @see \Drupal\simple_oauth\Authentication\Provider\SimpleOauthAuthenticationProvider
 *
 * This authentication provider is added to a subset of the Canvas API routes
 * marked as external API endpoints.
 * @see \Drupal\canvas_oauth\Routing\CanvasOauthRouteSubscriber
 *
 * It applies to artifact routes (upload and manifest sync) and to config entity
 * routes for specific entity types (JavaScript components, asset libraries, and
 * brand kits).
 * @see \Drupal\canvas_oauth\Authentication\Provider\CanvasOauthAuthenticationProvider::applies()
 */
class CanvasOauthAuthenticationProvider implements AuthenticationProviderInterface {

  public function __construct(
    #[Autowire(service: '@simple_oauth.authentication.simple_oauth')]
    private readonly SimpleOauthAuthenticationProvider $simpleOauthAuthenticationProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request) {
    // @see \Drupal\canvas_oauth\Routing\CanvasOauthRouteSubscriber
    $applies_to_config = FALSE;
    $applies_to_content = FALSE;
    // @todo https://www.drupal.org/i/3498525 should verify this is fine
    //   for all eligible content entity types.
    $page_route_names = [
      'canvas.api.content.get',
      'canvas.api.content.get.by_uuid',
      'canvas.api.content.patch',
      'canvas.api.content.list',
      'canvas.api.content.delete',
      'canvas.api.content.create',
    ];
    $route_match = RouteMatch::createFromRequest($request);

    // Special case: artifact upload and push lifecycle routes.
    $named_routes = [
      'canvas.api.artifacts.upload',
      'canvas.api.push.complete',
      'canvas.api.push.fail',
      'canvas.api.push.start',
    ];
    if (\in_array($route_match->getRouteName(), $named_routes, TRUE)) {
      return $this->simpleOauthAuthenticationProvider->applies($request);
    }

    // Special case: media upload route. Media is not a Canvas provided entity.
    if ($route_match->getRouteName() === 'canvas.api.media.upload') {
      return $this->simpleOauthAuthenticationProvider->applies($request);
    }

    // Apply to config entity routes for protected entity types.
    // @see \Drupal\canvas_oauth\Routing\CanvasOauthRouteSubscriber
    $entity_type_id = $route_match->getRawParameter('canvas_config_entity_type_id');
    // Narrow down the config entity types that are protected by this
    // authentication provider.
    $protected_config_entity_types = [
      Component::ENTITY_TYPE_ID,
      JavaScriptComponent::ENTITY_TYPE_ID,
      AssetLibrary::ENTITY_TYPE_ID,
      BrandKit::ENTITY_TYPE_ID,
    ];

    if ($entity_type_id !== NULL && \in_array($entity_type_id, $protected_config_entity_types, TRUE)) {
      $applies_to_config = TRUE;
    }

    if (\in_array($route_match->getRouteName(), $page_route_names, TRUE)) {
      $applies_to_content = TRUE;
    }

    if (!$applies_to_content && !$applies_to_config) {
      return FALSE;
    }

    // Let the Simple OAuth authentication provider decide if the request is
    // protected. It does so by checking if the request has an OAuth2 access
    // token.
    return $this->simpleOauthAuthenticationProvider->applies($request);
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request) {
    // Delegate to the Simple OAuth authentication provider.
    return $this->simpleOauthAuthenticationProvider->authenticate($request);
  }

}
