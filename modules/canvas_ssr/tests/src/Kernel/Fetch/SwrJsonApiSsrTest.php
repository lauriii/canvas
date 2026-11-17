<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Fetch;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which SWR fetches SSR answers, and which fall back to CSR.
 *
 * @legacy-covers \Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider
 * @legacy-covers \Drupal\canvas_ssr\DrupalSettings\Provider\CanvasSiteDataProvider
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class SwrJsonApiSsrTest extends CanvasSsrKernelTestBase {

  use UserCreationTrait;

  /**
   * What decodeDevalue() gives for a reference to an object being decoded.
   */
  private const string CYCLE = '(cycle)';

  /**
   * Collects what canvas_ssr logs.
   */
  private CollectingLogger $logger;

  /**
   * {@inheritdoc}
   *
   * The failing scenarios need JSON:API installed too, to show they fail for
   * the right reason.
   */
  protected static $modules = [
    'node',
    'serialization',
    'jsonapi',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->logger = new CollectingLogger();
    $factory = $this->container->get(LoggerChannelFactoryInterface::class);
    \assert($factory instanceof LoggerChannelFactoryInterface);
    $factory->addLogger($this->logger);
  }

  /**
   * Tests which SWR fetches SSR answers, and which fall back to CSR.
   *
   * The scenarios share one site install.
   */
  public function testSwrFetches(): void {
    $this->assertScenarios([
      ...$this->answeredScenarios(),
      ...$this->csrFallbackScenarios(),
    ]);
  }

  /**
   * Tests that a render a failed island left suspended stays that island's.
   *
   * The first island fails while one of its components is suspended on a
   * promise its module keeps. The second island, of the same component,
   * settles that promise, so the first island's component renders again
   * while the second renders, and what it fetches is taken for the
   * second's.
   *
   * It fails for now, on every runner, so it's skipped unless
   * CANVAS_SSR_TEST_KNOWN_GAPS is set. ADR 11 lists the gap.
   */
  public function testSuspendedRenderOfFailedIsland(): void {
    if (\getenv('CANVAS_SSR_TEST_KNOWN_GAPS') === FALSE) {
      self::markTestSkipped('A render a failed island left suspended renders again during a later island that settles what it waits on. See ADR 11.');
    }
    $component = $this->createComponent('swr leftover consumer', <<<'JS'
      'use client';
      import { h } from "preact";
      import useSWR from "swr";
      let islands = 0;
      let wake;
      let woken = false;
      const waiting = new Promise((resolve) => {
        wake = resolve;
      });
      function Suspended() {
        if (!woken) {
          throw waiting;
        }
        return h("i", null, useSWR("leftover", async () => "left").data);
      }
      // Its own component, so the suspension is caught below Root, and
      // Failing still renders.
      function Holding() {
        return h(Suspended);
      }
      function Failing() {
        throw new Error("failed");
      }
      function Waking() {
        if (!woken) {
          woken = true;
          wake();
        }
        return h("b", null, useSWR("own", async () => "own").data);
      }
      export default function Root() {
        return ++islands === 1 ? h("div", null, h(Holding), h(Failing)) : h(Waking);
      }
      JS);

    self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $this->renderComponent($component, ['n' => 1]));
    $output = $this->renderComponent($component, ['n' => 2]);
    self::assertSame('<b>own</b>', $this->extractSsrMarkup($output));
    self::assertSame([['own', 'own']], self::swrFallback($output));
  }

  /**
   * Scenarios for the SWR fetches SSR can answer.
   *
   * They read the whole article collection, so they share one article.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function answeredScenarios(): array {
    $this->setUpCurrentUser([], ['access content']);
    Node::create([
      'type' => 'article',
      'title' => 'Newest Article',
      'status' => 1,
      'created' => \strtotime('now'),
    ])->save();

    return [
      // A null fetcher makes no request, so even an external key is fine.
      // Here fallbackData is the final answer.
      'a null fetcher renders its fallbackData without fetching' => function (): void {
        self::assertSame('<div>swr-fallback</div>', $this->ssrMarkup(
          'swr null fetcher consumer',
          <<<'JS'
          import { h } from "preact";
          import useSWR from "swr";
          export default function Root() {
            const { data } = useSWR("https://example.com/api/node/article", null, {
              fallbackData: "swr-fallback",
            });
            return h("div", null, data ?? "no-data");
          }
          JS,
        ));
      },

      'a JsonApiClient collection renders its data' => function (): void {
        self::assertSame('<div>Newest Article</div>', $this->ssrMarkup(
          'swr success consumer',
          <<<'JS'
          import { h } from "preact";
          import { JsonApiClient } from "drupal-canvas";
          import useSWR from "swr";
          const client = new JsonApiClient();
          export default function Root() {
            const { data } = useSWR(["node--article", {}], ([type, options]) => client.getCollection(type, options));
            if (!Array.isArray(data) || data.length === 0) {
              return h("div", null, "no-data");
            }
            return h("div", null, data[0].title);
          }
          JS,
          ['drupalSettings' => ['v0.baseUrl', 'v0.jsonapiSettings']],
        ));
      },

      // The client awaits before it fetches, so no component is on the
      // stack of its fetch. It counts for the components that import the
      // client, not for one that shares only a button with them.
      'a JsonApiClient fetch counts for the components that import the client' => function (): void {
        $this->createComponent('client button', <<<'JS'
          import { h } from "preact";
          import { cn } from "drupal-canvas";
          export default function Button() {
            return h("button", { class: cn("a") }, "ok");
          }
          JS, machine_name: 'client_button');
        $keeper = $this->createComponent('client keeper', <<<'JS'
          import { h } from "preact";
          import { JsonApiClient } from "drupal-canvas";
          import Button from "@/components/client_button";
          const client = new JsonApiClient();
          let kept;
          export default function Root(props) {
            if (kept === undefined) {
              throw client.getCollection("node--article", {}).then((data) => { kept = data[0].title; });
            }
            return h("div", null, `${kept} ${props.n}`, h(Button, null));
          }
          JS, data_dependencies: ['drupalSettings' => ['v0.baseUrl', 'v0.jsonapiSettings']], imports: ['client_button']);
        foreach ([1, 2] as $n) {
          $cacheability = new CacheableMetadata();
          $output = $this->renderComponent($keeper, ['n' => $n], cacheability: $cacheability);
          self::assertSame("<div>Newest Article $n<button class=\"a\">ok</button></div>", $this->extractSsrMarkup($output));
          self::assertContains('node_list', $cacheability->getCacheTags(), "Island $n of the component that kept what the client fetched.");
        }

        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->createComponent('client card', <<<'JS'
          import { h } from "preact";
          import Button from "@/components/client_button";
          export default function Root() {
            return h("div", null, h(Button, null));
          }
          JS, imports: ['client_button']), cacheability: $cacheability);
        self::assertSame('<div><button class="a">ok</button></div>', $this->extractSsrMarkup($output));
        self::assertNotContains('node_list', $cacheability->getCacheTags());
      },

      // Canvas's island script gives the hook its client in the browser.
      // The framework renderer does here.
      'a useJsonApiClient() collection renders its data' => function (): void {
        $hook = $this->createComponent(
          'hook client consumer',
          <<<'JS'
          import { h } from "preact";
          import { useJsonApiClient } from "drupal-canvas/react";
          import useSWR from "swr";
          export default function Root() {
            const client = useJsonApiClient();
            const { data } = useSWR(["node--article", {}], ([type, options]) => client.getCollection(type, options));
            return h("div", null, `${data[0].title} ${client.resourceVersion}`);
          }
          JS,
          data_dependencies: ['drupalSettings' => ['v0.baseUrl', 'v0.jsonapiSettings']],
        );
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($hook, cacheability: $cacheability);
        self::assertSame('<div>Newest Article null</div>', $this->extractSsrMarkup($output));
        self::assertContains('node_list', $cacheability->getCacheTags());
      },

      // In a preview, the browser's client asks for working copies, so
      // the server's does too.
      'a preview island gets a client that reads working copies' => function (): void {
        $output = $this->renderComponent(
          $this->createComponent(
            'preview hook client consumer',
            <<<'JS'
            import { h } from "preact";
            import { useJsonApiClient } from "drupal-canvas/react";
            export default function Root() {
              return h("div", null, useJsonApiClient().resourceVersion);
            }
            JS,
            data_dependencies: ['drupalSettings' => ['v0.baseUrl', 'v0.jsonapiSettings']],
          ),
          preview: TRUE,
        );
        self::assertSame('<div>rel:working-copy</div>', $this->extractSsrMarkup($output));
      },

      // The client awaits before it fetches, and the component neither
      // made the client nor imports what did. It still counts as one that
      // may keep what the client fetched.
      'a useJsonApiClient() fetch counts for the components that use the hook' => function (): void {
        $keeper = $this->createComponent('hook client keeper', <<<'JS'
          import { h } from "preact";
          import { useJsonApiClient } from "drupal-canvas/react";
          let kept;
          export default function Root(props) {
            const client = useJsonApiClient();
            if (kept === undefined) {
              throw client.getCollection("node--article", {}).then((data) => { kept = data[0].title; });
            }
            return h("div", null, `${kept} ${props.n}`);
          }
          JS, data_dependencies: ['drupalSettings' => ['v0.baseUrl', 'v0.jsonapiSettings']]);
        foreach ([1, 2] as $n) {
          $cacheability = new CacheableMetadata();
          $output = $this->renderComponent($keeper, ['n' => $n], cacheability: $cacheability);
          self::assertSame("<div>Newest Article $n</div>", $this->extractSsrMarkup($output));
          self::assertContains('node_list', $cacheability->getCacheTags(), "Island $n of the component that kept what the hook's client fetched.");
        }
      },

      // Absolute URLs on the site's own base URL are still same-site.
      'a client built on the site base URL is still same-site' => function (): void {
        self::assertSame('<div>Newest Article</div>', $this->ssrMarkup(
          'swr same-site absolute url consumer',
          <<<'JS'
          import { h } from "preact";
          import { JsonApiClient, getSiteData } from "drupal-canvas";
          import useSWR from "swr";
          const client = new JsonApiClient(getSiteData().baseUrl);
          export default function Root() {
            const { data } = useSWR(
              ["node--article", {}],
              ([type, options]) => client.getCollection(type, options),
            );
            if (!Array.isArray(data) || data.length === 0) {
              return h("div", null, "no-data");
            }
            return h("div", null, data[0].title);
          }
          JS,
          ['drupalSettings' => ['v0.baseUrl', 'v0.branding', 'v0.jsonapiSettings']],
        ));
      },

      // The browser's first render reads the same data from the island, so
      // it finds the DOM it expects. A static island ships no code to read
      // it, and another island's fallback is its own.
      'a hydrated island carries what useSWR() fetched' => function (): void {
        $source = <<<'JS'
          import { h } from "preact";
          import { JsonApiClient } from "drupal-canvas";
          import useSWR from "swr";
          const client = new JsonApiClient();
          export default function Root() {
            const { data } = useSWR(["node--article", {}], ([type, options]) => client.getCollection(type, options));
            return h("div", null, data[0].title);
          }
          JS;
        $data_dependencies = ['drupalSettings' => ['v0.baseUrl', 'v0.jsonapiSettings']];

        $output = $this->renderComponent($this->createComponent('swr hydrated consumer', "'use client';\n" . $source, data_dependencies: $data_dependencies));
        self::assertSame('<div>Newest Article</div>', $this->extractSsrMarkup($output));
        $fallback = self::swrFallback($output);
        self::assertNotNull($fallback);
        self::assertCount(1, $fallback);
        self::assertSame(['node--article', []], $fallback[0][0]);
        self::assertSame('Newest Article', $fallback[0][1][0]['title']);

        $output = $this->renderComponent($this->createComponent('swr other key consumer', <<<'JS'
          'use client';
          import { h } from "preact";
          import useSWR from "swr";
          export default function Root() {
            const { data } = useSWR("other", async () => ({ n: 1, missing: undefined }));
            return h("div", null, data.n);
          }
          JS));
        self::assertSame([['other', ['n' => 1, 'missing' => NULL]]], self::swrFallback($output));

        // Rendered right after one that fetched, it carries nothing.
        $output = $this->renderComponent($this->createComponent('swr hydrated non-consumer', <<<'JS'
          'use client';
          import { h } from "preact";
          export default function Root() {
            return h("div", null, "none");
          }
          JS));
        self::assertSame('<div>none</div>', $this->extractSsrMarkup($output));
        self::assertNull(self::swrFallback($output));

        $output = $this->renderComponent($this->createComponent('swr static consumer', $source, data_dependencies: $data_dependencies));
        self::assertSame('<div>Newest Article</div>', $this->extractSsrMarkup($output));
        self::assertNull(self::swrFallback($output));
      },

      // SWR serializes each of these keys as "", and fetches none of them,
      // so neither does SSR, and the island carries nothing for them.
      'keys SWR does not fetch' => function (): void {
        $output = $this->renderComponent($this->createComponent('swr empty key hydrated consumer', <<<'JS'
          'use client';
          import { h } from "preact";
          import useSWR from "swr";
          export default function Root() {
            const fetcher = () => { throw new Error("fetched"); };
            const results = ["", [], 0].map((key) => useSWR(key, fetcher, { fallbackData: "none" }).data);
            return h("div", null, results.join(","));
          }
          JS));
        self::assertSame('<div>none,none,none</div>', $this->extractSsrMarkup($output));
        self::assertNull(self::swrFallback($output));
      },

      // SWR in the browser takes these keys as one, so the server fetches
      // once for them, and carries one.
      'keys SWR takes as one are fetched once' => function (): void {
        $output = $this->renderComponent($this->createComponent('swr same key hydrated consumer', <<<'JS'
          'use client';
          import { h } from "preact";
          import useSWR from "swr";
          let fetches = 0;
          export default function Root() {
            const fetcher = async () => ++fetches;
            const results = [
              ["articles", { category: "news", limit: 5 }],
              ["articles", { limit: 5, category: "news", page: undefined }],
            ].map((key) => useSWR(key, fetcher).data);
            return h("div", null, results.join(","));
          }
          JS));
        self::assertSame('<div>1,1</div>', $this->extractSsrMarkup($output));
        self::assertSame([[['articles', ['category' => 'news', 'limit' => 5]], 1]], self::swrFallback($output));
      },

      // JSON would give the browser a string where the server had a Date, and
      // couldn't carry objects that refer to each other at all.
      'a hydrated island carries a Date and a reference cycle' => function (): void {
        $output = $this->renderComponent($this->createComponent('swr date hydrated consumer', <<<'JS'
          'use client';
          import { h } from "preact";
          import useSWR from "swr";
          export default function Root() {
            const { data } = useSWR("when", async () => {
              const article = { changed: new Date(0) };
              article.author = { articles: [article] };
              return article;
            });
            return h("div", null, data.changed.getTime());
          }
          JS));
        self::assertSame('<div>0</div>', $this->extractSsrMarkup($output));
        $article = [
          'changed' => ['Date', '1970-01-01T00:00:00.000Z'],
          'author' => ['articles' => [self::CYCLE]],
        ];
        self::assertSame([['when', $article]], self::swrFallback($output));
      },

      // Keep this last: it adds a second article, which would change the
      // scenarios above.
      //
      // This also needs TextEncoder and TextDecoder. The JSON:API client
      // bundles @smithy/util-utf8, which creates them when it loads, and
      // getCollection() hashes the query string with them. They aren't part
      // of the JS language, so the runner must supply them before any
      // module evaluates.
      'a DrupalJsonApiParams query string reaches the server' => function (): void {
        Node::create([
          'type' => 'article',
          'title' => 'Older Article',
          'status' => 1,
          'created' => \strtotime('-1 day'),
        ])->save();

        // Newest first shows the sort in the query string was applied.
        self::assertSame('<div>Newest Article, Older Article:function</div>', $this->ssrMarkup(
          'swr drupal-jsonapi-params consumer',
          <<<'JS'
          import { h } from "preact";
          import { JsonApiClient } from "drupal-canvas";
          import { DrupalJsonApiParams } from "drupal-jsonapi-params";
          import useSWR from "swr";
          const client = new JsonApiClient();
          export default function Root() {
            const { data } = useSWR(
              [
                "node--article",
                {
                  queryString: new DrupalJsonApiParams()
                    .addSort("created", "DESC")
                    .getQueryString(),
                },
              ],
              ([type, options]) => client.getCollection(type, options)
            );
            if (!Array.isArray(data) || data.length === 0) {
              return h("div", null, "no-data");
            }
            return h("div", null, `${data.map((a) => a.title).join(", ")}:${typeof globalThis.TextEncoder}`);
          }
          JS,
          ['drupalSettings' => ['v0.baseUrl', 'v0.jsonapiSettings']],
        ));
      },
    ];
  }

  /**
   * Scenarios for the SWR fetches that fall back to CSR.
   *
   * If a fetch fails or is refused, the markup would be missing data, so the
   * island falls back to CSR instead.
   *
   * @see docs/adr/0007-never-render-without-declared-data.md
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function csrFallbackScenarios(): array {
    return [
      // If the apiPrefix were ignored, the fetch would go to /jsonapi and
      // succeed.
      'a client pointed at a path that does not exist' => function (): void {
        $this->assertFallsBackToCsr(
          'swr custom apiPrefix consumer',
          <<<'JS'
          import { h } from "preact";
          import { JsonApiClient, getSiteData } from "drupal-canvas";
          import useSWR from "swr";
          const client = new JsonApiClient(getSiteData().baseUrl, { apiPrefix: "custom-api" });
          export default function Root() {
            const { data } = useSWR(
              ["node--article", {}],
              ([type, options]) => client.getCollection(type, options),
            );
            return h("div", null, String(data));
          }
          JS,
          ['drupalSettings' => ['v0.baseUrl', 'v0.branding', 'v0.jsonapiSettings']],
        );
      },

      // SWR identifies a Map by its identity, which is new each time the
      // component renders again, and isn't the same in the browser.
      'a key SWR identifies by its identity' => function (): void {
        $this->assertFallsBackToCsr(
          'swr map key consumer',
          <<<'JS'
          import { h } from "preact";
          import useSWR from "swr";
          export default function Root() {
            const { data } = useSWR(["articles", new Map()], async () => 1);
            return h("div", null, data);
          }
          JS,
        );
      },

      // fallbackData is a placeholder for the browser, not a final answer.
      'a failed fetch, even where fallbackData was supplied' => function (): void {
        $this->assertFallsBackToCsr(
          'swr failure fallback consumer',
          <<<'JS'
          import { h } from "preact";
          import { JsonApiClient } from "drupal-canvas";
          import useSWR from "swr";
          const client = new JsonApiClient(undefined, { apiPrefix: "__invalid_prefix__" });
          export default function Root() {
            const { data } = useSWR(
              ["node--article", {}],
              ([type, options]) => client.getCollection(type, options),
              { fallbackData: "client-side-fallback" }
            );
            return h("div", null, String(data));
          }
          JS,
          ['drupalSettings' => ['v0.baseUrl', 'v0.jsonapiSettings']],
        );
      },

      // The browser would get a plain object where the server had an
      // instance, so the first render there could differ. Without "use
      // client", nothing reads it in the browser, so it renders on the
      // server.
      'a hydrated island whose fetched data devalue cannot encode' => function (): void {
        $source = <<<'JS'
          import { h } from "preact";
          import useSWR from "swr";
          class Thing {
            n = 1;
          }
          export default function Root() {
            const { data } = useSWR("thing", async () => new Thing());
            return h("div", null, data.n);
          }
          JS;
        $warnings = \count($this->logger->at((string) RfcLogLevel::WARNING));
        $component = $this->createComponent('swr class hydrated consumer', "'use client';\n" . $source);
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $this->renderComponent($component));
        $logged = \array_slice($this->logger->at((string) RfcLogLevel::WARNING), $warnings);
        self::assertCount(1, $logged);
        self::assertStringContainsString("the {$component->id()} component", $logged[0]);
        self::assertStringContainsString('Cannot stringify arbitrary non-POJOs at .swr[0][1].', $logged[0]);
        self::assertSame('<div>1</div>', $this->ssrMarkup('swr class static consumer', $source));
      },

      // The page and the render cache would carry all of it with the island.
      // It's measured as escaped for the island's attribute: these quotes
      // are 60,000 bytes encoded, but over 200,000 in the page.
      'a hydrated island whose fetched data is too large to carry' => function (): void {
        $source = <<<'JS'
          import { h } from "preact";
          import useSWR from "swr";
          export default function Root() {
            const { data } = useSWR("big", async () => '"'.repeat(30000));
            return h("div", null, data.length);
          }
          JS;
        $warnings = \count($this->logger->at((string) RfcLogLevel::WARNING));
        $component = $this->createComponent('swr big hydrated consumer', "'use client';\n" . $source);
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $this->renderComponent($component));
        $logged = \array_slice($this->logger->at((string) RfcLogLevel::WARNING), $warnings);
        self::assertCount(1, $logged);
        self::assertStringContainsString("the {$component->id()} component", $logged[0]);
        self::assertStringContainsString('more than the 131072', $logged[0]);
        self::assertSame('<div>30000</div>', $this->ssrMarkup('swr big static consumer', $source));
      },

      // The same-site fetch refuses another host without making a request.
      'a fetch of an external absolute URL' => function (): void {
        $this->assertFallsBackToCsr(
          'swr external url consumer',
          <<<'JS'
          import { h } from "preact";
          import useSWR from "swr";
          export default function Root() {
            const { data } = useSWR(
              "https://example.invalid/api/v2/pokemon/ditto",
              (url) => fetch(url).then((response) => response.json()),
            );
            return h("div", null, String(data));
          }
          JS,
        );
      },
    ];
  }

  /**
   * Renders a component and returns its SSR markup, asserting SSR happened.
   *
   * @param string $label
   *   The component's label.
   * @param string $js
   *   The component source, used as both authored and compiled.
   * @param array<string, array<int, string>> $data_dependencies
   *   Declared data dependencies.
   *
   * @return string
   *   The markup SSR produced.
   */
  private function ssrMarkup(string $label, string $js, array $data_dependencies = []): string {
    $output = $this->renderComponent($this->createComponent($label, $js, data_dependencies: $data_dependencies));
    self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);

    return $this->extractSsrMarkup($output);
  }

  /**
   * Reads the SWR part of the hydration data an island carries.
   *
   * @param string $output
   *   The rendered island.
   *
   * @return array<int, array{mixed, mixed}>|null
   *   Each key with its value, or NULL if the island carries none.
   */
  private static function swrFallback(string $output): ?array {
    if (\preg_match('/<canvas-island\b[^>]*\shydration-data="([^"]*)"/', $output, $match) !== 1) {
      return NULL;
    }
    $data = self::decodeDevalue(\html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
    self::assertIsArray($data);
    self::assertSame(['swr'], \array_keys($data));
    return $data['swr'];
  }

  /**
   * Decodes what devalue's stringify() encoded, as far as these tests need.
   *
   * Objects and arrays become arrays, undefined NULL, and any other type a
   * list of its name and what devalue encoded it as, like ['Date', '…'].
   * A reference back to an object being decoded is self::CYCLE.
   *
   * @param string $encoded
   *   What stringify() returned.
   *
   * @return mixed
   *   The value.
   */
  private static function decodeDevalue(string $encoded): mixed {
    $values = \json_decode($encoded, flags: JSON_THROW_ON_ERROR);
    $decode = static function (int $index, array $decoding) use (&$decode, $values): mixed {
      // Negative indexes stand for values with no entry of their own.
      if ($index < 0) {
        self::assertSame(-1, $index, 'Only undefined is expected.');
        return NULL;
      }
      if (isset($decoding[$index])) {
        return self::CYCLE;
      }
      $decoding[$index] = TRUE;
      $value = $values[$index];
      if ($value instanceof \stdClass) {
        return \array_map(static fn (int $item): mixed => $decode($item, $decoding), (array) $value);
      }
      if (\is_array($value)) {
        // An array of indexes, or a type's name and its encoding.
        if (isset($value[0]) && \is_string($value[0])) {
          return $value;
        }
        return \array_map(static fn (int $item): mixed => $decode($item, $decoding), $value);
      }
      return $value;
    };
    return \is_int($values) ? $decode($values, []) : $decode(0, []);
  }

  /**
   * Asserts a component's island falls back to CSR.
   *
   * @param string $label
   *   The component's label.
   * @param string $js
   *   The component source, used as both authored and compiled.
   * @param array<string, array<int, string>> $data_dependencies
   *   Declared data dependencies.
   */
  private function assertFallsBackToCsr(string $label, string $js, array $data_dependencies = []): void {
    self::assertStringContainsString(
      self::CLIENT_ONLY_ATTRIBUTE,
      $this->renderComponent($this->createComponent($label, $js, data_dependencies: $data_dependencies)),
    );
  }

}
