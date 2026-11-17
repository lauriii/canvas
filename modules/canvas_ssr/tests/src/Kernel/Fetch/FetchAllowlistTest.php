<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Fetch;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the same-site fetch goes through the path policies.
 *
 * Unit tests cover each policy's paths. This checks the policies are wired
 * into the fetch a render uses, with one allowed path and one refused one,
 * and what an allowed path answered with an error gives.
 * JSON:API paths are tested elsewhere, since they need the jsonapi module.
 *
 * @legacy-covers \Drupal\canvas_ssr\Fetch\SameSiteFetch
 * @legacy-covers \Drupal\canvas_ssr\Fetch\Policy\MenuLinksetPathPolicy
 *
 * @see \Drupal\Tests\canvas_ssr\Unit\Fetch\Policy\MenuLinksetPathPolicyTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\Fetch\SameSiteFetchTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class FetchAllowlistTest extends CanvasSsrKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
  }

  /**
   * Tests a render's fetch allows only what a policy allows.
   */
  public function testEveryFetchGoesThroughPolicyAllowlist(): void {
    $this->assertScenarios([
      // Not JSON:API, so the fetch checks more than one policy.
      'a path a policy claims is fetched' => function (): void {
        $output = $this->renderComponent(
          $this->fetchingComponent('menu linkset fetch consumer', '/system/menu/main/linkset', 'menu-linkset-fetch-ok'),
        );

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertSame('<div>menu-linkset-fetch-ok</div>', $this->extractSsrMarkup($output));
      },

      // A real route, but no policy allows it. Otherwise a component could
      // read anything the user can, and bake it into cached markup.
      'a path no policy claims is refused, and the island falls back to CSR' => function (): void {
        self::assertStringContainsString(
          self::CLIENT_ONLY_ATTRIBUTE,
          $this->renderComponent(
            $this->fetchingComponent('unclaimed path fetch consumer', '/user/login', 'should-not-ssr'),
          ),
        );
      },

      // As in the browser, an error status is a response, not a failure. The
      // component decides what it means.
      'an allowed path answered with an error resolves to that response' => function (): void {
        $output = $this->renderComponent(
          $this->statusComponent('error status reader', 'return `${response.status} ${response.ok}`;'),
        );

        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertSame('<div>404 false</div>', $this->extractSsrMarkup($output));
      },

      // Data-fetching code usually throws on one, as Canvas's JSON:API client
      // does.
      'a component that throws on an error status falls back to CSR' => function (): void {
        self::assertStringContainsString(
          self::CLIENT_ONLY_ATTRIBUTE,
          $this->renderComponent(
            $this->statusComponent('error status thrower', 'if (!response.ok) { throw new Error(`HTTP ${response.status}`); } return "ok";'),
          ),
        );
      },
    ]);
  }

  /**
   * Builds a component that fetches a menu no site has, and renders a result.
   *
   * @param string $label
   *   The component's label.
   * @param string $handler
   *   The body of a function of the response, returning what to render.
   */
  private function statusComponent(string $label, string $handler): JavaScriptComponent {
    $source = \sprintf(<<<'JS'
    import { h } from "preact";
    import useSWR from "swr";
    export default function Root() {
      const { data } = useSWR(
        "/system/menu/no-such-menu/linkset",
        (url) => fetch(url).then((response) => { %s }),
      );
      return h("div", null, data);
    }
    JS, $handler);

    return $this->createComponent($label, $source);
  }

  /**
   * Builds a component that renders a marker once its fetch resolves.
   *
   * @param string $label
   *   The component's label.
   * @param string $url
   *   The URL it fetches.
   * @param string $marker
   *   The text it renders. A fetch that rejects leaves it nothing to
   *   render, so the island falls back to CSR.
   */
  private function fetchingComponent(string $label, string $url, string $marker): JavaScriptComponent {
    $source = \sprintf(<<<'JS'
    import { h } from "preact";
    import useSWR from "swr";
    export default function Root() {
      const { data } = useSWR(%s, (url) => fetch(url).then(() => %s));
      return h("div", null, data);
    }
    JS,
      \json_encode($url, JSON_THROW_ON_ERROR),
      \json_encode($marker, JSON_THROW_ON_ERROR),
    );

    return $this->createComponent($label, $source);
  }

}
