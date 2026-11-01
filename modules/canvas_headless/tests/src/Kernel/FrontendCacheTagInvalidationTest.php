<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Kernel;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\canvas_headless\Controller\CacheInvalidationController;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests announcing invalidated cache tags through the real container.
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas_headless')]
final class FrontendCacheTagInvalidationTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'consumers',
    'simple_oauth',
    'custom_elements',
    'canvas_headless',
  ];

  /**
   * Tests that a deferred router rebuild is announced and can be read once.
   */
  public function testAnnouncesDeferredRouterRebuild(): void {
    $this->installConfig(['canvas_headless']);
    $this->config('canvas_headless.settings')
      ->set('cache_invalidation_urls', ['https://www.example.com/api/canvas/revalidate'])
      ->save();
    $history = [];
    $handler = HandlerStack::create(new MockHandler([new Response(204)]));
    $handler->push(Middleware::history($history));
    $this->container->set('http_client', new Client(['handler' => $handler]));

    Cache::invalidateTags(['node:1']);
    // Rebuilt by the router builder's destructor, which invalidates
    // http_response: announced only if the invalidator is destructed later.
    $this->container->get(RouteBuilderInterface::class)->setRebuildNeeded();
    $destructable_services = $this->container->getParameter('kernel.destructable_services');
    \assert(\is_array($destructable_services));
    $this->assertSame(FrontendCacheTagInvalidator::class, end($destructable_services));
    foreach ($destructable_services as $id) {
      if ($this->container->initialized($id)) {
        $service = $this->container->get($id);
        \assert($service instanceof DestructableInterface);
        $service->destruct();
      }
    }

    \assert(\is_array($history));
    $this->assertCount(1, $history);
    $this->assertSame('https://www.example.com/api/canvas/revalidate', (string) $history[0]['request']->getUri());
    $id = json_decode((string) $history[0]['request']->getBody(), flags: JSON_THROW_ON_ERROR)->id;

    $route = $this->container->get(RouteProviderInterface::class)->getRouteByName('canvas_headless.cache_invalidation');
    $this->assertSame('/canvas-headless/cache-invalidation', $route->getPath());
    $this->assertSame(['POST'], $route->getMethods());
    $this->assertSame(CacheInvalidationController::class, $route->getDefault('_controller'));
    $this->assertTrue($route->getOption('no_cache'));
    $controller = $this->container->get(CacheInvalidationController::class);
    $read = static fn (mixed $id) => $controller(Request::create('/canvas-headless/cache-invalidation', 'POST', content: (string) json_encode(['id' => $id])));
    $this->assertSame('{"tags":["http_response"]}', $read($id)->getContent());
    // Single use.
    $this->expectException(NotFoundHttpException::class);
    $read($id);
  }

}
