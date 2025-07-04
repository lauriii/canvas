<?php

namespace Drupal\experience_builder\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\experience_builder\Routing\ParametrizedImageStyleConverter;
use Symfony\Component\Routing\RouteCollection;

/**
 * Defines a route subscriber to register a url for serving image styles.
 */
class ImageStyleRoutesEventSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    $public_route = $collection->get('image.style_public');
    if ($public_route !== NULL) {
      $public_route
        ->setOption('parameters', [
          'image_style' => [
            'type' => 'image_style_parametrized',
            'converter' => ParametrizedImageStyleConverter::class,
          ],
        ]);
    }
    $private_route = $collection->get('image.style_private');
    if ($private_route !== NULL) {
      $private_route
        ->setOption('parameters', [
          'image_style' => [
            'type' => 'image_style_parametrized',
            'converter' => ParametrizedImageStyleConverter::class,
          ],
        ]);
    }
  }

}
