<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\Controller\CanvasContentController;
use Drupal\canvas_headless\Routing\CanvasContentRouteEnhancer;
use Drupal\canvas_headless\StackMiddleware\CanvasContentApiRequest;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the routed content API boundary.
 */
#[CoversClass(CanvasContentRouteEnhancer::class)]
#[Group('canvas_headless')]
final class CanvasContentRouteEnhancerTest extends UnitTestCase {

  /**
   * Tests a converted canonical content entity route.
   */
  public function testCanonicalContentEntityRoute(): void {
    $enhancer = new CanvasContentRouteEnhancer();
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $request = Request::create('/node/1');
    $request->attributes->set(
      CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE,
      '/node/1',
    );
    $route = new Route('/node/{node}');

    $result = $enhancer->enhance([
      RouteObjectInterface::ROUTE_NAME => 'entity.node.canonical',
      RouteObjectInterface::ROUTE_OBJECT => $route,
      'node' => $entity,
      '_controller' => 'original.controller',
    ], $request);

    self::assertSame(
      CanvasContentController::class . '::get',
      $result['_controller'],
    );
    self::assertSame(
      'node',
      $result[CanvasContentRouteEnhancer::PRIMARY_ENTITY_PARAMETER],
    );
    self::assertSame($entity, $result['node']);
    self::assertEquals(
      $route,
      $result[RouteObjectInterface::ROUTE_OBJECT],
    );
    self::assertNotSame(
      $route,
      $result[RouteObjectInterface::ROUTE_OBJECT],
    );
  }

  /**
   * Tests routes without a matching canonical content entity.
   */
  public function testRouteWithoutCanonicalContentEntity(): void {
    $enhancer = new CanvasContentRouteEnhancer();
    $request = Request::create('/admin');
    $request->attributes->set(
      CanvasContentApiRequest::REQUESTED_URI_ATTRIBUTE,
      '/admin',
    );
    $mismatched_entity = $this->createMock(ContentEntityInterface::class);
    $mismatched_entity->method('getEntityTypeId')->willReturn('user');

    foreach ([
      [
        RouteObjectInterface::ROUTE_NAME => 'system.admin',
        '_controller' => 'original.controller',
      ],
      [
        RouteObjectInterface::ROUTE_NAME => 'entity.node.canonical',
        'node' => $mismatched_entity,
        '_controller' => 'original.controller',
      ],
    ] as $defaults) {
      $result = $enhancer->enhance($defaults, $request);
      self::assertSame(
        CanvasContentController::class . '::get',
        $result['_controller'],
      );
      self::assertArrayNotHasKey(
        CanvasContentRouteEnhancer::PRIMARY_ENTITY_PARAMETER,
        $result,
      );
    }
  }

  /**
   * Tests that ordinary Drupal requests are unchanged.
   */
  public function testOrdinaryRequest(): void {
    $enhancer = new CanvasContentRouteEnhancer();
    $defaults = [
      RouteObjectInterface::ROUTE_NAME => 'system.admin',
      '_controller' => 'original.controller',
    ];

    self::assertSame(
      $defaults,
      $enhancer->enhance($defaults, Request::create('/admin')),
    );
  }

}
