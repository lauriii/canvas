<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\Controller\CanvasContentController;
use Drupal\canvas_headless\Routing\CanvasContentRoute;
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
#[CoversClass(CanvasContentRoute::class)]
#[Group('canvas_headless')]
final class CanvasContentRouteEnhancerTest extends UnitTestCase {

  /**
   * Tests content entity display routes and their selected preview view modes.
   */
  public function testContentEntityRoutes(): void {
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
    $content_route = CanvasContentRoute::resolve('entity.node.canonical', $route, $result);
    self::assertNotNull($content_route);
    self::assertSame($entity, $content_route->entity);
    self::assertSame('full', $content_route->viewMode);
    self::assertFalse($content_route->isPreview);
    self::assertSame($entity, $result['node']);
    self::assertEquals(
      $route,
      $result[RouteObjectInterface::ROUTE_OBJECT],
    );
    self::assertNotSame(
      $route,
      $result[RouteObjectInterface::ROUTE_OBJECT],
    );
    foreach (['node', 'media', 'taxonomy_term', 'custom_content'] as $entity_type) {
      $selected = $this->createMock(ContentEntityInterface::class);
      $selected->method('getEntityTypeId')->willReturn($entity_type);
      foreach (['preview' => $entity_type . '_preview', 'revision' => $entity_type . '_revision', 'latest_version' => $entity_type] as $operation => $parameter) {
        $route_name = "entity.$entity_type.$operation";
        $parameters = [$parameter => $selected, 'view_mode_id' => 'teaser'];
        $content_route = CanvasContentRoute::resolve($route_name, $route, $parameters);
        self::assertNotNull($content_route);
        self::assertSame($selected, $content_route->entity);
        self::assertTrue($content_route->isPreview);
        self::assertSame('teaser', $content_route->viewMode);
      }
      // A renamed revision parameter must win over the current entity.
      $revision_route = new Route('/revision/{selected}', options: ['parameters' => ['selected' => ['type' => 'entity_revision:' . $entity_type]]]);
      $content_route = CanvasContentRoute::resolve("entity.$entity_type.revision", $revision_route, [$entity_type => $entity, 'selected' => $selected]);
      self::assertNotNull($content_route);
      self::assertSame($selected, $content_route->entity);
    }
    $preview_route = new Route('/preview/{node_preview}', ['_entity_view' => 'node.teaser']);
    $content_route = CanvasContentRoute::resolve('entity.node.preview', $preview_route, ['node_preview' => $entity]);
    self::assertNotNull($content_route);
    self::assertSame($entity, $content_route->entity);
    self::assertSame('teaser', $content_route->viewMode);
    self::assertTrue($content_route->isPreview);
    self::assertNull(CanvasContentRoute::resolve('entity.node.preview', $preview_route, []));
    self::assertNull(CanvasContentRoute::resolve('custom.preview', $preview_route, ['node_preview' => $entity]));
    $preview_route->setDefault('_entity_form', 'node.edit');
    self::assertNull(CanvasContentRoute::resolve('entity.node.preview', $preview_route, ['node_preview' => $entity]));

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
        RouteObjectInterface::ROUTE_NAME => 'entity.user.revision_revert_form',
        'user_revision' => $mismatched_entity,
      ],
      [
        RouteObjectInterface::ROUTE_NAME => 'entity.user.revision_delete_form',
        'user_revision' => $mismatched_entity,
      ],
      [
        RouteObjectInterface::ROUTE_NAME => 'entity.user.revision',
        'user' => $mismatched_entity,
      ],
      [
        RouteObjectInterface::ROUTE_NAME => 'entity.node.preview',
        'node_preview' => $mismatched_entity,
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
      self::assertNull(CanvasContentRoute::resolve($defaults[RouteObjectInterface::ROUTE_NAME], NULL, $defaults));
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
