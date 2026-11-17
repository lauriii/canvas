<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Fetch;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\Cache\NestedRenderCacheContext;
use Drupal\canvas_ssr\Fetch\FetchInterface;
use Drupal\canvas_ssr\Fetch\SameSiteFetch;
use Drupal\canvas_ssr\Renderer;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Route;

/**
 * Tests an island rendered from inside another island's fetch.
 *
 * A fetch to a path Drupal doesn't route gets Drupal's error page, built in
 * the subrequest, and that page can have islands on it. Each renders while
 * the outer island's invoke is still running. The runner can't take it, so
 * it falls back to CSR, and the outer island renders as if it weren't
 * there.
 *
 * @legacy-covers \Drupal\canvas_ssr\JsRunner\RunnerGuard
 * @legacy-covers \Drupal\canvas_ssr\Renderer
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class NestedRenderTest extends CanvasSsrKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'serialization',
    'jsonapi',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container
      ->register(NestingFetch::class, NestingFetch::class)
      ->addArgument(new Reference(SameSiteFetch::class));
    $container->setAlias(FetchInterface::class, NestingFetch::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NestingFetch::$render = NULL;
    NestingFetch::$rendered = [];
  }

  /**
   * Tests the outer island renders, and keeps what it fetched.
   */
  public function testIslandRenderedFromInsideFetch(): void {
    $this->setUpCurrentUser([], ['access content']);
    $article = Node::create([
      'type' => 'article',
      'title' => 'Fetched Article',
      'status' => 1,
    ]);
    $article->save();
    $node_tag = 'node:' . $article->id();

    $this->assertScenarios([
      // Without the guard, Deno's process was torn down, since the nested
      // invoke reached it while it waited on the fetch, and both islands
      // fell back.
      'an island that would invoke falls back, and the outer one renders' => function () use ($node_tag): void {
        $inner = $this->createComponent(
          'inner island',
          'import { h } from "preact"; export default function Inner(){ return h("p", null, "inner"); }',
        );
        $this->assertOuterRendersAround($inner, $node_tag);
      },

      // The nested render once cleared the outer one's fetch cacheability as
      // it started, so the outer island was cached without the node's tag.
      'an island that bails before the runner leaves the outer one its fetch cacheability' => function () use ($node_tag): void {
        $inner = $this->createComponent(
          'inner island without compiled js',
          'import { h } from "preact"; export default function Inner(){ return h("p", null, "inner"); }',
          compiled: '',
        );
        $this->assertOuterRendersAround($inner, $node_tag);
      },

      'the runner renders the next island as before' => function (): void {
        $next = $this->createComponent(
          'next island',
          'import { h } from "preact"; export default function Next(){ return h("p", null, "next"); }',
        );
        $output = $this->renderComponent($next);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('next', $this->extractSsrMarkup($output));
      },
    ]);
  }

  /**
   * Tests nothing is resolved for an island rendered from inside a fetch.
   *
   * The error page a fetch gets is built in a subrequest, for its route.
   * Page data resolved for an island on it would be that route's, and was
   * kept for the islands the page rendered next. And the island took its
   * component's place in the record of what islands fetched, so what the
   * outer island fetched after it was lost to the component's later
   * islands.
   */
  public function testIslandRenderedFromInsideFetchResolvesNothing(): void {
    $this->setUpCurrentUser([], ['access content']);
    $article = Node::create([
      'type' => 'article',
      'title' => 'Main Page Title',
      'status' => 1,
    ]);
    $article->save();
    $stack = $this->container->get(RequestStack::class);
    \assert($stack instanceof RequestStack);
    $session = $stack->getCurrentRequest()?->getSession();
    \assert($session instanceof SessionInterface);
    $route_provider = $this->container->get(RouteProviderInterface::class);
    \assert($route_provider instanceof RouteProviderInterface);
    $page = Request::create('/node/' . $article->id());
    $page->attributes->add([
      RouteObjectInterface::ROUTE_NAME => 'entity.node.canonical',
      RouteObjectInterface::ROUTE_OBJECT => $route_provider->getRouteByName('entity.node.canonical'),
      'node' => $article,
      '_raw_variables' => new InputBag(['node' => (string) $article->id()]),
    ]);
    $page->setSession($session);
    $stack->push($page);

    // On every page, the error page included, as in a header.
    $title = $this->createComponent(
      'title island',
      'import { h } from "preact"; export default function Title() { return h("h1", null, drupalSettings.canvasData.v0.pageTitle); }',
      data_dependencies: ['drupalSettings' => ['v0.pageTitle']],
    );
    // Keeps what it fetches after the fetch that renders an island of it.
    $keeper = $this->createComponent('keeper island', \sprintf(<<<'JS'
    import { h } from "preact";
    let kept;
    export default function Keeper(props) {
      if (kept === undefined) {
        throw (async () => {
          await fetch(%s);
          kept = (await fetch("/jsonapi/node/article").then((r) => r.json())).data[0].attributes.title;
        })();
      }
      return h("div", null, `kept ${kept}`);
    }
    JS, \json_encode(NestingFetch::PATH, JSON_THROW_ON_ERROR)));
    NestingFetch::$render = function () use ($title, $keeper, $stack, $session): array {
      $error_page = Request::create('/other');
      $error_page->attributes->add([
        RouteObjectInterface::ROUTE_NAME => 'canvas_ssr_test.other',
        RouteObjectInterface::ROUTE_OBJECT => new Route('/other', ['_title' => 'Page not found']),
      ]);
      $error_page->setSession($session);
      $stack->push($error_page);
      try {
        $html = $this->renderComponent($title) . $this->renderComponent($keeper);
      }
      finally {
        $stack->pop();
      }
      return [$html, new CacheableMetadata()];
    };

    self::assertSame('<div>kept Main Page Title</div>', $this->extractSsrMarkup($this->renderComponent($keeper)));

    $cacheability = new CacheableMetadata();
    $output = $this->renderComponent($title, cacheability: $cacheability);
    self::assertSame('<h1>Main Page Title</h1>', $this->extractSsrMarkup($output));
    self::assertContains('node:' . $article->id(), $cacheability->getCacheTags());

    $cacheability = new CacheableMetadata();
    $output = $this->renderComponent($keeper, ['again' => TRUE], cacheability: $cacheability);
    self::assertSame('<div>kept Main Page Title</div>', $this->extractSsrMarkup($output));
    self::assertContains('node:' . $article->id(), $cacheability->getCacheTags());
  }

  /**
   * Renders an island whose fetch renders another, and checks both.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $inner
   *   The component the fetch renders an island of.
   * @param string $node_tag
   *   The cache tag of the article the outer island fetches first.
   */
  private function assertOuterRendersAround(JavaScriptComponent $inner, string $node_tag): void {
    NestingFetch::$rendered = [];
    $inner_cacheability = NULL;
    NestingFetch::$render = function () use ($inner, &$inner_cacheability): array {
      $inner_cacheability = new CacheableMetadata();
      $html = $this->renderComponent($inner, cacheability: $inner_cacheability);

      return [$html, clone $inner_cacheability];
    };

    $cacheability = new CacheableMetadata();
    $output = $this->renderComponent($this->outerComponent(), cacheability: $cacheability);

    self::assertCount(1, NestingFetch::$rendered);
    self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, NestingFetch::$rendered[0]);
    self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
    self::assertStringContainsString('Fetched Article after nested', $this->extractSsrMarkup($output));
    self::assertContains($node_tag, $cacheability->getCacheTags());
    self::assertContains(NestingFetch::TAG, $cacheability->getCacheTags());

    // The inner island falls back every time it's rendered from inside
    // another, so that's cached as a render, apart from what a page showing
    // it caches. So the outer island, which carries the cacheability of
    // what it fetched, is cached as a render too.
    \assert($inner_cacheability instanceof CacheableMetadata);
    foreach ([$inner_cacheability, $cacheability] as $either) {
      self::assertContains(NestedRenderCacheContext::ID, $either->getCacheContexts());
      self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $either->getCacheTags());
      self::assertSame(Cache::PERMANENT, $either->getCacheMaxAge());
    }
    $contexts = $this->container->get(CacheContextsManager::class);
    \assert($contexts instanceof CacheContextsManager);
    self::assertSame(['[' . NestedRenderCacheContext::ID . ']=0'], $contexts->convertTokensToKeys([NestedRenderCacheContext::ID])->getKeys());
  }

  /**
   * Builds a component that fetches an article, then the nesting path.
   */
  private function outerComponent(): JavaScriptComponent {
    $source = \sprintf(<<<'JS'
    import { h } from "preact";
    import useSWR from "swr";
    export default function Outer() {
      const { data } = useSWR(
        "/jsonapi/node/article",
        async (url) => {
          const list = await fetch(url).then((r) => r.json());
          const nested = await fetch(%s).then((r) => r.text());
          return { title: list.data[0].attributes.title, nested: nested !== "" };
        },
        { fallbackData: "unused" }
      );
      if (typeof data !== "object") {
        return h("div", null, "no-data");
      }
      return h("div", null, `${data.title} after ${data.nested ? "nested" : "nothing"}`);
    }
    JS, \json_encode(NestingFetch::PATH, JSON_THROW_ON_ERROR));

    return $this->createComponent(
      'outer island',
      $source,
      data_dependencies: ['drupalSettings' => ['v0.jsonapiSettings']],
    );
  }

}
