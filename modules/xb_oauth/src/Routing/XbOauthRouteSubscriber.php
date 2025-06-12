<?php

declare(strict_types=1);

namespace Drupal\xb_oauth\Routing;

use Drupal\Core\Authentication\AuthenticationCollector;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouteCollection;

class XbOauthRouteSubscriber extends RouteSubscriberBase {

  /**
   * Name of route option XB uses to mark a route as an external API endpoint.
   */
  private const ROUTE_OPTION_EXTERNAL_API = 'xb_external_api';

  public function __construct(
    #[Autowire(service: 'authentication_collector')]
    private readonly AuthenticationCollector $authenticationCollector,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    // The XB routes don't define an `_auth` option, which means that all global
    // authentication providers are allowed to authenticate on these routes. We
    // intend to keep that behavior, but explicitly adding an `_auth` option
    // means only the providers listed in the option are allowed to authenticate.
    // At the same time, we don't want to mark this module's authentication
    // provider as global.
    // So let's collect all global providers, and place `xb_oauth` at the
    // beginning of the list.
    // One exclusion we make is the `oauth2` provider by the Simple OAuth
    // module, which would be redundant with `xb_oauth`.
    // @see \Drupal\xb_oauth\Authentication\Provider\XbOauthAuthenticationProvider
    $providers = array_filter(
      array_keys($this->authenticationCollector->getSortedProviders()),
      fn($provider_id) => $this->authenticationCollector->isGlobal($provider_id) && $provider_id !== 'oauth2'
    );

    foreach ($collection->all() as $route_id => $route) {
      if (str_starts_with($route_id, 'experience_builder.') && $route->getOption(self::ROUTE_OPTION_EXTERNAL_API)) {
        // @see \Drupal\xb_oauth\Authentication\Provider\XbOauthAuthenticationProvider
        $route->setOption('_auth', ['xb_oauth', ...$providers]);
      }
    }
  }

}
