<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\DrupalSettings;

use Drupal\canvas\CodeComponentDataProvider;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests what Canvas's site and page data render, and what bubbles.
 *
 * DrupalSettingsResolverTest covers which keys a graph gets. This covers
 * their values and cacheability, which need a render.
 *
 * @legacy-covers \Drupal\canvas_ssr\DrupalSettings\Provider\CanvasSiteDataProvider
 * @legacy-covers \Drupal\canvas_ssr\DrupalSettings\Provider\CanvasPageDataProvider
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\DrupalSettings\DrupalSettingsResolverTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class CanvasSiteAndPageDataTest extends CanvasSsrKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    // The page data reads a canvas_page from the route.
    $this->installEntitySchema('canvas_page');
  }

  /**
   * Tests the data a component gets, and the cacheability it bubbles.
   */
  public function testDataRendersAndBubblesCacheability(): void {
    $this->assertScenarios([
      'getPageData() renders the page and carries its cache tag' => function (): void {
        $page = Page::create(['title' => 'SSR page']);
        $page->save();
        $request_stack = $this->pushPageRequest($page);

        $root = $this->createComponent(
          'page data component',
          <<<'JS'
          import { getPageData } from "drupal-canvas";
          export default function Root() {
            return <div>{getPageData().pageTitle}</div>;
          }
          JS,
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          import { getPageData } from "drupal-canvas";
          export default function Root() {
            return _jsx("div", { children: getPageData().pageTitle });
          }
          JS,
          data_dependencies: ['drupalSettings' => ['v0.pageTitle', 'v0.breadcrumbs', 'v0.mainEntity']],
        );

        try {
          $output = $this->renderComponent($root, cacheability: $cacheability);
          self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
          self::assertStringContainsString('SSR page', $output);
          self::assertContains('route', $cacheability->getCacheContexts());
          self::assertContains('languages:language_content', $cacheability->getCacheContexts());
          self::assertContains('canvas_page:' . $page->id(), $cacheability->getCacheTags());
        }
        finally {
          $request_stack->pop();
        }
      },

      'getSiteData() renders real, request-stable site data' => function (): void {
        $this->config('system.site')->set('name', 'Canvas SSR Test Site')->save();

        $root = $this->createComponent(
          'site data component',
          <<<'JS'
          import { getSiteData } from "drupal-canvas";
          export default function Root() {
            return <div>{getSiteData().branding.siteName}</div>;
          }
          JS,
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          import { getSiteData } from "drupal-canvas";
          export default function Root() {
            return _jsx("div", { children: getSiteData().branding.siteName });
          }
          JS,
          data_dependencies: ['drupalSettings' => ['v0.branding', 'v0.baseUrl']],
        );

        $output = $this->renderComponent($root, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('Canvas SSR Test Site', $output);

        // So the island is invalidated when site config or theme settings
        // change.
        $cache_tags = $cacheability->getCacheTags();
        self::assertContains('config:system.site', $cache_tags);
        // CanvasKernelTestBase installs stark as the active theme.
        self::assertContains('config:stark.settings', $cache_tags);
      },

      // Each key is given on its own, so declaring only themeAssets is
      // enough.
      'getSiteData().themeAssets is a real object, not just a trigger' => function (): void {
        $root = $this->createComponent(
          'theme assets component',
          <<<'JS'
          import { getSiteData } from "drupal-canvas";
          export default function Root() {
            return <div>{typeof getSiteData().themeAssets.logo.url}</div>;
          }
          JS,
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          import { getSiteData } from "drupal-canvas";
          export default function Root() {
            return _jsx("div", { children: typeof getSiteData().themeAssets.logo.url });
          }
          JS,
          data_dependencies: ['drupalSettings' => ['v0.themeAssets']],
        );

        $output = $this->renderComponent($root, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('<div>string</div>', $output);
        self::assertContains('config:stark.settings', $cacheability->getCacheTags());
      },

      // Canvas's island script gives the hooks their context in the
      // browser. The framework renderer does here, from the same data.
      "Canvas's context hooks give the page and site data" => function (): void {
        $this->config('system.site')->set('name', 'Canvas SSR Test Site')->save();
        $page = Page::create(['title' => 'SSR page']);
        $page->save();
        $request_stack = $this->pushPageRequest($page);

        $root = $this->createComponent(
          'context hooks component',
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          import { usePageContext, useSiteContext } from "drupal-canvas/react";
          export default function Root() {
            const page = usePageContext();
            const site = useSiteContext();
            return _jsx("div", { children: `${page.pageTitle}|${site.branding.siteName}|${typeof site.themeAssets.logo.url}` });
          }
          JS,
          data_dependencies: [
            'drupalSettings' => [
              'v0.breadcrumbs',
              'v0.pageTitle',
              'v0.mainEntity',
              'v0.baseUrl',
              'v0.branding',
              'v0.themeAssets',
            ],
          ],
        );

        try {
          $output = $this->renderComponent($root, cacheability: $cacheability);
          self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
          self::assertStringContainsString('<div>SSR page|Canvas SSR Test Site|string</div>', $output);
          self::assertContains('canvas_page:' . $page->id(), $cacheability->getCacheTags());
          self::assertContains('config:system.site', $cacheability->getCacheTags());
        }
        finally {
          $request_stack->pop();
        }
      },

      // The CLI declares each hook's data for the component that imports
      // it, so this is a component that got a hook some other way.
      'a context hook gives null for data the component did not declare' => function (): void {
        $root = $this->createComponent(
          'undeclared context component',
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          import { useJsonApiClient, usePageContext, useSiteContext } from "drupal-canvas/react";
          export default function Root() {
            return _jsx("div", { children: `${usePageContext()}|${useSiteContext() === null ? null : "site"}|${useJsonApiClient()}` });
          }
          JS,
          data_dependencies: ['drupalSettings' => ['v0.branding']],
        );

        $output = $this->renderComponent($root, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('<div>null|site|null</div>', $output);
        self::assertNotContains('route', $cacheability->getCacheContexts());
      },

      // Canvas after 1.12 declares v0.langcode for useSiteContext() and
      // useDateFormatter(). Canvas 1.12 has neither the key nor the hook.
      'useSiteContext() and useDateFormatter() get the interface language' => function (): void {
        if (!\in_array('getCanvasDataLangcodeV0', \get_class_methods(CodeComponentDataProvider::class), TRUE)) {
          return;
        }
        // The formatter needs Intl, which is ECMA-402. An engine may leave
        // that out, so the component only formats where there's one.
        $root = $this->createComponent(
          'langcode component',
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          import { useDateFormatter, useSiteContext } from "drupal-canvas/react";
          export default function Root() {
            const format = useDateFormatter();
            const date = typeof Intl === "undefined" ? "no Intl" : format("2026-01-15", { dateStyle: "long" });
            return _jsx("div", { children: `${useSiteContext().langcode}|${date}` });
          }
          JS,
          data_dependencies: ['drupalSettings' => ['v0.langcode']],
        );

        $output = $this->renderComponent($root, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertMatchesRegularExpression('#<div>en\|(January 15, 2026|no Intl)</div>#', $output);
        self::assertContains('languages:language_interface', $cacheability->getCacheContexts());
      },

      // As in the browser, where Canvas attaches a library per declared
      // key, a key the component didn't declare isn't set, even beside one
      // it did.
      "Canvas's functions know they're on a Drupal page" => function (): void {
        $root = $this->createComponent(
          'runtime marker component',
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          const atModuleScope = JSON.stringify(globalThis.__drupalCanvasRuntime);
          export default function Root() {
            return _jsx("div", { children: atModuleScope + " " + JSON.stringify(globalThis.__drupalCanvasRuntime) });
          }
          JS,
        );

        $output = $this->renderComponent($root);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('<div>{&quot;environment&quot;:&quot;drupal&quot;} {&quot;environment&quot;:&quot;drupal&quot;}</div>', $output);
      },

      'only the declared keys are set' => function (): void {
        $root = $this->createComponent(
          'branding component',
          <<<'JS'
          import { jsx as _jsx } from "react/jsx-runtime";
          export default function Root() {
            const v0 = globalThis.drupalSettings?.canvasData?.v0 ?? {};
            return _jsx("div", { children: Object.keys(v0).sort().join(",") });
          }
          JS,
          data_dependencies: ['drupalSettings' => ['v0.branding', 'v0.baseUrl']],
        );

        $output = $this->renderComponent($root);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringContainsString('<div>baseUrl,branding</div>', $output);
      },

      'a component reading no canvas data bubbles none of its cacheability' => function (): void {
        $root = $this->createFormattedTextNullComponent('no data component');

        $output = $this->renderComponent($root, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertNotContains('config:system.site', $cacheability->getCacheTags());
        self::assertNotContains('route', $cacheability->getCacheContexts());
      },

      // A component can read data it didn't declare. It bubbles no
      // cacheability, so it must get nothing. The risk is the realm still
      // holding the previous island's drupalSettings.
      'an undeclared read gets nothing, not the last islands data' => function (): void {
        $this->config('system.site')->set('name', 'Canvas SSR Test Site')->save();

        $js = <<<'JS'
        import { jsx as _jsx } from "react/jsx-runtime";
        import { getSiteData } from "drupal-canvas";
        export default function Root() {
          return _jsx("div", { children: `[${getSiteData().branding.siteName}]` });
        }
        JS;
        $declared = $this->createComponent(
          'site data component',
          $js,
          data_dependencies: ['drupalSettings' => ['v0.branding', 'v0.baseUrl']],
        );
        // Same code, no declaration. getSiteData() returns empty values
        // rather than throwing.
        $undeclared = $this->createComponent('undeclared site data component', $js);

        // Render the declaring island first.
        $declared_output = $this->renderComponent($declared);
        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $declared_output);
        self::assertStringContainsString('[Canvas SSR Test Site]', $declared_output);

        $undeclared_output = $this->renderComponent($undeclared, cacheability: $cacheability);

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $undeclared_output);
        self::assertStringContainsString('[]', $undeclared_output);
        self::assertStringNotContainsString('Canvas SSR Test Site', $undeclared_output);
        self::assertNotContains('config:system.site', $cacheability->getCacheTags());
      },

      // A module evaluates once per realm, which can outlive the page, so
      // while evaluating it sees only site data. Page data
      // comes with the payload. Module scope needs site data for a top-level
      // `new JsonApiClient()`.
      'module scope sees the site data, and only a render sees the page data' => function (): void {
        $this->config('system.site')->set('name', 'Canvas SSR Test Site')->save();
        $page = Page::create(['title' => 'SSR page']);
        $page->save();
        $request_stack = $this->pushPageRequest($page);

        $js = <<<'JS'
        import { jsx as _jsx } from "react/jsx-runtime";
        import { getPageData } from "drupal-canvas";
        const v0 = globalThis.drupalSettings?.canvasData?.v0;
        const atLoad = `${v0?.branding?.siteName}|${v0?.pageTitle}`;
        export default function Root() {
          return _jsx("div", { children: `[${atLoad}|${getPageData().pageTitle}]` });
        }
        JS;
        $root = $this->createComponent(
          'module scope component',
          $js,
          data_dependencies: ['drupalSettings' => ['v0.branding', 'v0.pageTitle']],
        );

        try {
          $output = $this->renderComponent($root);
          self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
          self::assertStringContainsString('[Canvas SSR Test Site|undefined|SSR page]', $output);
        }
        finally {
          $request_stack->pop();
        }
      },
    ]);
  }

  /**
   * Pushes a request for a canvas_page's canonical route.
   *
   * Kernel tests skip routing, so this sets the route attributes by hand.
   *
   * @param \Drupal\canvas\Entity\Page $page
   *   The page the request is for.
   *
   * @return \Symfony\Component\HttpFoundation\RequestStack
   *   The request stack, for the caller to pop.
   */
  private function pushPageRequest(Page $page): RequestStack {
    $request = Request::create('/page/' . $page->id());
    $route_provider = $this->container->get(RouteProviderInterface::class);
    $route = $route_provider->getRouteByName('entity.canvas_page.canonical');
    $request->attributes->set('_route', 'entity.canvas_page.canonical');
    $request->attributes->set('_route_object', $route);
    $request->attributes->set('canvas_page', $page);
    $request->attributes->set('_raw_variables', new ParameterBag([
      'canvas_page' => (string) $page->id(),
    ]));
    $request_stack = $this->container->get(RequestStack::class);
    \assert($request_stack instanceof RequestStack);
    $request_stack->push($request);

    return $request_stack;
  }

  /**
   * Creates a component that imports FormattedText but renders nothing.
   *
   * The real import shows drupal-canvas loaded and evaluated.
   */
  private function createFormattedTextNullComponent(string $name): JavaScriptComponent {
    $js = <<<'JS'
    import { FormattedText } from "drupal-canvas";
    export default function Root() {
      return null;
    }
    JS;
    return $this->createComponent($name, $js);
  }

}
