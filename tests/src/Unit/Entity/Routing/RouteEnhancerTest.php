<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Unit\Entity\Routing;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\experience_builder\Entity\Routing\XbHtmlRouteEnhancer;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Drupal\experience_builder\Controller\ExperienceBuilderController;

/**
 * @coversDefaultClass \Drupal\experience_builder\Entity\Routing\XbHtmlRouteEnhancer
 * @group experience_builder
 */
final class RouteEnhancerTest extends UnitTestCase {

  /**
   * @covers ::enhance
   * @covers ::applies
   *
   * @dataProvider data
   */
  public function testEnhance(array $original, array $enhanced): void {
    $sut = new XbHtmlRouteEnhancer();
    $route = new Route('/');
    $route->setDefaults($original);
    $defaults = [
      ...$original,
      RouteObjectInterface::ROUTE_OBJECT => $route,
    ];
    self::assertEquals(
      [
        ...$enhanced,
        RouteObjectInterface::ROUTE_OBJECT => $route,
      ],
      $sut->enhance($defaults, Request::create('/'))
    );
  }

  public static function data(): array {
    return [
      'with _experience_builder' => [
        [
          '_experience_builder' => TRUE,
          'entity_type_id' => 'node',
        ],
        [
          '_controller' => ExperienceBuilderController::class,
          'entity_type_id' => 'node',
          'entity_type' => 'node',
          'entity' => NULL,
        ],
      ],
      'without _experience_builder' => [
        [
          'entity_type_id' => 'node',
        ],
        [
          'entity_type_id' => 'node',
        ],
      ],
    ];
  }

}
