<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\CanvasContentHeadBuilder;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\metatag\MetatagManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests document-head normalization.
 */
#[CoversClass(CanvasContentHeadBuilder::class)]
#[Group('canvas_headless')]
final class CanvasContentHeadBuilderTest extends UnitTestCase {

  /**
   * Tests resolved Metatag elements override the entity-label fallback.
   */
  public function testBuildWithMetatag(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('label')->willReturn('Fallback title');
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn(['entity:1']);
    $entity->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('executeInRenderContext')
      ->willReturnCallback(
        static function ($context, callable $callback): mixed {
          $context->push(
            (new CacheableMetadata())
              ->setCacheTags(['metatag_render']),
          );
          return $callback();
        },
      );
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->expects(self::once())->method('alter');
    $metatag_defaults = $this->createMock(EntityTypeInterface::class);
    $metatag_defaults->method('getListCacheTags')
      ->willReturn(['config:metatag_defaults_list']);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('hasDefinition')
      ->with('metatag_defaults')
      ->willReturn(TRUE);
    $entity_type_manager->method('getDefinition')
      ->with('metatag_defaults')
      ->willReturn($metatag_defaults);
    $metatag_manager = $this->createMock(MetatagManagerInterface::class);
    $metatag_manager->expects(self::once())
      ->method('tagsFromEntityWithDefaults')
      ->with($entity)
      ->willReturn(['title' => 'Resolved title']);
    $metatag_manager->expects(self::once())
      ->method('generateRawElements')
      ->with(['title' => 'Resolved title'], $entity)
      ->willReturn([
        'title' => [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'title',
            'content' => 'Resolved title',
          ],
        ],
      ]);

    $result = (new CanvasContentHeadBuilder(
      $renderer,
      $module_handler,
      $entity_type_manager,
      $this->createMock(TitleResolverInterface::class),
      $metatag_manager,
    ))->build($entity);

    self::assertSame(['title' => 'Resolved title'], $result['head']);
    self::assertContains('entity:1', $result['cacheability']->getCacheTags());
    self::assertContains('metatag_render', $result['cacheability']->getCacheTags());
    self::assertContains(
      'config:metatag_defaults_list',
      $result['cacheability']->getCacheTags(),
    );
  }

  /**
   * Tests route title rendering and cacheability.
   */
  public function testBuildRoute(): void {
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->expects(self::once())
      ->method('executeInRenderContext')
      ->willReturnCallback(
        static function (RenderContext $context, callable $callback): mixed {
          $context->push(
            (new CacheableMetadata())->setCacheTags(['route_title']),
          );
          return $callback();
        },
      );
    $renderer->expects(self::once())
      ->method('render')
      ->willReturn(Markup::create('<em>Dynamic &amp; translated</em>'));
    $request = Request::create('/example');
    $route = new Route('/example');
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteObject')->willReturn($route);
    $title_resolver = $this->createMock(TitleResolverInterface::class);
    $title_resolver->expects(self::once())
      ->method('getTitle')
      ->with($request, $route)
      ->willReturn(['#markup' => 'Dynamic & translated']);

    $result = (new CanvasContentHeadBuilder(
      $renderer,
      $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $title_resolver,
    ))->buildFromRoute($request, $route_match);

    self::assertSame(
      ['title' => 'Dynamic & translated'],
      $result['head'],
    );
    self::assertContains(
      'route_title',
      $result['cacheability']->getCacheTags(),
    );
  }

  /**
   * Tests supported tags and Metatag's meta-name title convention.
   */
  public function testNormalizeSupportedHead(): void {
    $head = CanvasContentHeadBuilder::normalize([
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'title',
          'content' => 'Resolved title',
        ],
      ],
      [
        '#tag' => 'meta',
        '#attributes' => [
          'property' => 'og:title',
          'content' => 'Open Graph title',
        ],
      ],
      [
        '#tag' => 'meta',
        '#attributes' => [
          'http-equiv' => 'content-language',
          'content' => 'en',
        ],
      ],
      [
        '#tag' => 'meta',
        '#attributes' => [
          'charset' => 'utf-8',
        ],
      ],
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'description',
          'property' => 'og:description',
          'content' => 'Multiple identifiers',
          'data-source' => 'drupal',
        ],
      ],
      [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'canonical',
          'href' => 'https://example.com/article',
        ],
      ],
      [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'alternate',
          'href' => '/fr/article',
          'hreflang' => 'fr',
        ],
      ],
      [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'preload',
          'href' => '/app.js',
          'as' => 'script',
          'fetchpriority' => 'high',
        ],
      ],
      [
        '#tag' => 'script',
        '#attributes' => ['type' => 'application/ld+json'],
        '#value' => '{"@type":"Article","unsafe":"</script>"}',
      ],
    ], 'Fallback title');

    self::assertSame([
      'title' => 'Resolved title',
      'meta' => [[
        'property' => 'og:title',
        'content' => 'Open Graph title',
      ], [
        'http-equiv' => 'content-language',
        'content' => 'en',
      ], [
        'charset' => 'utf-8',
      ], [
        'name' => 'description',
        'property' => 'og:description',
        'content' => 'Multiple identifiers',
        'data-source' => 'drupal',
      ],
      ],
      'link' => [[
        'rel' => 'alternate',
        'href' => '/fr/article',
        'hreflang' => 'fr',
      ], [
        'rel' => 'preload',
        'href' => '/app.js',
        'as' => 'script',
        'fetchpriority' => 'high',
      ],
      ],
      'script' => [[
        'type' => 'application/ld+json',
        'textContent' => [
          '@type' => 'Article',
          'unsafe' => '</script>',
        ],
      ],
      ],
    ], $head);
  }

  /**
   * Tests executable data, canonical links, and stylesheet links are omitted.
   */
  public function testNormalizeRejectsUnsafeHead(): void {
    $head = CanvasContentHeadBuilder::normalize([
      [
        '#tag' => 'script',
        '#attributes' => ['src' => 'https://example.com/app.js'],
      ],
      [
        '#tag' => 'script',
        '#attributes' => ['type' => 'application/ld+json'],
        '#value' => '{invalid',
      ],
      [
        '#tag' => 'script',
        '#attributes' => ['type' => 'application/ld+json'],
        '#value' => '"scalar"',
      ],
      [
        '#tag' => 'style',
        '#value' => 'body { display: none }',
      ],
      [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'preload stylesheet',
          'href' => 'https://example.com/app.css',
        ],
      ],
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'description',
          'content' => 'Unsafe event attribute',
          'onclick' => 'alert(1)',
        ],
      ],
      [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'canonical',
          'href' => 'javascript:alert(1)',
          'onload' => 'alert(1)',
        ],
      ],
    ], 'Fallback title');

    self::assertSame([
      'title' => 'Fallback title',
      'meta' => [[
        'name' => 'description',
        'content' => 'Unsafe event attribute',
      ],
      ],
    ], $head);
  }

}
