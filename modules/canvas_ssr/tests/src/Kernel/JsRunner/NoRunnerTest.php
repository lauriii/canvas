<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsRunner;

use Drupal\canvas_ssr\HostStatus;
use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\canvas_ssr\JsRunner\RunnerSelector;
use Drupal\canvas_ssr\JsRunner\UnavailableRunner;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that with no runner available, every island falls back to CSR.
 *
 * Deno is pointed at a missing binary, so this runs the same whether or not
 * Deno is installed.
 *
 * @legacy-covers \Drupal\canvas_ssr\JsRunner\UnavailableRunner
 * @legacy-covers \Drupal\canvas_ssr\Renderer
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class NoRunnerTest extends CanvasSsrKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);

    // No runner module is installed. Keep Deno as a candidate, but an
    // unavailable one.
    $container
      ->getDefinition(DenoRunner::class)
      ->setArgument(0, '/nonexistent/canvas-ssr-no-runner/deno');
  }

  /**
   * Tests the runner is unavailable, and islands fall back to CSR.
   */
  public function testNoAvailableRunnerFallsBackToCsr(): void {
    $this->assertScenarios([
      'the selector selects a runner that is unavailable' => function (): void {
        $runner = $this->container->get(RunnerInterface::class);
        $selector = $this->container->get(RunnerSelector::class);

        self::assertInstanceOf(RunnerSelector::class, $selector);
        self::assertInstanceOf(UnavailableRunner::class, $selector->select());
        self::assertFalse($runner->isAvailable());
      },

      'the status report says SSR is off, and why' => function (): void {
        $requirements = $this->container->get(ModuleHandlerInterface::class)->invoke('canvas_ssr', 'runtime_requirements');

        self::assertIsArray($requirements);
        $requirement = $requirements['canvas_ssr'];
        self::assertSame(RequirementSeverity::Warning, $requirement['severity']);
        self::assertSame('Off', (string) $requirement['value']);
        $description = $this->describe($requirement);
        self::assertStringContainsString(\sprintf('On %s, this web server, no runner is available', HostStatus::host()), $description);
        self::assertStringContainsString('every island renders in the browser instead', $description);
        self::assertStringContainsString('Once a runner is available, clear all caches.', $description);
        // Escaped, since it's shown as HTML.
        self::assertStringContainsString('The Deno runner is unavailable, since there is no executable Deno binary at &quot;/nonexistent/canvas-ssr-no-runner/deno&quot;.', $description);
      },

      'a component that would server-render falls back to CSR' => function (): void {
        $component_source = <<<'JS'
        import { createElement } from "preact";
        export default function Root() {
          return createElement("span", null, "server-marker");
        }
        JS;
        $component = $this->createComponent('component with nowhere to run', $component_source);

        $output = $this->renderComponent($component);

        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringNotContainsString('server-marker', $output);
      },

      // Each web server records it, so the report shows them all.
      'falling back records that this host has no runner' => function (): void {
        $reports = $this->container->get(HostStatus::class)->reports();

        self::assertStringContainsString('there is no executable Deno binary', $reports[HostStatus::host()]['off']['reason'] ?? '');
      },

      'the status report lists what other web servers recorded' => function (): void {
        $store = $this->container->get(KeyValueExpirableFactoryInterface::class)->get('canvas_ssr.host_status');
        \assert($store instanceof KeyValueStoreExpirableInterface);
        $store->setWithExpire('off:web-2', ['reason' => 'Its cache directory is someone else\'s.', 'at' => \time()], 3600);
        $store->setWithExpire('base:web-3', [
          'since' => \time() - 600,
          'due' => \time() + 600,
          'error' => 'It ran out of time.',
        ], 7200);
        $store->setWithExpire('limits:web-4', ['limitations' => ['The Deno runner can\'t read /proc here.'], 'at' => \time()], 3600);

        $requirements = $this->container->get(ModuleHandlerInterface::class)->invoke('canvas_ssr', 'runtime_requirements');
        $description = $this->describe($requirements['canvas_ssr']);

        self::assertStringContainsString('On web-2, no runner was available', $description);
        self::assertStringContainsString('someone else&#039;s', $description);
        self::assertStringContainsString('On web-3, the SSR base hasn\'t been built since', $description);
        self::assertStringContainsString('It ran out of time.', $description);
        self::assertStringContainsString('On web-4, SSR was on as of', $description);
        self::assertStringContainsString('with a limit it can\'t enforce. The Deno runner can&#039;t read /proc here.', $description);
        // This one's own record isn't listed twice.
        self::assertSame(1, \substr_count($description, 'On ' . HostStatus::host() . ','));
      },
    ]);
  }

  /**
   * Renders a requirement's description.
   *
   * @param array<string, mixed> $requirement
   *   The requirement.
   *
   * @return string
   *   The HTML.
   */
  private function describe(array $requirement): string {
    $description = $requirement['description'] ?? '';
    if (!\is_array($description)) {
      return (string) $description;
    }

    return (string) $this->container->get('renderer')->renderInIsolation($description);
  }

}
