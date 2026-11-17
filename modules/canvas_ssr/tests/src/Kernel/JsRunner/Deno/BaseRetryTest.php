<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsRunner\Deno;

use Drupal\canvas_ssr\EventSubscriber\PendingBaseSubscriber;
use Drupal\canvas_ssr\HostStatus;
use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\RunnerSelector;
use Drupal\canvas_ssr\Renderer;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\canvas_ssr\Traits\WarmsUpDenoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a base the Deno runner can't build in time is retried.
 *
 * It's retried after the response. This also tests what the site sees
 * meanwhile.
 *
 * Islands fall back to CSR, cached until the base can be used. A retry that
 * builds it invalidates them. One that fails is recorded for the status
 * report.
 *
 * A component's top level slows or stops the build here, as any component's
 * could.
 *
 * It runs Deno whatever the runner under test is, since only Deno builds a
 * base after the response.
 */
#[CoversClass(PendingBaseSubscriber::class)]
#[CoversClass(HostStatus::class)]
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class BaseRetryTest extends CanvasSsrKernelTestBase {

  use WarmsUpDenoTrait;

  /**
   * A component that's quick to render.
   */
  private const QUICK = 'import { h } from "preact"; export default function Quick() { return h("p", null, "quick"); }';

  /**
   * Holds the runner's base caches and runner caches.
   */
  private static ?string $directory = NULL;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    self::$directory ??= \sys_get_temp_dir() . '/canvas-ssr-base-retry-' . \bin2hex(\random_bytes(6));
    $container->getDefinition(DenoRunner::class)
      ->setArgument('$timeouts', ['medium' => 400, 'long' => 2500])
      ->setArgument('$baseCachesRoot', self::$directory . '/base-caches')
      ->setArgument('$runnerCachesRoot', self::$directory . '/runner-caches');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // No runner module is enabled, so Deno is selected if it's available.
    $selector = $this->container->get(RunnerSelector::class);
    \assert($selector instanceof RunnerSelector);
    if (!$selector->select() instanceof DenoRunner) {
      self::markTestSkipped('The Deno runner is not available here.');
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (self::$directory !== NULL) {
      \exec('rm -rf ' . \escapeshellarg(self::$directory));
    }
    parent::tearDown();
  }

  /**
   * Tests a slow base falls back, and its retry ends the fallbacks.
   */
  public function testSlowBaseIsBuiltAfterTheResponse(): void {
    $this->createComponent('Slow', 'import { h } from "preact"; const end = Date.now() + 1500; while (Date.now() < end); export default function Slow() { return h("p", null, "slow"); }', machine_name: 'slow');
    $quick = $this->createComponent('Quick', self::QUICK, machine_name: 'quick');

    $result = $this->ssrRenderer()->render($quick, [], [], 'preact');
    self::assertNull($result->html);
    self::assertContains(Renderer::BASE_PENDING_TAG, $result->getCacheTags());
    self::assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
    self::assertTrue($this->denoRunner()->hasPendingBase());

    // Stands for a page that cached the fallback.
    $this->container->get('cache.default')->set('page', 'csr', Cache::PERMANENT, [Renderer::BASE_PENDING_TAG]);
    $this->container->get(PendingBaseSubscriber::class)->onTerminate();

    self::assertFalse($this->denoRunner()->hasPendingBase());
    self::assertFalse($this->container->get('cache.default')->get('page'), 'The fallback was not invalidated.');
    self::assertArrayNotHasKey(HostStatus::host(), $this->container->get(HostStatus::class)->reports());

    // The renderer remembered the base was pending, until the request
    // ended.
    $renderer = $this->ssrRenderer();
    self::assertNull($renderer->render($quick, [], [], 'preact')->html);
    $renderer->destruct();
    self::assertStringContainsString('quick', (string) $renderer->render($quick, [], [], 'preact')->html);
  }

  /**
   * Tests a base that never builds is recorded.
   *
   * The status report then says so.
   */
  public function testBaseThatNeverBuildsIsOnTheStatusReport(): void {
    $this->createComponent('Endless', 'import { h } from "preact"; while (true) {} export default function Endless() { return h("p"); }', machine_name: 'endless');
    $quick = $this->createComponent('Quick', self::QUICK, machine_name: 'quick');
    self::assertNull($this->ssrRenderer()->render($quick, [], [], 'preact')->html);

    $this->container->get(PendingBaseSubscriber::class)->onTerminate();

    $record = $this->container->get(HostStatus::class)->reports()[HostStatus::host()]['base'] ?? NULL;
    self::assertIsArray($record);
    self::assertGreaterThan(\time(), $record['due']);
    $requirements = $this->container->get(ModuleHandlerInterface::class)->invoke('canvas_ssr', 'runtime_requirements');
    self::assertSame(RequirementSeverity::Warning, $requirements['canvas_ssr']['severity']);
    self::assertSame('On', (string) $requirements['canvas_ssr']['value']);
    $description = (string) $this->container->get('renderer')->renderInIsolation($requirements['canvas_ssr']['description']);
    self::assertStringContainsString(\sprintf('On %s, the SSR base hasn\'t been built since', HostStatus::host()), $description);
  }

  /**
   * Returns the Deno runner.
   */
  private function denoRunner(): DenoRunner {
    $runner = $this->container->get(DenoRunner::class);
    \assert($runner instanceof DenoRunner);

    return $runner;
  }

}
