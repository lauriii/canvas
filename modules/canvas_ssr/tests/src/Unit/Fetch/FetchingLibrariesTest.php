<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\Fetch;

use Drupal\canvas\GlobalImports;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\canvas_ssr\Fetch\FetchingLibraries;
use Drupal\canvas_ssr\JsModules\AllProvidedModules;
use Drupal\canvas_ssr\JsModules\ProvidedModule;
use Drupal\canvas_ssr\JsModules\SiteImportMap;
use Drupal\canvas_ssr\JsModules\SsrImportMap;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\canvas_ssr\Unit\JsModules\AllProvidedModulesMutableProvider;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which components a library may fetch for.
 */
#[CoversClass(FetchingLibraries::class)]
#[Group('canvas_ssr')]
final class FetchingLibrariesTest extends UnitTestCase {

  /**
   * Tests a component counts when it imports what fetches.
   */
  public function testImporterOfWhatFetchesCounts(): void {
    self::assertTrue(FetchingLibraries::imports(['preact', 'swr'], ''));
    self::assertTrue(FetchingLibraries::imports(['@drupal-api-client/json-api-client'], ''));
    self::assertFalse(FetchingLibraries::imports(['preact', 'clsx', 'drupal-jsonapi-params'], 'useSWR JsonApiClient'));
    self::assertFalse(FetchingLibraries::imports([], ''));
  }

  /**
   * Tests a library that exports more counts only for what fetches.
   */
  public function testLibraryThatExportsMoreCountsOnlyForWhatFetches(): void {
    self::assertFalse(FetchingLibraries::imports(['drupal-canvas'], 'import { cn } from "drupal-canvas";'));
    self::assertTrue(FetchingLibraries::imports(['drupal-canvas'], 'import { JsonApiClient } from "drupal-canvas";'));
    self::assertTrue(FetchingLibraries::imports(['drupal-canvas'], 'import * as canvas from "drupal-canvas"; new canvas.JsonApiClient();'));
    self::assertFalse(FetchingLibraries::imports(['drupal-canvas/react'], 'import { useSiteContext } from "drupal-canvas/react";'));
    self::assertTrue(FetchingLibraries::imports(['drupal-canvas/react'], 'import { useJsonApiClient } from "drupal-canvas/react";'));
  }

  /**
   * Tests a provided module that starts to import a library counts at once.
   *
   * Its source can change under the same URL, as with a push mid-request,
   * which leaves the SSR import map as it was. Invalidating its cache tags
   * then has to drop what was worked out from the old source.
   */
  public function testChangedProvidedModuleCountsOnceInvalidated(): void {
    // Merging cache contexts asserts they're valid, which asks the container.
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);

    $lib = static fn (string $source): ProvidedModule => new ProvidedModule(url: '/local/lib.js', read: static fn () => $source, specifiers: ['@/lib/x']);
    $provider = new AllProvidedModulesMutableProvider([$lib('export const a = 1;')], ['pushed']);
    $provided = new AllProvidedModules([$provider]);
    $global_imports = $this->createMock(GlobalImports::class);
    $global_imports->method('getImportMap')->willReturn([ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => []]);
    $ssr_import_map = new SsrImportMap(
      new SiteImportMap(static fn () => $global_imports, $this->createMock(CacheTagsChecksumInterface::class)),
      $provided,
    );
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(\time());
    $cache = new MemoryBackend($time);
    $libraries = new FetchingLibraries($ssr_import_map, $provided, $cache);
    self::assertFalse($libraries->mayFetchFor(['@/lib/x'], ''));

    $provider->modules = [$lib('import useSWR from "swr"; export const a = useSWR;')];
    foreach ([$provided, $cache, $libraries] as $invalidator) {
      $invalidator->invalidateTags(['pushed']);
    }
    self::assertTrue($libraries->mayFetchFor(['@/lib/x'], ''));
  }

  /**
   * Tests which modules reach a library through the modules they import.
   */
  public function testModulesReachThroughWhatTheyImport(): void {
    $resolve = static fn (string $specifier): ?string => [
      '@/lib/barrel' => '/local/barrel.js',
      'drupal-canvas' => '/canvas/drupal-canvas.js',
      '@drupal-api-client/json-api-client' => '/canvas/jsonapi-client.js',
    ][$specifier] ?? NULL;
    $reaches = FetchingLibraries::reachTable([
      // A cycle, entered before the module that imports the library.
      '/local/a.js' => 'import "./b.js"; export { useStatus } from "./c.js";',
      '/local/b.js' => 'export { useStatus } from "./a.js";',
      '/local/c.js' => 'import useSWR from"swr";export const useStatus=1;',
      // Minified, commented and dynamic imports.
      '/vendor/min.js' => 'export*from"swr"',
      '/vendor/commented.js' => 'const swr = import(/* webpackChunkName: "swr" */ "swr");',
      '/vendor/backtick.js' => 'const swr = import(`swr`);',
      // Only import() takes a template literal. Elsewhere it's text.
      '/vendor/message.js' => 'throw new Error("Import useSWR from `swr` instead.");',
      '/vendor/up/one.js' => 'import "../min.js?v=1";',
      // A library that exports more counts for what fetches.
      '/local/barrel.js' => 'export * from "drupal-canvas";',
      '/local/through-barrel.js' => 'import { JsonApiClient } from "@/lib/barrel";',
      // It may import what fetches itself, by its specifier.
      '/canvas/drupal-canvas.js' => 'import{JsonApiClient as h}from"@drupal-api-client/json-api-client";import{cn as x}from"@/lib/utils";export{h as JsonApiClient,x as cn};',
      '/local/cn.js' => 'import { cn } from "drupal-canvas"; export const x = cn("a");',
      '/local/client.js' => 'import { JsonApiClient as C } from "drupal-canvas";',
      // Not an address, and nothing to do with a library.
      '/local/odd.js' => 'import "./\\\\"; import "preact";',
    ], $resolve);

    self::assertSame([
      '/local/a.js' => TRUE,
      '/local/b.js' => TRUE,
      '/local/c.js' => TRUE,
      '/vendor/min.js' => TRUE,
      '/vendor/commented.js' => TRUE,
      '/vendor/backtick.js' => TRUE,
      '/vendor/message.js' => FALSE,
      '/vendor/up/one.js' => TRUE,
      '/local/barrel.js' => TRUE,
      '/local/through-barrel.js' => TRUE,
      '/canvas/drupal-canvas.js' => FALSE,
      '/local/cn.js' => FALSE,
      '/local/client.js' => TRUE,
      '/local/odd.js' => FALSE,
    ], $reaches);
  }

}
