<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Entity\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\canvas\Entity\Routing\CanvasHtmlRouteEnhancer;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Drupal\canvas\Controller\CanvasController;

/**
 * Tests Drupal\canvas\Entity\Routing\CanvasHtmlRouteEnhancer.
 */
#[CoversClass(CanvasHtmlRouteEnhancer::class)]
#[Group('canvas')]
final class RouteEnhancerTest extends UnitTestCase {

  /**
   * Tests enhance.
   *
   * @legacy-covers ::enhance
   * @legacy-covers ::applies
   */
  #[DataProvider('data')]
  public function testEnhance(array $original, array $enhanced): void {
    $sut = new CanvasHtmlRouteEnhancer();
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
      'with _canvas' => [
        [
          '_canvas' => TRUE,
          'entity_type_id' => 'node',
        ],
        [
          '_controller' => CanvasController::class,
          'entity_type_id' => 'node',
          'entity_type' => 'node',
          'entity' => NULL,
        ],
      ],
      'without _canvas' => [
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
