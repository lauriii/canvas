<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Fetch;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\Fetch\FetchInterface;
use Drupal\canvas_ssr\Fetch\SameSiteFetch;
use Drupal\canvas_ssr\Renderer;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\canvas_ssr\Kernel\GlobalAssetLibraryTrait;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests how what a fetch gets decides how long the island is cached.
 *
 * A response whose cacheability isn't known can't say how long markup made
 * from it holds, so the island falls back to CSR, cached as a render is. A
 * server error is a transient error, cached briefly, as is a fetch that
 * fails. A refused fetch fails the same way every time, so it isn't.
 *
 * @legacy-covers \Drupal\canvas_ssr\Renderer
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class FetchCacheabilityTest extends CanvasSsrKernelTestBase {

  use GlobalAssetLibraryTrait;

  /**
   * Collects what's logged.
   */
  private CollectingLogger $logger;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container
      ->register(CannedFetch::class, CannedFetch::class)
      ->addArgument(new Reference(SameSiteFetch::class));
    $container->setAlias(FetchInterface::class, CannedFetch::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    // Saving the global asset library looks up its files in file_managed.
    $this->installEntitySchema('file');
    $this->logger = new CollectingLogger();
    $factory = $this->container->get(LoggerChannelFactoryInterface::class);
    \assert($factory instanceof LoggerChannelFactoryInterface);
    $factory->addLogger($this->logger);
    CannedFetch::$responses = [
      '/jsonapi/unknown' => CannedFetch::response(200, NULL),
      '/jsonapi/server-error' => CannedFetch::response(500, 0),
      '/jsonapi/server-error-unknown' => CannedFetch::response(503, NULL),
      '/jsonapi/fine' => CannedFetch::response(200, Cache::PERMANENT),
      '/jsonapi/binary' => CannedFetch::response(200, Cache::PERMANENT)->withBody(Utils::streamFor("\xff\xfe")),
      '/jsonapi/empty' => CannedFetch::response(200, Cache::PERMANENT)->withBody(Utils::streamFor('')),
    ];
    CannedFetch::$lastRequest = NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->cleanupGlobalAssetLibraryModules();
    parent::tearDown();
  }

  /**
   * Tests each kind of answer gives the island the cacheability it should.
   */
  public function testFetchDecidesHowLongTheIslandIsCached(): void {
    $this->assertScenarios([
      'a response with cacheability renders with it' => function (): void {
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->statusComponent('fine reader', '/jsonapi/fine'), cacheability: $cacheability);

        self::assertSame('<div>200 true</div>', $this->extractSsrMarkup($output));
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertContains('canned_fetch', $cacheability->getCacheTags());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },

      // A component's module evaluates once in a realm, so a later island
      // reads what the module kept without fetching it.
      "a later island of a component takes on what the component's module kept" => function (): void {
        $kinds = [
          'fetched as it evaluated' => <<<'JS'
            import { h } from "preact";
            const status = await fetch("/jsonapi/fine").then((response) => response.status);
            export default function Root(props) {
              return h("div", null, `${status} ${props.n}`);
            }
            JS,
          'kept from a render' => <<<'JS'
            import { h } from "preact";
            let status;
            export default function Root(props) {
              if (status === undefined) {
                throw fetch("/jsonapi/fine").then((response) => { status = response.status; });
              }
              return h("div", null, `${status} ${props.n}`);
            }
            JS,
        ];
        foreach ($kinds as $kind => $source) {
          $component = $this->createComponent("keeper $kind", $source);
          foreach ([1, 2] as $n) {
            $cacheability = new CacheableMetadata();
            $output = $this->renderComponent($component, ['n' => $n], cacheability: $cacheability);

            self::assertSame("<div>200 $n</div>", $this->extractSsrMarkup($output), "Island $n of a component whose module $kind.");
            self::assertContains('canned_fetch', $cacheability->getCacheTags(), "Island $n of a component whose module $kind.");
          }
        }

        // So does an island of a component that imports it.
        $keeper = $this->createComponent('shared keeper', <<<'JS'
          import { h } from "preact";
          export const status = await fetch("/jsonapi/fine").then((response) => response.status);
          export default function Root() {
            return h("div", null, String(status));
          }
          JS, machine_name: 'shared_keeper');
        $importer = $this->createComponent('keeper importer', <<<'JS'
          import { h } from "preact";
          import { status } from "@/components/shared_keeper";
          export default function Root() {
            return h("div", null, `imported ${status}`);
          }
          JS, imports: ['shared_keeper']);
        $this->renderComponent($keeper);
        $cacheability = new CacheableMetadata();
        self::assertSame('<div>imported 200</div>', $this->extractSsrMarkup($this->renderComponent($importer, cacheability: $cacheability)));
        self::assertContains('canned_fetch', $cacheability->getCacheTags());

        // An island that shares no component module with them doesn't.
        $cacheability = new CacheableMetadata();
        $other = $this->createComponent('non keeper', 'import { h } from "preact"; export default function Root() { return h("div", null, "plain"); }');
        self::assertSame('<div>plain</div>', $this->extractSsrMarkup($this->renderComponent($other, cacheability: $cacheability)));
        self::assertNotContains('canned_fetch', $cacheability->getCacheTags());
      },

      // What the module kept may be from a fetch whose markup can't be used,
      // or can't be cached for long. A later island that reads it is no
      // better off than the island that fetched it.
      "a later island takes on what went wrong with what the component's module kept" => function (): void {
        $keeper = static fn (string $url): string => \sprintf(<<<'JS'
          import { h } from "preact";
          let kept;
          export default function Root(props) {
            if (kept === undefined) {
              throw fetch(%s).then(
                (response) => { kept = response.status; },
                (error) => { kept = error.name; },
              );
            }
            return h("div", null, `${kept} ${props.n}`);
          }
          JS, \json_encode($url, JSON_THROW_ON_ERROR));

        $unknown = $this->createComponent('unknown keeper', $keeper('/jsonapi/unknown'));
        $refused = $this->createComponent('refused keeper', $keeper('https://example.invalid/api'));
        foreach ([1, 2] as $n) {
          $output = $this->renderComponent($unknown, ['n' => $n]);
          self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output, "Island $n of a component that kept a response whose cacheability isn't known.");

          $cacheability = new CacheableMetadata();
          $output = $this->renderComponent($refused, ['n' => $n], cacheability: $cacheability);
          self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output, "Island $n of a component that kept a refused fetch.");
          // The first lasts until its host or component changes. The second
          // may be cached apart from the first's host, so it doesn't.
          self::assertSame($n === 1 ? Cache::PERMANENT : 3600, $cacheability->getCacheMaxAge(), "Island $n of a component that kept a refused fetch.");
          self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
          // The second makes no fetch of its own, so isn't told it did.
          if ($n === 1) {
            $this->assertWarnedOfFetch((string) $refused->id(), 'SSR fetch only supports same-site URLs');
          }
          else {
            $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
            self::assertStringContainsString("the {$refused->id()} component on the client, since an earlier island of it made a fetch SSR can't make", (string) \end($warnings));
          }
        }

        $server_error = $this->createComponent('server error keeper', $keeper('/jsonapi/server-error-unknown'));
        foreach ([1, 2] as $n) {
          $cacheability = new CacheableMetadata();
          $output = $this->renderComponent($server_error, ['n' => $n], cacheability: $cacheability);

          self::assertSame("<div>503 $n</div>", $this->extractSsrMarkup($output));
          self::assertSame(60, $cacheability->getCacheMaxAge(), "Island $n of a component that kept a server error.");
          self::assertContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags(), "Island $n of a component that kept a server error.");
        }
      },

      // A runner may finish loading the module only after the render, once
      // nothing else is left to run. The island waits for it, so the fetch
      // isn't made during a later island, and counted as that island's.
      "what follows an import() a component doesn't wait for is its own island's" => function (): void {
        $this->createComponent('lazy thing', 'export default function Lazy() { return null; }', machine_name: 'lazy_thing');
        $bystander = $this->createComponent('lazy bystander', <<<'JS'
          import { h } from "preact";
          export default function Root() {
            return h("div", null, "bystander");
          }
          JS);
        $chain = static fn(int $length): string => \sprintf('Array.from({ length: %d }).reduce((chain, _, n) => chain.then(() => import("data:text/javascript,export default " + n)).catch(() => {}), Promise.resolve())', $length);
        $imports = [
          'a component' => 'import("@/components/lazy_thing")',
          'a computed specifier' => 'import(String("swr"))',
          // Each starts once the one before has loaded or failed to, so the
          // last would settle long after the render. A runner may load
          // ahead what a string names, so these are computed.
          'one after another' => $chain(40),
        ];
        foreach ($imports as $case => $import) {
          $starter = $this->createComponent("lazy starter $case", \sprintf(<<<'JS'
            import { h } from "preact";
            export default function Root() {
              %s.then(() => fetch("/jsonapi/fine"));
              return h("div", null, "started");
            }
            JS, $import), imports: ['lazy_thing']);
          $cacheability = new CacheableMetadata();
          $output = $this->renderComponent($starter, cacheability: $cacheability);
          self::assertSame('<div>started</div>', $this->extractSsrMarkup($output), $case);
          self::assertContains('canned_fetch', $cacheability->getCacheTags(), $case);

          $cacheability = new CacheableMetadata();
          $output = $this->renderComponent($bystander, cacheability: $cacheability);
          self::assertSame('<div>bystander</div>', $this->extractSsrMarkup($output), $case);
          self::assertNotContains('canned_fetch', $cacheability->getCacheTags(), $case);
        }

        // A component that goes on importing is given up on, and the
        // runner reset, so it doesn't go on during later islands. That's
        // after so many rounds of waiting, or sooner if the runner's time
        // limit ends one. A runner that loads within the job queue has
        // finished them by then.
        $endless = $this->createComponent('lazy endless', <<<'JS'
          import { h } from "preact";
          let n = 0;
          const next = () => n++ < 2000 && import("data:text/javascript,export default " + n).catch(() => {}).then(next);
          export default function Root() {
            next();
            return h("div", null, "endless");
          }
          JS);
        $output = $this->renderComponent($endless);
        if (\str_contains($output, self::CLIENT_ONLY_ATTRIBUTE)) {
          $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
          self::assertMatchesRegularExpression("/Rendering the {$endless->id()} component on the client, since (after 100 rounds or 2 seconds of waiting|its server-side render went past a time or memory limit)/", (string) \end($warnings));
        }
        else {
          self::assertSame('<div>endless</div>', $this->extractSsrMarkup($output));
        }
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($bystander, cacheability: $cacheability);
        self::assertSame('<div>bystander</div>', $this->extractSsrMarkup($output));
        self::assertNotContains('canned_fetch', $cacheability->getCacheTags());

        // Each round of waiting has a time limit of its own, so one that
        // spends most of a round's, round after round, is given up on by
        // the clock.
        $slow = $this->createComponent('lazy slow', <<<'JS'
          import { h } from "preact";
          let n = 0;
          const spin = () => { const start = Date.now(); while (Date.now() - start < 300); };
          const next = () => n++ < 2000 && import("data:text/javascript,export default " + n).catch(() => {}).then(() => { spin(); return next(); });
          export default function Root() {
            next();
            return h("div", null, "slow");
          }
          JS);
        $start = \hrtime(TRUE);
        $output = $this->renderComponent($slow);
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertLessThan(15, (\hrtime(TRUE) - $start) / 1e9);

        // What isn't a promise isn't waited for: waiting would call its
        // then(), every round.
        $thenable = $this->createComponent('lazy thenable', <<<'JS'
          import { h } from "preact";
          export default function Root() {
            try {
              __canvasSsrTrackImport({ then(resolve) { resolve(); } });
            }
            catch (e) {
              return h("div", null, e.constructor.name);
            }
            return h("div", null, "tracked");
          }
          JS);
        self::assertSame('<div>TypeError</div>', $this->extractSsrMarkup($this->renderComponent($thenable)));
      },

      // A later island takes on what a module's code fetched, not what
      // every island with the module in its graph did. So islands that
      // only share a component such as a button don't affect each other.
      'a later island takes on only what the code of its own modules fetched' => function (): void {
        $this->createComponent('button', <<<'JS'
          import { h } from "preact";
          export default function Button() {
            return h("button", null, "ok");
          }
          JS, machine_name: 'button');
        // Fetches for whoever calls it, and keeps what it got.
        $this->createComponent('loader', <<<'JS'
          let kept;
          export function load() {
            if (kept === undefined) {
              throw fetch("/jsonapi/fine").then((response) => { kept = response.status; });
            }
            return kept;
          }
          export default function Loader() {
            return null;
          }
          JS, machine_name: 'loader');
        $plain = static fn (string $text): string => \sprintf(<<<'JS'
          import { h } from "preact";
          import Button from "@/components/button";
          export default function Root() {
            return h("div", null, %s, h(Button, null));
          }
          JS, \json_encode($text, JSON_THROW_ON_ERROR));
        $reader = static fn (string $text): string => \sprintf(<<<'JS'
          import { h } from "preact";
          import Button from "@/components/button";
          import { load } from "@/components/loader";
          export default function Root() {
            return h("div", null, `%s ${load()}`, h(Button, null));
          }
          JS, $text);

        $refused = $this->createComponent('refused', <<<'JS'
          import { h } from "preact";
          import Button from "@/components/button";
          let kept;
          export default function Root() {
            if (kept === undefined) {
              throw fetch("https://example.invalid/api").catch((error) => { kept = error.name; });
            }
            return h("div", null, kept, h(Button, null));
          }
          JS, imports: ['button']);
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $this->renderComponent($refused));

        // One that shares only the button renders, and takes nothing on.
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->createComponent('card', $plain('card'), imports: ['button']), cacheability: $cacheability);
        self::assertSame('<div>card<button>ok</button></div>', $this->extractSsrMarkup($output));
        self::assertNotContains('canned_fetch', $cacheability->getCacheTags());

        // A module that fetches for its importer is on the call stack with
        // it, so an island of any component that imports it takes on what
        // it fetched.
        $first = $this->createComponent('first reader', $reader('first'), imports: ['button', 'loader']);
        $second = $this->createComponent('second reader', $reader('second'), imports: ['button', 'loader']);
        foreach (['first' => $first, 'second' => $second] as $text => $component) {
          $cacheability = new CacheableMetadata();
          $output = $this->renderComponent($component, cacheability: $cacheability);
          self::assertSame("<div>$text 200<button>ok</button></div>", $this->extractSsrMarkup($output));
          self::assertContains('canned_fetch', $cacheability->getCacheTags(), "The $text reader.");
        }
        $cacheability = new CacheableMetadata();
        $this->renderComponent($this->createComponent('tile', $plain('tile'), imports: ['button']), cacheability: $cacheability);
        self::assertNotContains('canned_fetch', $cacheability->getCacheTags());

        // A call that no module's code is on the stack of could be kept by
        // any module of its island, so islands that share one take it on.
        $deferred = $this->createComponent('deferred', <<<'JS'
          import { h } from "preact";
          import Button from "@/components/button";
          let kept;
          export default function Root() {
            if (kept === undefined) {
              throw Promise.resolve("/jsonapi/fine").then(fetch).then((response) => { kept = response.status; });
            }
            return h("div", null, kept, h(Button, null));
          }
          JS, imports: ['button']);
        self::assertSame('<div>200<button>ok</button></div>', $this->extractSsrMarkup($this->renderComponent($deferred)));
        $cacheability = new CacheableMetadata();
        $this->renderComponent($this->createComponent('panel', $plain('panel'), imports: ['button']), cacheability: $cacheability);
        self::assertContains('canned_fetch', $cacheability->getCacheTags());
      },

      // A library that fetches for a component may call fetch() once the
      // component has returned, so the component isn't on the stack. The
      // library is, and the fetch counts for the components that import it.
      'a fetch a library makes counts for the components that import it' => function (): void {
        $this->createComponent('swr button', <<<'JS'
          import { h } from "preact";
          export default function Button() {
            return h("button", null, "ok");
          }
          JS, machine_name: 'swr_button');
        $plain = static fn (string $text): string => \sprintf(<<<'JS'
          import { h } from "preact";
          import Button from "@/components/swr_button";
          export default function Root() {
            return h("div", null, %s, h(Button, null));
          }
          JS, \json_encode($text, JSON_THROW_ON_ERROR));
        // The SWR module calls the fetcher, here fetch() itself, when the
        // component has returned.
        $reader = static fn (string $url): string => \sprintf(<<<'JS'
          import { h } from "preact";
          import useSWR from "swr";
          import Button from "@/components/swr_button";
          export default function Root() {
            const { data } = useSWR(%s, fetch);
            return h("div", null, String(data.status), h(Button, null));
          }
          JS, \json_encode($url, JSON_THROW_ON_ERROR));

        $refused = $this->createComponent('swr refused', $reader('https://example.invalid/api'), imports: ['swr_button']);
        $fine = $this->createComponent('swr fine', $reader('/jsonapi/fine'), imports: ['swr_button']);
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $this->renderComponent($refused));
        self::assertSame('<div>200<button>ok</button></div>', $this->extractSsrMarkup($this->renderComponent($fine)));

        // One that shares only the button takes on neither.
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->createComponent('swr card', $plain('card'), imports: ['swr_button']), cacheability: $cacheability);
        self::assertSame('<div>card<button>ok</button></div>', $this->extractSsrMarkup($output));
        self::assertNotContains('canned_fetch', $cacheability->getCacheTags());

        // A component that keeps what a fetcher its parent handed it got
        // imports the SWR module, so a later island with it takes that on,
        // though only its parent is on the stack.
        $this->createComponent('swr child', <<<'JS'
          import { h } from "preact";
          import useSWR from "swr";
          let kept;
          export default function Child(props) {
            if (props.fetcher) {
              try {
                kept = useSWR(props.url, props.fetcher).data;
              }
              catch (thrown) {
                if (thrown instanceof Promise) throw thrown;
                kept = "failed";
              }
            }
            return h("i", null, String(kept));
          }
          JS, machine_name: 'swr_child');
        $parent = static fn (string $url): string => \sprintf(<<<'JS'
          import { h } from "preact";
          import Child from "@/components/swr_child";
          const fetcher = (url) => fetch(url).then((response) => response.status);
          export default function Root() {
            return h(Child, { url: %s, fetcher });
          }
          JS, \json_encode($url, JSON_THROW_ON_ERROR));
        $orphan = <<<'JS'
          import { h } from "preact";
          import Child from "@/components/swr_child";
          export default function Root() {
            return h(Child, null);
          }
          JS;
        $fine_parent = $this->createComponent('swr parent', $parent('/jsonapi/fine'), imports: ['swr_child']);
        self::assertSame('<i>200</i>', $this->extractSsrMarkup($this->renderComponent($fine_parent)));
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->createComponent('swr orphan', $orphan, imports: ['swr_child']), cacheability: $cacheability);
        self::assertSame('<i>200</i>', $this->extractSsrMarkup($output));
        self::assertContains('canned_fetch', $cacheability->getCacheTags());
        // Only a fetch that rules SSR out brings its component's tags.
        self::assertNotSame([], \array_diff($fine_parent->getCacheTags(), $cacheability->getCacheTags()));

        $refused_parent = $this->createComponent('swr refused parent', $parent('https://example.invalid/api'), imports: ['swr_child']);
        $this->renderComponent($refused_parent);
        $second_orphan = $this->createComponent('swr second orphan', $orphan, imports: ['swr_child']);
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($second_orphan, cacheability: $cacheability);
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        // Its fallback ends when the component that made the fetch changes.
        self::assertSame([], \array_diff($refused_parent->getCacheTags(), $cacheability->getCacheTags()));
        // The warning names the component that made the fetch, since the
        // one that took it on makes none.
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        $last = (string) \end($warnings);
        self::assertStringContainsString("Rendering the {$second_orphan->id()} component on the client, since it shares code with the {$refused_parent->id()} component", $last);
        self::assertStringContainsString('SSR fetch only supports same-site URLs', $last);

        // Another island of the component that made it is told the same,
        // not that it made the fetch itself: it makes none.
        $this->renderComponent($refused_parent);
        $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
        self::assertStringContainsString("Rendering the {$refused_parent->id()} component on the client, since an earlier island of it made a fetch SSR can't make", (string) \end($warnings));

        // What it took on already rules its markup out, so it isn't
        // rendered to find that out: it makes no fetch of its own.
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->createComponent('swr fetching orphan', <<<'JS'
          import { h } from "preact";
          import Child from "@/components/swr_child";
          let kept;
          export default function Root() {
            if (kept === undefined) {
              throw fetch("/jsonapi/server-error").then((response) => { kept = response.status; });
            }
            return h(Child, null);
          }
          JS, imports: ['swr_child']), cacheability: $cacheability);
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());

        // Nor does the fetcher's own await hide that: only the parent is
        // on the stack then, with no frame of the SWR module.
        $awaiting = <<<'JS'
          import { h } from "preact";
          import Child from "@/components/swr_child";
          const fetcher = async (url) => {
            await null;
            return (await fetch(url)).status;
          };
          export default function Root() {
            return h(Child, { url: "https://example.invalid/awaited", fetcher });
          }
          JS;
        $this->renderComponent($this->createComponent('swr awaiting parent', $awaiting, imports: ['swr_child']));
        $output = $this->renderComponent($this->createComponent('swr third orphan', $orphan, imports: ['swr_child']));
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
      },

      // A Canvas CLI codebase's own "@/lib" modules are pushed as modules
      // the import map names, and may import a fetching library for the
      // components that import them.
      'a component that imports a fetching library through a pushed module counts' => function (): void {
        $chunk = $this->writeGlobalAssetModule('status-chunk.js', 'import useSWR from "swr"; export const useStatus = (url) => useSWR(url, fetch).data.status;');
        $api = $this->writeGlobalAssetModule('api.js', 'export { useStatus } from "./status-chunk.js";');
        $deep = $this->writeGlobalAssetModule('deep.js', 'export const deep = (depth, url) => depth > 0 ? deep(depth - 1, url) : fetch(url);');
        $this->setGlobalAssetLibraryEntries(
          assets: [['name' => '@/lib/api', 'uri' => $api], ['name' => '@/lib/deep', 'uri' => $deep]],
          shared: [['name' => './local/status-chunk.js', 'uri' => $chunk]],
        );
        $this->createComponent('pushed button', <<<'JS'
          import { h } from "preact";
          export default function Button() {
            return h("button", null, "ok");
          }
          JS, machine_name: 'pushed_button');
        $reader = static fn (string $url): string => \sprintf(<<<'JS'
          import { h } from "preact";
          import { useStatus } from "@/lib/api";
          import Button from "@/components/pushed_button";
          export default function Root() {
            return h("div", null, String(useStatus(%s)), h(Button, null));
          }
          JS, \json_encode($url, JSON_THROW_ON_ERROR));
        self::assertSame('<div>200<button>ok</button></div>', $this->extractSsrMarkup($this->renderComponent($this->createComponent('pushed fine', $reader('/jsonapi/fine'), imports: ['pushed_button']))));
        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $this->renderComponent($this->createComponent('pushed refused', $reader('https://example.invalid/api'), imports: ['pushed_button'])));

        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->createComponent('pushed card', <<<'JS'
          import { h } from "preact";
          import Button from "@/components/pushed_button";
          export default function Root() {
            return h("div", null, h(Button, null));
          }
          JS, imports: ['pushed_button']), cacheability: $cacheability);
        self::assertSame('<div><button>ok</button></div>', $this->extractSsrMarkup($output));
        self::assertNotContains('canned_fetch', $cacheability->getCacheTags());

        // The component is still found on the stack past many frames of a
        // module that isn't one.
        $this->renderComponent($this->createComponent('pushed deep', <<<'JS'
          import { h } from "preact";
          import { deep } from "@/lib/deep";
          import Button from "@/components/pushed_button";
          let kept;
          export default function Root() {
            if (kept === undefined) {
              throw deep(20, "https://example.invalid/deep").catch((error) => { kept = error.name; });
            }
            return h("div", null, kept, h(Button, null));
          }
          JS, imports: ['pushed_button']));
        $output = $this->renderComponent($this->createComponent('pushed second card', <<<'JS'
          import { h } from "preact";
          import Button from "@/components/pushed_button";
          export default function Root() {
            return h("div", null, "second", h(Button, null));
          }
          JS, imports: ['pushed_button']));
        self::assertSame('<div>second<button>ok</button></div>', $this->extractSsrMarkup($output));
      },

      // Whether a response is cacheable depends on the endpoint, so the
      // fallback recurs, and is cached as a render is.
      'a response whose cacheability is not known falls back to CSR, cached as a render' => function (): void {
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->statusComponent('unknown reader', '/jsonapi/unknown'), cacheability: $cacheability);

        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },

      // JSON:API gives a server error max-age 0, which is kept.
      'a server error is a transient error, with its own cacheability' => function (): void {
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->statusComponent('server error reader', '/jsonapi/server-error'), cacheability: $cacheability);

        self::assertSame('<div>500 false</div>', $this->extractSsrMarkup($output));
        self::assertSame(0, $cacheability->getCacheMaxAge());
        self::assertContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },

      'a server error without cacheability is a transient error, cached briefly' => function (): void {
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->statusComponent('bare server error reader', '/jsonapi/server-error-unknown'), cacheability: $cacheability);

        self::assertSame('<div>503 false</div>', $this->extractSsrMarkup($output));
        self::assertSame(60, $cacheability->getCacheMaxAge());
        self::assertContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },

      // A refusal is decided before any request, the same way every time, so
      // the fallback is cached as a render is.
      'a refused fetch falls back to CSR, cached as a render' => function (): void {
        $cacheability = new CacheableMetadata();
        $output = $this->renderComponent($this->statusComponent('refused reader', 'https://example.invalid/api'), cacheability: $cacheability);

        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },

      // As in the browser, a failed fetch rejects with a TypeError rather
      // than throwing, so a component can catch it. But the browser's fetch
      // may work, so the island still falls back, and its author is told.
      'a refused fetch the component catches still falls back to CSR' => function (): void {
        $source = <<<'JS'
        import { h } from "preact";
        import useSWR from "swr";
        export default function Root() {
          const { data } = useSWR(
            "https://example.invalid/api",
            (url) => fetch(url).then(() => "fetched", (error) => `${error.name}: ${error.message.startsWith("Failed to fetch: ")}`),
          );
          return h("div", null, data);
        }
        JS;
        $cacheability = new CacheableMetadata();
        $component = $this->createComponent('refusal catcher', $source);
        $output = $this->renderComponent($component, cacheability: $cacheability);

        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
        // Not a script failure: the component caught the rejection.
        $this->assertWarnedOfFetch((string) $component->id(), 'SSR fetch only supports same-site URLs');
      },

      // As in the browser, fetch() takes a URL object as well as a string.
      'a fetch of a URL object is made' => function (): void {
        $source = <<<'JS'
        import { h } from "preact";
        import useSWR from "swr";
        export default function Root() {
          const { data } = useSWR(
            "/jsonapi/fine",
            (path) => fetch(new URL(path, "http://localhost")).then((response) => `${response.status} ${response.ok}`),
          );
          return h("div", null, data);
        }
        JS;
        $output = $this->renderComponent($this->createComponent('url object reader', $source));

        self::assertSame('<div>200 true</div>', $this->extractSsrMarkup($output));
      },

      // As in the browser, the init's headers replace a Request's, and a
      // name given twice, in any case, keeps both values.
      'request headers are sent as a browser sends them' => function (): void {
        $source = <<<'JS'
        import { h } from "preact";
        import useSWR from "swr";
        export default function Root() {
          const { data } = useSWR("/jsonapi/fine", (url) => fetch(
            { url, headers: { "X-From-Request": "1" } },
            { headers: [["Accept", "application/vnd.api+json"], ["accept", "application/json"]] },
          ).then((response) => String(response.status)));
          return h("div", null, data);
        }
        JS;
        $output = $this->renderComponent($this->createComponent('header sender', $source));

        self::assertSame('<div>200</div>', $this->extractSsrMarkup($output));
        $request = CannedFetch::$lastRequest;
        self::assertNotNull($request);
        self::assertSame('application/vnd.api+json, application/json', $request->getHeaderLine('accept'));
        self::assertFalse($request->hasHeader('x-from-request'));
      },

      // As in the browser, json() rejects a body that isn't JSON, such as
      // an empty one, rather than reading it as null.
      'an empty body read as JSON rejects' => function (): void {
        $source = <<<'JS'
        import { h } from "preact";
        import useSWR from "swr";
        export default function Root() {
          const { data } = useSWR("/jsonapi/empty", (url) => fetch(url).then(
            (response) => response.json().then((body) => `parsed ${body}`, (error) => error.name),
          ));
          return h("div", null, data);
        }
        JS;
        $output = $this->renderComponent($this->createComponent('empty body reader', $source));

        self::assertSame('<div>SyntaxError</div>', $this->extractSsrMarkup($output));
      },

      // The body reaches the realm as text.
      'a response whose body is not text falls back to CSR, cached as a render' => function (): void {
        $cacheability = new CacheableMetadata();
        $component = $this->statusComponent('binary reader', '/jsonapi/binary');
        $output = $this->renderComponent($component, cacheability: $cacheability);

        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        $this->assertWarnedOfFetch((string) $component->id(), 'body is UTF-8 text');
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        // The fallback ends when the response changes.
        self::assertContains('canned_fetch', $cacheability->getCacheTags());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },

      // A request PHP can't read fails the same way every time too.
      'a request that is not one falls back to CSR, cached as a render' => function (): void {
        $source = <<<'JS'
        import { h } from "preact";
        import useSWR from "swr";
        export default function Root() {
          const { data } = useSWR(
            "/jsonapi/fine",
            (url) => fetch(url, { method: "patch" }).then(() => "fetched", (error) => error.name),
          );
          return h("div", null, data);
        }
        JS;
        $cacheability = new CacheableMetadata();
        $component = $this->createComponent('malformed request', $source);
        $output = $this->renderComponent($component, cacheability: $cacheability);

        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        $this->assertWarnedOfFetch((string) $component->id(), 'must be upper-case');
        self::assertSame(Cache::PERMANENT, $cacheability->getCacheMaxAge());
        self::assertNotContains(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags());
      },
    ]);
  }

  /**
   * Asserts the last warning says a component's fetch can't be made in SSR.
   *
   * @param string $component
   *   The component's machine name.
   * @param string $reason
   *   Part of why.
   */
  private function assertWarnedOfFetch(string $component, string $reason): void {
    $warnings = $this->logger->at((string) RfcLogLevel::WARNING);
    self::assertNotSame([], $warnings);
    $last = (string) \end($warnings);
    self::assertStringContainsString("the $component component on the client, since SSR can't make a fetch it makes", $last);
    self::assertStringContainsString($reason, $last);
  }

  /**
   * Builds a component that renders a fetch's status and whether it's ok.
   *
   * @param string $label
   *   The component's label.
   * @param string $url
   *   What it fetches.
   */
  private function statusComponent(string $label, string $url): JavaScriptComponent {
    $source = \sprintf(<<<'JS'
    import { h } from "preact";
    import useSWR from "swr";
    export default function Root() {
      const { data } = useSWR(
        %s,
        (url) => fetch(url).then((response) => `${response.status} ${response.ok}`),
      );
      return h("div", null, data);
    }
    JS, \json_encode($url, JSON_THROW_ON_ERROR));

    return $this->createComponent($label, $source);
  }

}
